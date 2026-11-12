<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

$root = dirname(__DIR__, 2);
$productUtil = file_get_contents($root.'/app/Utils/ProductUtil.php');
$productRow = file_get_contents($root.'/resources/views/sale_pos/product_row.blade.php');
$checks = 0;

function salesTaxCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

salesTaxCheck(
    str_contains($productUtil, '$product->sell_price_inc_tax - $product->default_sell_price'),
    'Initial per-unit tax must come from the stored product prices'
);
salesTaxCheck(
    str_contains($productRow, 'hidden_base_unit_item_tax')
        && str_contains($productRow, '$item_tax = $base_item_tax * $multiplier'),
    'Product rows must retain the base-unit tax amount'
);

$start = strpos($productRow, '$tax_id = $product->tax_id;');
$priceBlock = substr($productRow, $start, strpos($productRow, '$discount_type =', $start) - $start);
$prepare = static function (array $prices, ?object $so_line = null, string $hide_tax = '') use ($priceBlock): array {
    $product = (object) ($prices + ['tax_id' => 1, 'item_tax' => 0]);
    $action = '';
    $multiplier = 1;
    eval($priceBlock);
    return [$pos_unit_price, $unit_price_before_discount_inc_tax, $unit_price_inc_tax];
};
foreach ([
    [['default_sell_price' => 100, 'sell_price_inc_tax' => 103.33], [100, 103.33, 103.33]],
    [['default_sell_price' => 100, 'sell_price_inc_tax' => 100], [100, 100, 100]],
    [['default_sell_price' => 0, 'sell_price_inc_tax' => 0], [0, 0, 0]],
    [['default_sell_price' => 90, 'sell_price_inc_tax' => 105.75, 'unit_price_before_discount' => 100], [100, 117.5, 105.75]],
    [['default_sell_price' => 90, 'sell_price_inc_tax' => 90, 'unit_price_before_discount' => 100], [100, 100, 90]],
    [['default_sell_price' => 0, 'sell_price_inc_tax' => 0, 'unit_price_before_discount' => 100,
        'catalog_price_exc_tax' => 100, 'catalog_price_inc_tax' => 117.5], [100, 117.5, 0]],
    [['default_sell_price' => 0, 'sell_price_inc_tax' => 0, 'unit_price_before_discount' => 100,
        'catalog_price_exc_tax' => 100, 'catalog_price_inc_tax' => 100], [100, 100, 0]],
] as [$prices, $expected]) {
    $actual = $prepare($prices);
    foreach ($expected as $index => $price) {
        salesTaxCheck(abs($actual[$index] - $price) < 0.000001,
            'Server row preserves database prices and restores saved pre-discount prices without a tax dropdown');
    }
}
salesTaxCheck($prepare(['default_sell_price' => 100, 'sell_price_inc_tax' => 117.5], null, 'hide')[1] === 100,
    'Disabling inline tax uses the stored exclusive price without VAT');
$order = (object) ['tax_id' => 1, 'item_tax' => 15.75, 'unit_price' => 90,
    'unit_price_before_discount' => 100, 'unit_price_inc_tax' => 105.75];
salesTaxCheck(abs($prepare(['default_sell_price' => 200, 'sell_price_inc_tax' => 235], $order)[1] - 117.5) < 0.000001,
    'Sales-order lines keep their stored price pair instead of current catalog prices');

$app = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($app);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$session = new Illuminate\Session\Store('sales-tax', new Illuminate\Session\ArraySessionHandler(120));
$app->instance('session', $session);
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$capsule->schema()->create('business', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->increments('id'); $table->boolean('enable_inline_tax');
});
$capsule->schema()->create('tax_rates', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->increments('id'); $table->decimal('amount', 8, 3); $table->softDeletes();
});
$capsule->table('business')->insert([['id' => 1, 'enable_inline_tax' => 0], ['id' => 2, 'enable_inline_tax' => 1]]);
$capsule->table('tax_rates')->insert(['id' => 1, 'amount' => 10]);
$util = new App\Utils\ProductUtil();
foreach ([[false, '.'], [true, '.'], [true, ',']] as [$formatted, $decimal]) {
    $session->put('currency', ['decimal_separator' => $decimal, 'thousand_separator' => $decimal === ',' ? '.' : ',']);
    $number = static fn ($value) => $formatted ? number_format($value, 4, $decimal, $decimal === ',' ? '.' : ',') : $value;
    foreach (['fixed', 'percentage'] as $type) {
        $input = ['tax_rate_id' => 1, 'discount_type' => 'percentage', 'discount_amount' => $number(10),
            'products' => [['unit_price' => $number(1000), 'unit_price_inc_tax' => $number(1057.5),
                'quantity' => $number(2), 'item_tax' => $number(157.5), 'tax_id' => 1,
                'line_discount_type' => $type, 'line_discount_amount' => $number($type === 'fixed' ? 100 : 10)]]];
        $old = $util->calculateInvoiceTotal($input['products'], 1, $input, $formatted);
        $input['final_total'] = $number($old['final_total'] + 5);
        salesTaxCheck($util->applySalesTaxConfiguration(2, $input, $formatted) === $input,
            'Enabled business tax preserves submitted prices, discounts and invoice tax');
        $normalized = $util->applySalesTaxConfiguration(1, $input, $formatted);
        $read = static fn ($value) => $formatted ? $util->num_uf($value) : (float) $value;
        salesTaxCheck($normalized['tax_rate_id'] === null && $normalized['products'][0]['tax_id'] === null
            && $read($normalized['products'][0]['item_tax']) === 0.0
            && $read($normalized['products'][0]['unit_price_inc_tax']) === 900.0,
            'Disabled business tax removes submitted item and invoice taxes after fixed/percentage discounts');
        salesTaxCheck(abs($read($normalized['final_total']) - 1625) < 0.000001,
            'Removing item and invoice tax preserves invoice discounts and shipping/other charges');
        salesTaxCheck($util->applySalesTaxConfiguration(1, $normalized, $formatted) === $normalized,
            'Repeated normalization does not change totals or apply discounts twice');
    }
}

echo 'Sales tax persistence checks passed: '.$checks." checks\n";
