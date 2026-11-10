@if(empty($receipt_details->hide_price))
    @if(!empty($table_rows))
        @if(isset($receipt_details->taxable_amount))
            <tr class="receipt-taxable-amount">
                <th>{{ $receipt_details->taxable_amount_label }}</th>
                <td class="text-right">{{ $receipt_details->taxable_amount }}</td>
            </tr>
        @endif
        <tr class="receipt-total-vat">
            <th>{{ $receipt_details->vat_total_label ?? __('sale.vat').':' }}</th>
            <td class="text-right">{{ $receipt_details->vat_amount }}</td>
        </tr>
    @else
    @if(isset($receipt_details->taxable_amount))
        <div class="receipt-taxable-amount" style="display: flex; justify-content: space-between; gap: 8px;">
            <span>{{ $receipt_details->taxable_amount_label }}</span>
            <span>{{ $receipt_details->taxable_amount }}</span>
        </div>
    @endif
    <div class="receipt-total-vat" style="display: flex; justify-content: space-between; gap: 8px;">
        <span>{{ $receipt_details->vat_total_label ?? __('sale.vat').':' }}</span>
        <span>{{ $receipt_details->vat_amount }}</span>
    </div>
    @endif
@endif
