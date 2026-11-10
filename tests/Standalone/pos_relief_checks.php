<?php

// SQLite memory only; no application boot, .env, live sales, or EIS calls.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\SellPosController;
use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Services\Sales\SaleTransformer;

$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setEventDispatcher(new Illuminate\Events\Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
Container::setInstance($container);
$container->instance('db', $capsule->getDatabaseManager());
$schema = $capsule->getConnection()->getSchemaBuilder();
$container->instance('db.schema', $schema);
$container->instance('translator', new Translator(new ArrayLoader(), 'en'));
Facade::setFacadeApplication($container);
Facade::clearResolvedInstances();
if (! class_exists('DB')) {
    class_alias(Illuminate\Support\Facades\DB::class, 'DB');
}

// Build synthetic columns for the actual utility's sale attribute projection.
$source = file(dirname(__DIR__, 2).'/app/Utils/TransactionUtil.php');
$method = new ReflectionMethod(TransactionUtil::class, 'createSellTransaction');
preg_match_all('/^\s*\x27([a-z_0-9]+)\x27\s*=>/m', implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1)), $matches);
$schema->create('transactions', function (Blueprint $table) use ($matches) {
    $table->increments('id');
    foreach (array_unique(array_diff($matches[1], ['is_relief'])) as $column) {
        $table->text($column)->nullable();
    }
    $table->timestamps();
});
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_14_000002_add_is_relief_to_transactions_table.php';
$migration->up();
$migration->up();
/** @var TransactionUtil&\Mockery\MockInterface $util */
$util = Mockery::mock(TransactionUtil::class)->makePartial();
$util->shouldReceive('calculateRewardPoints')->andReturn(0);
$input = [
    'type' => 'sell', 'status' => 'draft', 'location_id' => 2, 'contact_id' => 3,
    'invoice_no' => 'RELIEF-FIXTURE', 'final_total' => 100, 'transaction_date' => '2026-10-14 10:00:00',
    'customer_group_id' => null, 'tax_rate_id' => null, 'discount_type' => 'fixed',
    'discount_amount' => 0, 'commission_agent' => null, 'pay_term_number' => 1, 'pay_term_type' => 'days',
];
$totals = ['total_before_tax' => 100, 'tax' => 0];
$checks = 0;
function verifyRelief($condition, string $message): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
$transformer = (new ReflectionClass(SaleTransformer::class))->newInstanceWithoutConstructor();
$headerMethod = new ReflectionMethod(SaleTransformer::class, 'buildInvoiceHeader');
$headerMethod->setAccessible(true);
foreach ([[], ['is_relief' => '0'], ['is_relief' => '1']] as $flag) {
    $transaction = $util->createSellTransaction(7, $input + $flag, $totals, 1, false)->fresh();
    $expected = ($flag['is_relief'] ?? '0') === '1';
    verifyRelief($transaction->is_relief === $expected, 'Persist create boolean');
    $header = $headerMethod->invoke($transformer, $transaction, new EisSetting(), 'TEST-1', '00', []);
    verifyRelief($header['isReliefSupply'] === $expected, 'EIS header uses stored relief boolean');
    foreach (['1', null, '0'] as $value) {
        $change = $value === null ? [] : ['is_relief' => $value];
        $transaction = $util->updateSellTransaction($transaction, 7, $input + $change, $totals, 1, false)->fresh();
        verifyRelief($transaction->is_relief === ($value !== '0'), 'Update, preserve omitted, and uncheck');
    }
}

foreach ([[], ['is_export' => '0'], ['is_export' => '1']] as $flag) {
    $transaction = $util->createSellTransaction(7, $input + $flag, $totals, 1, false)->fresh();
    $expected = ($flag['is_export'] ?? '0') === '1';
    $header = $headerMethod->invoke($transformer, $transaction, new EisSetting(), 'TEST-1', '00', []);
    verifyRelief((bool) $transaction->is_export === $expected && $header['isExport'] === $expected,
        'Persist export flag and send an exact EIS boolean');
    $transaction->export_custom_fields_info = ['export_custom_field_1' => 'Historical value'];
    $transaction->save();
    foreach (['1', null, '0'] as $value) {
        $change = $value === null ? [] : ['is_export' => $value];
        $transaction = $util->updateSellTransaction($transaction, 7, $input + $change, $totals, 1, false)->fresh();
        $header = $headerMethod->invoke($transformer, $transaction, new EisSetting(), 'TEST-1', '00', []);
        verifyRelief($header['isExport'] === ($value !== '0'), 'Export edit, omitted flag preservation, and uncheck');
        verifyRelief($transaction->export_custom_fields_info === ['export_custom_field_1' => 'Historical value'],
            'Removing legacy fields from forms does not erase historical data');
    }
}

$factory = new Factory($container->make('translator'), $container);
Request::macro('validate', function ($rules) use ($factory) {
    return $factory->make($this->all(), $rules)->validate();
});
$user = Mockery::mock();
$user->shouldReceive('can')->andReturn(true);
$auth = Mockery::mock();
$auth->shouldReceive('user')->andReturn($user);
$container->instance(Illuminate\Contracts\Auth\Factory::class, $auth);
$controller = (new ReflectionClass(SellPosController::class))->newInstanceWithoutConstructor();
foreach (['store', 'update'] as $method) {
    foreach (['is_relief', 'is_export'] as $field) {
        foreach (['invalid', ['1'], null, '2'] as $value) {
            try {
                $request = Request::create('/pos', 'POST', [$field => $value]);
                $method === 'store' ? $controller->store($request) : $controller->update($request, 1);
                throw new RuntimeException('Invalid '.$field.' input accepted');
            } catch (ValidationException $e) {
                verifyRelief(isset($e->errors()[$field]), 'Controller rejects invalid '.$field.' boolean');
            }
        }
    }
}

$blade = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
$checkboxSource = file_get_contents(dirname(__DIR__, 2).'/resources/views/sale_pos/partials/relief_checkbox.blade.php');
foreach ([[false, false], [true, false], [true, true]] as [$selected, $only_payment]) {
    $transaction = new Transaction(['is_relief' => $selected]);
    ob_start();
    eval('?>'.$blade->compileString($checkboxSource));
    $html = ob_get_clean();
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $fields = $dom->getElementsByTagName('input');
    $checkbox = $fields->item($fields->length - 1);
    verifyRelief($checkbox->getAttribute('id') === 'isRelief', 'POS checkbox ID');
    verifyRelief($checkbox->hasAttribute('checked') === $selected, 'Edit loads stored checkbox state');
    verifyRelief($checkbox->hasAttribute('disabled') === $only_payment, 'Payment-only checkbox read-only');
    verifyRelief($fields->length === ($only_payment ? 1 : 2), 'Unchecked hidden value omitted on payment-only screen');
}
parse_str('is_relief=0&is_relief=1', $posted);
verifyRelief($posted['is_relief'] === '1', 'Checked form overrides hidden zero');
foreach (['create', 'edit'] as $view) {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/sale_pos/'.$view.'.blade.php');
    token_get_all($blade->compileString($source), TOKEN_PARSE);
    verifyRelief(str_contains($source, "'sale_pos.partials.pos_form_totals'"), 'Both POS screens include shared totals');
}
$source = file_get_contents(dirname(__DIR__, 2).'/resources/views/sale_pos/partials/pos_form_totals.blade.php');
token_get_all($blade->compileString($source), TOKEN_PARSE);
verifyRelief(str_contains($source, "'sale_pos.partials.relief_checkbox'"), 'Totals include relief checkbox');
foreach (['create', 'edit'] as $view) {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/sell/'.$view.'.blade.php');
    token_get_all($blade->compileString($source), TOKEN_PARSE);
    foreach (['relief_checkbox', 'vat5_fields'] as $partial) {
        $position = strpos($source, "@include('sale_pos.partials.{$partial}')");
        verifyRelief($position !== false && $position > strpos($source, 'Form::open(')
            && $position < strpos($source, 'Form::close()'), 'Classic '.$view.' includes '.$partial.' inside the submitted form');
    }
    verifyRelief(str_contains($source, "asset('js/pos.js"), 'Classic '.$view.' loads the shared relief toggle handler');
    verifyRelief(! str_contains($source, "['is_enabled_export']"), 'Classic '.$view.' export controls do not depend on the business export setting');
    $exportPosition = strpos($source, 'name="is_export"');
    verifyRelief($exportPosition !== false && $exportPosition > strpos($source, 'Form::open(')
        && $exportPosition < strpos($source, 'Form::close()'), 'Classic '.$view.' submits the export checkbox');
    verifyRelief(! str_contains($source, 'export_custom_fields_info'), 'Classic '.$view.' omits non-EIS export fields');
    $exportCheckbox = [];
    preg_match_all('/<input[^>]+name="is_export"[^>]*>/', $source, $exportCheckbox);
    verifyRelief(count($exportCheckbox[0]) === 2
        && str_contains($exportCheckbox[0][0], 'value="0"')
        && str_contains($exportCheckbox[0][1], 'value="1"'), 'Classic '.$view.' sends explicit unchecked and checked export booleans');
}
$migration->down();
verifyRelief(! $schema->hasColumn('transactions', 'is_relief'), 'Migration rollback');
Mockery::close();
echo "PASS: {$checks} POS relief checks (SQLite memory only).\n";