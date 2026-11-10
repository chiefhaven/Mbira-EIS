<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

class_alias(DB::class, 'DB');
class_alias(Illuminate\Support\Facades\Log::class, 'Log');

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('log', new Psr\Log\NullLogger());
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$app->instance('db', $capsule->getDatabaseManager());
$capsule->schema()->create('eis_configurations', function (Blueprint $t) {
    $t->integer('id'); $t->integer('business_id'); $t->integer('location_id'); $t->integer('eis_setting_id');
});
$capsule->schema()->create('eis_terminal_configurations', function (Blueprint $t) {
    $t->integer('configuration_id'); $t->string('trading_name'); $t->text('address_lines')->nullable();
});
foreach ([[1, 6, 48, 13], [2, 6, 48, 14], [3, 7, 48, 13], [4, 6, 49, 13]] as [$id, $business, $location, $terminal]) {
    DB::table('eis_configurations')->insert(['id' => $id, 'business_id' => $business,
        'location_id' => $location, 'eis_setting_id' => $terminal]);
    DB::table('eis_terminal_configurations')->insert(['configuration_id' => $id,
        'trading_name' => 'Trading '.$id, 'address_lines' => json_encode($id === 1
            ? ['  EIS Street  ', '', 'Suite <2> & Office'] : ['Wrong terminal address'])]);
}
$source = file_get_contents(dirname(__DIR__, 2).'\app\Utils\TransactionUtil.php');
$start = strpos($source, '        $eis_trading_name = null;');
$configurationBlock = substr($source, $start, strpos($source, '        $output = [', $start) - $start);
$start = strpos($source, '        //Address', $start);
$addressBlock = substr($source, $start, strpos($source, "        \$output['website']", $start) - $start);
$checks = 0;
function addressCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
$render = function (bool $fiscal, string $printer = 'browser', int $terminal = 13, bool $showLegacy = true) use ($configurationBlock, $addressBlock): array {
    $is_eis_receipt = $fiscal;
    $transaction = (object) ['id' => 100, 'business_id' => 6, 'location_id' => 48,
        'eisSale' => (object) ['business_id' => 6, 'location_id' => 48, 'eis_setting_id' => $terminal]];
    $il = (object) array_fill_keys(['show_landmark', 'show_city', 'show_state', 'show_zip_code', 'show_country'], $showLegacy ? 1 : 0);
    $location_details = (object) ['landmark' => 'Legacy street', 'city' => 'Legacy city',
        'state' => 'Legacy district', 'zip_code' => '100', 'country' => 'Malawi'];
    $receipt_printer_type = $printer;
    $output = [];
    eval($configurationBlock);
    eval($addressBlock);
    return [$output['address'], $eis_trading_name];
};
[$address, $name] = $render(true);
addressCheck($address === 'EIS Street<br>Suite &lt;2&gt; &amp; Office', 'Browser receipts escape EIS text and preserve address line order');
addressCheck($name === 'Trading 1', 'Address and trading name come from the same original sale terminal');
addressCheck(!str_contains($address, 'Legacy'), 'Fiscal receipt does not append city, district or other legacy address fields');
addressCheck($render(true, 'printer')[0] === "EIS Street\nSuite <2> & Office", 'Thermal receipt uses plain text and newline separators');
addressCheck($render(true, 'browser', 13, false)[0] === $address, 'EIS address does not depend on legacy layout address flags');
addressCheck($render(false)[0] === 'Legacy street, Legacy city, Legacy district, 100, Malawi', 'Non-EIS receipt keeps existing address behavior');
addressCheck($render(false, 'browser', 13, false)[0] === '', 'Non-EIS address visibility flags remain enforced');
addressCheck($render(true, 'browser', 14)[0] === 'Wrong terminal address', 'Recorded terminal ID selects its own configuration');
foreach ([null, 'not json', '{"city":"wrong shape"}', '[123]'] as $invalid) {
    DB::table('eis_terminal_configurations')->where('configuration_id', 1)->update(['address_lines' => $invalid]);
    try {
        $render(true);
        throw new RuntimeException('Invalid EIS addresses must not silently print the legacy city');
    } catch (UnexpectedValueException $e) {
        addressCheck(str_contains($e->getMessage(), 'Refresh'), 'Missing or malformed configuration raises actionable printing error');
    }
}
DB::table('eis_terminal_configurations')->where('configuration_id', 1)->update(['address_lines' => '[]']);
addressCheck($render(true)[0] === '', 'An explicitly empty EIS address does not bring back city or district');
DB::table('eis_terminal_configurations')->where('configuration_id', 1)->delete();
try {
    $render(true);
    throw new RuntimeException('Missing original terminal configuration must not use another terminal');
} catch (UnexpectedValueException $e) {
    addressCheck(true, 'Missing original terminal configuration is reported');
}
echo "PASS: {$checks} EIS receipt address checks\n";
