<?php

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
salesTaxCheck($prepare(['default_sell_price' => 100, 'sell_price_inc_tax' => 117.5], null, 'hide')[1] === 117.5,
    'Hiding tax columns does not replace the database inclusive price');
$order = (object) ['tax_id' => 1, 'item_tax' => 15.75, 'unit_price' => 90,
    'unit_price_before_discount' => 100, 'unit_price_inc_tax' => 105.75];
salesTaxCheck(abs($prepare(['default_sell_price' => 200, 'sell_price_inc_tax' => 235], $order)[1] - 117.5) < 0.000001,
    'Sales-order lines keep their stored price pair instead of current catalog prices');

echo 'Sales tax persistence checks passed: '.$checks." checks\n";
