<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('log', new Psr\Log\NullLogger());
$root = dirname(__DIR__, 2);
$app->instance('translator', new Illuminate\Translation\Translator(
    new Illuminate\Translation\FileLoader(new Filesystem(), $root.'\lang'), 'en'
));
$compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
$checks = 0;
function vatDisplayCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function renderVatPartial(string $name, object $receipt_details): string
{
    global $compiler, $root;
    $compiled = $compiler->compileString(file_get_contents(
        $root.'\resources\views\sale_pos\receipts\partial\\'.$name.'.blade.php'
    ));
    ob_start();
    try { eval('?>'.$compiled); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
$method = new ReflectionMethod(TransactionUtil::class, 'receiptFiscalTaxDetails');
$method->setAccessible(true);
$util = new TransactionUtil();
$config = ['globalConfiguration' => ['taxrates' => [
    ['id' => 'B', 'name' => 'Zero Rated', 'rate' => 0],
    ['id' => 'A', 'name' => 'Standard Rated', 'rate' => 17.5],
    ['id' => 'E', 'name' => 'Exempt', 'rate' => 0],
    ['id' => 'C', 'name' => 'Reduced Rate', 'rate' => 5],
]]];
$invoice = ['invoiceLineItems' => [['total' => 500], ['total' => 8000]],
    'invoiceSummary' => ['invoiceTotal' => 8500, 'totalVAT' => 1265.96,
    'taxBreakDown' => [['rateId' => 'A', 'taxableAmount' => 7234.04, 'taxAmount' => 1265.96]]]];
$details = $method->invoke($util, $invoice, $config);
vatDisplayCheck($details === ['vat' => 1265.96, 'subtotal' => 8500.0, 'taxable_amount' => 7234.04, 'standard_rate' => '17.5'],
    'Receipt subtotal sums gross lines and taxable amount subtracts VAT');
foreach ([
    [100, 0, [['rateId' => 'B', 'taxableAmount' => 100]], [100.0]],
    [100, 0, [['rateId' => 'E', 'taxableAmount' => 100]], [100.0]],
    [0, 0, [], []],
    [357.5, 17.5, [
        ['rateId' => 'A', 'taxableAmount' => 100],
        ['rateId' => 'B', 'taxableAmount' => 90],
        ['rateId' => 'E', 'taxableAmount' => 150],
    ], [117.5, 90, 150]],
    [428.0, 28, [
        ['rateId' => 'A', 'taxableAmount' => 120],
        ['rateId' => 'C', 'taxableAmount' => 140],
        ['rateId' => 'B', 'taxableAmount' => 90],
        ['rateId' => 'E', 'taxableAmount' => 50],
    ], [141, 147, 90, 50]],
    [0.01, 0, [['rateId' => 'A', 'taxableAmount' => 0.01]], [0.01]],
] as [$gross, $vat, $breakdown, $lineTotals]) {
    $result = $method->invoke($util, ['invoiceLineItems' => array_map(fn ($total) => ['total' => $total], $lineTotals),
        'invoiceSummary' => [
        'invoiceTotal' => $gross, 'totalVAT' => $vat, 'taxBreakDown' => $breakdown,
    ]], $config);
    $expectedSubtotal = round(array_sum($lineTotals), 2);
    $expectedTaxable = round($expectedSubtotal - $vat, 2);
    vatDisplayCheck($result['subtotal'] === $expectedSubtotal && $result['taxable_amount'] === $expectedTaxable,
        'Subtotal sums gross line subtotals and Taxable Amount subtracts VAT');
}
foreach ([[], ['invoiceTotal' => 10], ['invoiceTotal' => 10, 'totalVAT' => 11],
    ['invoiceTotal' => INF, 'totalVAT' => 1], ['invoiceTotal' => 100, 'totalVAT' => -1]] as $summary) {
    try {
        $method->invoke($util, ['invoiceSummary' => $summary], $config);
        throw new RuntimeException('Invalid fiscal tax totals must not print');
    } catch (UnexpectedValueException $e) {
        vatDisplayCheck(str_contains($e->getMessage(), 'Synchronize'), 'Invalid fiscal tax totals require synchronization');
    }
}
foreach ([null, [], 'invalid', [['total' => null]], [['total' => -1]], [['total' => INF]]] as $lineItems) {
    $invalid = $invoice;
    $invalid['invoiceLineItems'] = $lineItems;
    try {
        $method->invoke($util, $invalid, $config);
        throw new RuntimeException('Invalid line subtotals must not print');
    } catch (UnexpectedValueException $e) {
        vatDisplayCheck(str_contains($e->getMessage(), 'Synchronize'), 'Invalid line subtotals require synchronization');
    }
}
$unknown = $invoice;
$unknown['invoiceSummary']['taxBreakDown'][0]['rateId'] = 'UNKNOWN';
$unknown['invoiceSummary']['taxBreakDown'][0]['taxableAmount'] = 7234.04;
$unknownDetails = $method->invoke($util, $unknown, $config);
vatDisplayCheck($unknownDetails['taxable_amount'] === 7234.04,
    'Taxable Amount subtracts VAT without filtering tax rates');
$invoice0043 = ['invoiceLineItems' => [['total' => 500], ['total' => 45000]],
    'invoiceSummary' => ['invoiceTotal' => 45500, 'totalVAT' => 74.47]];
$details0043 = $method->invoke($util, $invoice0043, []);
vatDisplayCheck($details0043['subtotal'] === 45500.0 && $details0043['taxable_amount'] === 45425.53,
    'Invoice 0043 subtotal is 500 plus 45,000 and taxable amount subtracts K74.47 VAT');
$receipt = (object) ['eis_invoice_number' => 'TEST', 'vat_registered' => true,
    'vat_registered_label' => 'VAT Registered', 'taxable_amount_label' => 'Taxable Amount:',
    'taxable_amount' => '7,234.04', 'vat_total_label' => 'VAT (17.5%):', 'vat_amount' => '1,265.96'];
$html = renderVatPartial('vat_summary', $receipt);
vatDisplayCheck(substr_count($html, 'class="width-50 text-right"') === 4
    && str_contains($html, 'class="flex-box receipt-taxable-amount"')
    && str_contains($html, 'class="flex-box receipt-total-vat"'),
    'VAT and taxable amount use the same equal-width right-aligned columns as receipt totals');
vatDisplayCheck(str_contains($html, 'Taxable Amount') && str_contains($html, '7,234.04')
    && str_contains($html, 'VAT (17.5%):') && str_contains($html, '1,265.96'),
    'Receipt renders taxable amount and Total VAT followed by the standard rate');
vatDisplayCheck(str_contains(renderVatPartial('vat_registered', $receipt), '***VAT Registered***'),
    'VAT registered fiscal receipt wraps the heading marker in literal asterisks');
$receipt->vat_registered = false;
vatDisplayCheck(trim(renderVatPartial('vat_registered', $receipt)) === '',
    'Non-VAT registered terminal does not claim registration');
unset($receipt->vat_registered);
vatDisplayCheck(trim(renderVatPartial('vat_registered', $receipt)) === '',
    'Missing registration flag prints nothing');
vatDisplayCheck(!str_contains(renderVatPartial('eis_invoice_number', $receipt), 'VAT Registered'),
    'EIS number partial does not duplicate the registration heading');
$receipt->hide_price = true;
vatDisplayCheck(trim(renderVatPartial('vat_summary', $receipt)) === '', 'Hidden-price receipts do not expose tax totals');
$receipt->hide_price = false;
$receipt->vat_total_label = '<script>unsafe</script>';
vatDisplayCheck(!str_contains(renderVatPartial('vat_summary', $receipt), '<script>'), 'Tax labels are escaped');
$receipt->table_product_label = 'Product';
$receipt->table_qty_label = 'Quantity';
$receipt->table_unit_price_label = 'UnitPrice';
$receipt->receipt_item_discount_label = 'Discount';
$receipt->table_subtotal_label = 'Subtotal';
$receipt->lines = [['name' => 'Product', 'quantity' => '1', 'units' => 'pc',
    'unit_price_before_discount' => '7,234.04', 'unit_price_inc_tax' => '8,500.00',
    'unit_price_before_discount_inc_tax' => '8,500.00',
    'total_line_discount' => '0.00', 'line_total' => '8,500.00', 'unit_price_quantity_subtotal' => '8,500.00',
    'modifiers' => [['name' => 'Extra', 'quantity' => '1', 'units' => 'pc',
        'unit_price_exc_tax' => '100.00', 'unit_price_inc_tax' => '117.50', 'line_total' => '117.50',
        'unit_price_quantity_subtotal' => '117.50']]]];
$receipt_details = $receipt;
$__env = new Illuminate\View\Factory(new Illuminate\View\Engines\EngineResolver(),
    new Illuminate\View\FileViewFinder(new Filesystem(), [$root.'\resources\views']),
    new Illuminate\Events\Dispatcher());
$compiled = $compiler->compileString(file_get_contents(
    $root.'\resources\views\sale_pos\receipts\partial\product_table.blade.php'
));
ob_start();
try { eval('?>'.$compiled); $html = ob_get_contents(); }
finally { ob_end_clean(); }
$dom = new DOMDocument();
$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
vatDisplayCheck(trim($xpath->query('//tbody/tr[1]/td[4]')->item(0)->textContent) === '8,500.00',
    'Product unit price includes VAT');
vatDisplayCheck(trim($xpath->query('//tbody/tr[1]/td[6]')->item(0)->textContent) === '8,500.00',
    'Product subtotal uses VAT-inclusive unit price times quantity');
vatDisplayCheck(trim($xpath->query('//tbody/tr[2]/td[6]')->item(0)->textContent) === '117.50',
    'Modifier subtotal uses VAT-inclusive unit price times quantity');
vatDisplayCheck(trim($xpath->query('//tbody/tr[2]/td[4]')->item(0)->textContent) === '117.50',
    'Modifier unit price also includes VAT');
foreach (['classic', 'columnize-taxes', 'detailed', 'elegant', 'elegant_modified', 'slim', 'slim2', 'english-arabic'] as $name) {
    $source = file_get_contents($root.'\resources\views\sale_pos\receipts\\'.$name.'.blade.php');
    vatDisplayCheck($compiler->compileString($source) !== '', $name.' compiles');
    vatDisplayCheck(!str_contains($source, '$receipt_details->subtotal_label') && !str_contains($source, 'Subtotal:'),
        $name.' omits the invoice-level Subtotal row');
    vatDisplayCheck(str_contains($source, "partial.vat_summary") || str_contains($source, 'taxable_amount_label'),
        $name.' includes the fiscal tax summary');
    $taxPosition = strpos($source, $name === 'english-arabic' ? 'taxable_amount_label' : 'partial.vat_summary');
    vatDisplayCheck($taxPosition < strpos($source, '$receipt_details->total_label'),
        $name.' shows taxable amount and VAT before the total');
}
$productTable = file_get_contents($root.'\resources\views\sale_pos\receipts\partial\product_table.blade.php');
vatDisplayCheck(str_contains($productTable, 'table_subtotal_label'),
    'Product table retains per-line Subtotal column');
echo "PASS: {$checks} receipt VAT display checks\n";
