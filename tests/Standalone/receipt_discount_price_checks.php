<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\TransactionSellLine;
use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

$root = dirname(__DIR__, 2);
$app = new Container();
Container::setInstance($app);
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$capsule->schema()->create('tax_rates', function (Blueprint $table) {
    $table->increments('id');
    $table->softDeletes();
});
$app->instance('translator', new Illuminate\Translation\Translator(
    new Illuminate\Translation\FileLoader(new Filesystem(), $root.'\lang'), 'en'
));
$compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
$util = new TransactionUtil();
$method = new ReflectionMethod(TransactionUtil::class, '_receiptDetailsSellLines');
$checks = 0;
function receiptDiscountCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
function renderDiscountPrice(BladeCompiler $compiler, array $line, object $receipt_details): string
{
    global $root;
    $source = file_get_contents($root.'\resources\views\sale_pos\receipts\partial\product_table.blade.php');
    $receipt_details = clone $receipt_details;
    $receipt_details->lines = $line === [] ? [] : [$line];
    $__env = new Illuminate\View\Factory(new Illuminate\View\Engines\EngineResolver(),
        new Illuminate\View\FileViewFinder(new Filesystem(), [$root.'\resources\views']),
        new Illuminate\Events\Dispatcher());
    $compiled = $compiler->compileString($source);
    ob_start();
    try {
        eval('?>'.$compiled);
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
$business = (object) ['enable_lot_number' => 0, 'enable_product_expiry' => 0, 'common_settings' => [],
    'thousand_separator' => ',', 'decimal_separator' => '.', 'currency_precision' => 2, 'quantity_precision' => 2];
$layout = (object) ['product_custom_fields' => [], 'common_settings' => [], 'show_brand' => 0,
    'show_sku' => 0, 'show_image' => 0, 'show_cat_code' => 0, 'show_sale_description' => 0];
$receipt_details = (object) ['table_product_label' => 'Product', 'table_qty_label' => 'Quantity',
    'table_unit_price_label' => 'UnitPrice', 'receipt_item_discount_label' => 'Discount', 'table_subtotal_label' => 'Subtotal'];
$printedLines = [];
foreach (['fixed', 'percentage', null] as $type) {
    foreach ([1, 3] as $multiplier) {
        $line = new TransactionSellLine();
        $line->setRawAttributes(['unit_price_before_discount' => 100 * $multiplier,
            'unit_price' => ($type ? 90 : 100) * $multiplier,
            'unit_price_inc_tax' => ($type ? 107.5 : 117.5) * $multiplier, 'item_tax' => 17.5 * $multiplier,
            'line_discount_type' => $type, 'line_discount_amount' => $type === 'fixed' ? 10 * $multiplier : ($type ? 10 : 0),
            'quantity' => 2, 'orig_quantity' => 2 * $multiplier, 'multiplier' => $multiplier, 'tax_id' => null]);
        $line->setRelation('product', (object) ['name' => 'Test product', 'unit' => (object) ['short_name' => 'pc'],
            'brand' => null, 'category' => null, 'tax' => null]);
        $line->setRelation('variations', (object) ['id' => 1, 'name' => 'DUMMY', 'product_variation' => (object) ['name' => 'DUMMY']]);
        $line->setRelation('sub_unit', null)->setRelation('modifiers', []);
        $before = $line->getAttributes();
        $details = $method->invoke($util, [$line], $layout, $business);
        $receiptLine = $details['lines'][0];
        receiptDiscountCheck($line->getAttributes() === $before, 'Receipt generation must not mutate prices');
        receiptDiscountCheck($receiptLine['unit_price_before_discount_inc_tax'] === number_format(117.5 * $multiplier, 2),
            'Tax-inclusive pre-discount unit price must handle fixed, percentage and sub-unit discounts');
        receiptDiscountCheck($receiptLine['unit_price_inc_tax'] === number_format(($type ? 107.5 : 117.5) * $multiplier, 2)
            && $receiptLine['line_total_uf'] === $line->unit_price_inc_tax * 2,
            'Existing discounted price and totals must remain unchanged');
        $html = renderDiscountPrice($compiler, $receiptLine, $receipt_details);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $headers = array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//thead/tr/th')));
        receiptDiscountCheck($headers === ['#', 'Product', 'Quantity', 'UnitPrice', 'Discount', 'Subtotal'],
            'Headers must match the requested six-column order');
        $cells = array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//tbody/tr[1]/td')));
        receiptDiscountCheck(count($cells) === 6 && $cells[0] === '1' && $cells[3] === $receiptLine['unit_price_before_discount_inc_tax']
            && str_starts_with($cells[4], $receiptLine['total_line_discount']) && $cells[5] === $receiptLine['line_total'],
            'Rows must display pre-discount price, total discount and saved subtotal in the correct columns');
        $printedLines[] = $receiptLine;
    }
}
$escapedLine = $printedLines[0];
$escapedLine['unit_price_before_discount_inc_tax'] = '<script>bad</script>';
$escapedReceipt = clone $receipt_details;
$escapedReceipt->table_unit_price_label = '<b>Price</b>';
$html = renderDiscountPrice($compiler, $escapedLine, $escapedReceipt);
receiptDiscountCheck(!str_contains($html, '<script>') && str_contains($html, '&lt;b&gt;Price'), 'Price and label must be escaped');
receiptDiscountCheck(str_contains(renderDiscountPrice($compiler, [], $receipt_details), 'colspan="6"'), 'Empty receipts must span all six columns');
$legacyLine = $printedLines[0];
unset($legacyLine['unit_price_before_discount_inc_tax'], $legacyLine['total_line_discount']);
$html = renderDiscountPrice($compiler, $legacyLine, $receipt_details);
receiptDiscountCheck(str_contains($html, $legacyLine['unit_price_inc_tax']) && str_contains($html, '0.00'),
    'Return and legacy line data must retain the available price and zero discount');
$utilSource = file_get_contents($root.'\app\Utils\TransactionUtil.php');
receiptDiscountCheck(strpos($utilSource, "'receipt_item_discount_label' =>") < strpos($utilSource, '//Display name'),
    'The localized discount column label must be available for all receipt types');
$hidden = clone $receipt_details;
$hidden->hide_price = true;
$html = renderDiscountPrice($compiler, $printedLines[0], $hidden);
receiptDiscountCheck(!str_contains($html, 'UnitPrice') && !str_contains($html, 'Discount') && !str_contains($html, 'Subtotal')
    && !str_contains($html, $printedLines[0]['unit_price_before_discount_inc_tax']), 'Hidden-price receipts must not expose any monetary columns');
$withModifier = $printedLines[0];
$withModifier['modifiers'] = [['name' => 'Extra', 'quantity' => '1', 'units' => 'pc', 'unit_price_inc_tax' => '5.00', 'line_total' => '5.00']];
$dom = new DOMDocument();
$dom->loadHTML(renderDiscountPrice($compiler, $withModifier, $receipt_details));
$xpath = new DOMXPath($dom);
receiptDiscountCheck($xpath->query('//tbody/tr[2]/td')->length === 6
    && $xpath->query('//tbody/tr[2]/td[5]')->item(0)->textContent === '0.00', 'Modifiers must align with six columns and show zero discount');
foreach ([
    ['savanna', 'savanna', 'savanna', 'savanna'],
    [' savanna ', 'savanna', ' savanna', 'savanna'],
    ['Shirt', 'Size', 'Large', 'Shirt Size Large'],
    ['Shirt', 'Size', 'Size', 'Shirt Size'],
    ['Product', '', '0', 'Product 0'],
] as [$name, $group, $variation, $expected]) {
    $namedLine = $printedLines[0];
    $namedLine['name'] = $name;
    $namedLine['product_variation'] = $group;
    $namedLine['variation'] = $variation;
    $namedLine['modifiers'] = [['name' => $name, 'variation' => $name, 'quantity' => '1', 'units' => 'pc',
        'unit_price_inc_tax' => '5.00', 'line_total' => '5.00']];
    $dom = new DOMDocument();
    $dom->loadHTML(renderDiscountPrice($compiler, $namedLine, $receipt_details));
    $xpath = new DOMXPath($dom);
    receiptDiscountCheck(trim($xpath->query('//tbody/tr[1]/td[2]')->item(0)->textContent) === $expected,
        'Browser product names must deduplicate identical fields and preserve distinct variations');
    receiptDiscountCheck(trim($xpath->query('//tbody/tr[2]/td[2]')->item(0)->textContent) === trim($name),
        'Browser modifier names must not repeat identical variations');
}
foreach (['classic', 'columnize-taxes', 'detailed', 'elegant', 'elegant_modified', 'english-arabic', 'slim', 'slim2'] as $name) {
    $source = file_get_contents($root.'\resources\views\sale_pos\receipts\\'.$name.'.blade.php');
    receiptDiscountCheck(substr_count($source, "@include('sale_pos.receipts.partial.product_table')") === 1,
        $name.' must use the shared six-column product table');
    receiptDiscountCheck($compiler->compileString($source) !== '', $name.' must compile');
}
$source = file_get_contents($root.'\pos_print_server\lib\Escpos.php');
require $root.'\pos_print_server\lib\Escpos.php';
$start = strpos($source, '        /* Products */');
$end = strpos($source, '        /* Totals */', $start);
$block = substr($source, $start, $end - $start);
$printer = new Twf\Pps\Escpos();
$printer->printer = new class {
    public array $rows = [];
    public function text($text): void { $this->rows[] = $text; }
    public function feed($lines = 1): void {}
    public function setEmphasis($enabled): void {}
};
$print = Closure::bind(function ($data) use ($block) { eval($block); }, $printer, Twf\Pps\Escpos::class);
foreach ([32, 42, 48] as $width) {
    $printer->char_per_line = $width;
    $printer->printer->rows = [];
    $data = clone $receipt_details;
    $data->lines = $printedLines;
    $print($data);
    $columnMethod = new ReflectionMethod(Twf\Pps\Escpos::class, 'productColumns');
    $expectedHeader = rtrim($columnMethod->invoke($printer, ['#', 'Product', 'Quantity', 'UnitPrice', 'Discount', 'Subtotal']));
    receiptDiscountCheck($printer->printer->rows[1] === $expectedHeader, 'Thermal headers must use six columns at '.$width.' characters');
    foreach ($printedLines as $index => $line) {
        $expectedRow = rtrim($columnMethod->invoke($printer, [$index + 1, 'Test product', $line['quantity'],
            $line['unit_price_before_discount_inc_tax'], $line['total_line_discount'], $line['line_total']]));
        receiptDiscountCheck($printer->printer->rows[$index + 3] === $expectedRow, 'Thermal values and row numbers must match browser prices');
    }
    foreach ($printer->printer->rows as $text) {
        receiptDiscountCheck(max(array_map('strlen', explode("\n", $text))) <= $width, 'Thermal text must fit configured paper width');
    }
}
$printer->printer->rows = [];
$data = clone $hidden;
$data->lines = [$printedLines[0]];
$print($data);
receiptDiscountCheck(!str_contains(implode('', $printer->printer->rows), 'UnitPrice')
    && !str_contains(implode('', $printer->printer->rows), $printedLines[0]['unit_price_before_discount_inc_tax']),
    'Thermal hidden-price receipts must omit monetary columns');
$printer->printer->rows = [];
$data = clone $receipt_details;
$data->lines = [$withModifier];
$print($data);
receiptDiscountCheck(count($printer->printer->rows) === 6 && str_contains($printer->printer->rows[4], 'Extra'),
    'Thermal modifiers must print after their parent product');
$nameMethod = new ReflectionMethod(Twf\Pps\Escpos::class, 'productName');
receiptDiscountCheck($nameMethod->invoke($printer, ['name' => 'savanna', 'product_variation' => 'savanna', 'variation' => 'savanna']) === 'savanna',
    'Thermal product names must not repeat identical fields');
receiptDiscountCheck($nameMethod->invoke($printer, ['name' => 'Shirt', 'product_variation' => 'Size', 'variation' => 'Large']) === 'Shirt Size Large',
    'Thermal product names must preserve distinct variations');
receiptDiscountCheck($nameMethod->invoke($printer, ['name' => 'Extra', 'variation' => 'Extra']) === 'Extra',
    'Thermal modifier names must not repeat identical variations');
echo 'Receipt discount price checks passed: '.$checks." checks\n";
