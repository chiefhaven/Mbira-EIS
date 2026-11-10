<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$root = dirname(__DIR__, 2);
$app->instance('translator', new Illuminate\Translation\Translator(
    new Illuminate\Translation\FileLoader(new Filesystem(), $root.'\lang'), 'en'
));
$checks = 0;
foreach (['paid', 'partial', 'due'] as $status) {
    $sale = new Transaction();
    $sale->payment_status = $status;
    try {
        TransactionUtil::assertSaleReturnAllowed($sale);
        if ($status === 'paid') { throw new RuntimeException('Paid invoice return was allowed'); }
    } catch (InvalidArgumentException $exception) {
        if ($status !== 'paid' || $exception->getMessage() !== 'Sale returns are disabled for fully paid invoices.') {
            throw $exception;
        }
    }
    ++$checks;
}
$source = file_get_contents($root.'\app\Utils\TransactionUtil.php');
$start = strpos($source, '    public function addSellReturn(');
$write = substr($source, $start);
if (strpos($write, 'self::assertSaleReturnAllowed($sell)') > strpos($write, '$eisReturns->beforeReturn(')
    || !str_contains($write, 'self::assertSaleReturnAllowed($sell)')) {
    throw new RuntimeException('Return writes must reject paid sales before fiscal/ledger changes');
}
++ $checks;
echo "PASS: {$checks} paid-invoice return checks\n";
