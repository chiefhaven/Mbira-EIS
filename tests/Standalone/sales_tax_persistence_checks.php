<?php

$root = dirname(__DIR__, 2);
$productUtil = file_get_contents($root.'/app/Utils/ProductUtil.php');
$productRow = file_get_contents($root.'/resources/views/sale_pos/product_row.blade.php');
$scripts = [
    file_get_contents($root.'/public/js/pos.js'),
    file_get_contents($root.'/public/public/js/pos.js'),
];
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

foreach ($scripts as $index => $script) {
    preg_match('/function pos_each_row\(row_obj\) \{(.*?)\n\}/s', $script, $matches);
    salesTaxCheck(isset($matches[1]), "POS script {$index} must define pos_each_row");
    salesTaxCheck(
        str_contains($matches[1], "__read_number(row_obj.find('input.item_tax'))")
            && !str_contains($matches[1], "data('rate')")
            && !str_contains($matches[1], "__write_number(row_obj.find('input.item_tax')"),
        "POS script {$index} must preserve per-unit tax during row updates"
    );
    salesTaxCheck(
        str_contains($script, "__write_number(tr.find('input.item_tax'), base_unit_item_tax * multiplier)")
            && str_contains($script, "$(this).find(':selected').data('rate')"),
        "POS script {$index} must scale fixed tax by unit and use the selected database rate"
    );
}

echo 'Sales tax persistence checks passed: '.$checks." checks\n";
