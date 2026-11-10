@if(empty($receipt_details->hide_price))
    @if(isset($receipt_details->taxable_amount))
        <div class="receipt-taxable-amount" style="display: flex; justify-content: space-between; gap: 8px;">
            <span>{{ $receipt_details->taxable_amount_label }}</span>
            <span>{{ $receipt_details->taxable_amount }}</span>
        </div>
    @endif
    <div class="receipt-total-vat" style="display: flex; justify-content: space-between; gap: 8px;">
        <span>{{ $receipt_details->vat_total_label ?? __('sale.total_vat') }}</span>
        <span>{{ $receipt_details->vat_amount }}</span>
    </div>
@endif
