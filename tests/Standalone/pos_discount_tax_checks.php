<?php

$root = dirname(__DIR__, 2);
$modal = file_get_contents($root.'\resources\views\sale_pos\partials\row_edit_product_price_modal.blade.php');
$checks = 0;

function posDiscountTaxCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

posDiscountTaxCheck(
    str_contains($modal, 'Form::hidden("products[$row_count][tax_id]", $tax_id)'),
    'The non-editable tax ID must still be submitted with the product'
);
posDiscountTaxCheck(
    str_contains($modal, '\'class\' => \'form-control tax_id\', \'disabled\' => true], $tax_dropdown[\'attributes\']'),
    'The tax selector must be disabled while retaining its tax rate attributes'
);
posDiscountTaxCheck(
    str_contains($modal, 'Form::hidden("products[$row_count][item_tax]", @num_format($item_tax), [\'class\' => \'item_tax\'])'),
    'The stored item tax must remain available for calculations and submission'
);
foreach (['line_discount_type', 'line_discount_amount'] as $field) {
    preg_match('/Form::(?:select|text)\("products\[\$row_count\]\['.$field.'\]".*?;/', $modal, $matches);
    posDiscountTaxCheck(
        isset($matches[0]) && !str_contains($matches[0], "'disabled'") && !str_contains($matches[0], "'readonly'"),
        'Discount fields must remain editable: '.$field
    );
}

echo 'POS discount tax checks passed: '.$checks." checks\n";
