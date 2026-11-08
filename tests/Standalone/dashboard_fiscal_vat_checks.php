<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('log', new Psr\Log\NullLogger());
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$app->instance('db', $capsule->getDatabaseManager());
$schema = $capsule->schema();
$schema->create('transactions', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('business_id');
    $t->integer('location_id');
    $t->integer('created_by');
    $t->string('type');
    $t->string('status');
    $t->string('payment_status');
    $t->string('transaction_date');
});
$schema->create('eis_sales', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('transaction_id');
    $t->integer('business_id');
    $t->integer('location_id');
    $t->string('status');
    $t->text('request_payload')->nullable();
    $t->text('response_payload')->nullable();
});
$checks = 0;
function fiscalVatCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
function fiscalFixture(int $id, string $date, float $vat, array $changes = [], ?array $remote = null): void
{
    global $capsule;
    $transaction = array_replace(['id' => $id, 'business_id' => 6, 'location_id' => 13, 'created_by' => 7,
        'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid', 'transaction_date' => '2026-11-08 10:00:00'],
        $changes);
    $capsule->table('transactions')->insert($transaction);
    $invoice = ['invoiceHeader' => ['invoiceDateTime' => $date], 'invoiceSummary' => ['totalVAT' => $vat]];
    $capsule->table('eis_sales')->insert(['id' => $id, 'transaction_id' => $id, 'business_id' => $transaction['business_id'],
        'location_id' => $transaction['location_id'], 'status' => 'submitted', 'request_payload' => json_encode($invoice),
        'response_payload' => $remote === null ? null : json_encode($remote)]);
}
// Exact current dashboard reconciliation: three invoices cross the Malawi date boundary.
fiscalFixture(1, '2026-11-08T01:22:57+05:30', 148.94);
fiscalFixture(2, '2026-11-08T01:26:05+05:30', 446.82);
fiscalFixture(3, '2026-11-08T03:00:04+05:30', 3127.66);
foreach ([148.94, 5808.52, 2978.72, 1251.06, 134.04, 1414.90, 2531.92, 0, 1414.90] as $index => $vat) {
    fiscalFixture($index + 4, '2026-11-08T10:00:00+02:00', $vat);
}
$util = new TransactionUtil();
$today = $util->getVatTotals(6, '2026-11-08', '2026-11-08', null, null, 'all');
fiscalVatCheck($today['total_tax'] === 15683.0 && $today['item_tax'] === 15683.0 && $today['header_tax'] === 0,
    'Dashboard fiscal VAT must reproduce the EIS K15,683 total without POS tax or quantity loss');
fiscalVatCheck($util->getVatTotals(6, '2026-11-07', '2026-11-07')['total_tax'] === 3723.42,
    'Offset-bearing early ERP invoices must belong to the previous Malawi fiscal day');
fiscalVatCheck($util->getVatTotals(6, '2026-11-08')['total_tax'] === 15683.0, 'Start-only date filters must work');
fiscalVatCheck($util->getVatTotals(6, null, '2026-11-07')['total_tax'] === 3723.42, 'End-only date filters must work');
fiscalVatCheck($util->getVatTotals(6, null, null, null, null, [])['total_tax'] === 0.0, 'No permitted locations must return no VAT');
fiscalVatCheck($util->getVatTotals(6, null, null, 14, null, [13])['total_tax'] === 0.0, 'Requested locations must not bypass permitted locations');
fiscalVatCheck($util->getVatTotals(6, null, null, null, 99)['total_tax'] === 0.0, 'Creator filter must remain enforced');
fiscalFixture(20, '2026-11-08T10:00:00', 999, ['business_id' => 7]);
fiscalFixture(21, '2026-11-08T10:00:00', 999, ['location_id' => 14]);
fiscalFixture(22, '2026-11-08T10:00:00', 999, ['status' => 'draft']);
fiscalFixture(23, '2026-11-08T10:00:00', 999, ['payment_status' => 'partial']);
fiscalFixture(24, '2026-11-08T10:00:00', 999);
$capsule->table('eis_sales')->where('id', 24)->update(['status' => 'failed']);
fiscalFixture(25, '2026-11-08T10:00:00', 999);
$capsule->table('eis_sales')->where('id', 25)->update(['business_id' => 7]);
fiscalFixture(26, '2026-11-08T10:00:00', 999);
$capsule->table('eis_sales')->where('id', 26)->update(['location_id' => 14]);
fiscalVatCheck($util->getVatTotals(6, '2026-11-08', '2026-11-08', 13, null, [13])['total_tax'] === 15683.0,
    'Only submitted, paid, final sales within the same business/location may contribute');
$remote = ['invoiceHeader' => ['invoiceDateTime' => '2026-11-07T23:00:00'], 'invoiceSummary' => ['totalVAT' => 20]];
fiscalFixture(27, '2026-11-08T10:00:00+02:00', 999, [], ['data' => $remote]);
fiscalFixture(28, '2026-11-08T10:00:00+02:00', 999, [], ['data' => ['data' => $remote]]);
fiscalVatCheck($util->getVatTotals(6, '2026-11-07', '2026-11-07', 13)['total_tax'] === 3763.42,
    'Both saved response envelope shapes must prefer the remote fiscal VAT and date');
fiscalVatCheck($util->getVatTotals(6, '2026-11-08', '2026-11-08', 13)['total_tax'] === 15683.0,
    'Remote dates must take precedence over ERP and request dates');
$capsule->table('eis_sales')->where('id', 27)->update(['request_payload' => null, 'response_payload' => null]);
try {
    $util->getVatTotals(6, '2026-11-08', '2026-11-08', 13);
    throw new RuntimeException('Incomplete submitted invoices must not silently contribute zero');
} catch (UnexpectedValueException $e) {
    fiscalVatCheck(str_contains($e->getMessage(), 'Synchronize'), 'Malformed fiscal data must raise an actionable error');
}
echo 'Dashboard fiscal VAT checks passed: '.$checks." checks\n";
