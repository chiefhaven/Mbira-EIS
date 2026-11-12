@if(empty($receipt_details->hide_price))
    @if(!empty($table_rows))
        @if(isset($receipt_details->taxable_amount))
            <tr class="receipt-taxable-amount">
                <th>{{ $receipt_details->taxable_amount_label }}</th>
                <td class="text-right">{{ $receipt_details->taxable_amount }}</td>
            </tr>
        @endif
        @if(!empty($receipt_details->vat_breakdown))
            @foreach($receipt_details->vat_breakdown as $vat_rate)
                <tr class="receipt-total-vat">
                    <th>{{ $vat_rate['label'] }}</th>
                    <td class="text-right">{{ $vat_rate['amount'] }}</td>
                </tr>
            @endforeach
        @else
            <tr class="receipt-total-vat">
                <th>{{ $receipt_details->vat_total_label ?? __('sale.vat').':' }}</th>
                <td class="text-right">{{ $receipt_details->vat_amount }}</td>
            </tr>
        @endif
    @else
        @if(isset($receipt_details->taxable_amount))
        <div class="flex-box receipt-taxable-amount">
            <p class="width-50 {{ $label_alignment ?? 'text-right' }}">{{ $receipt_details->taxable_amount_label }}</p>
            <p class="width-50 text-right">{{ $receipt_details->taxable_amount }}</p>
        </div>
        @endif
        @if(!empty($receipt_details->vat_breakdown))
            @foreach($receipt_details->vat_breakdown as $vat_rate)
                <div class="flex-box receipt-total-vat">
                    <p class="width-50 {{ $label_alignment ?? 'text-right' }}">{{ $vat_rate['label'] }}</p>
                    <p class="width-50 text-right">{{ $vat_rate['amount'] }}</p>
                </div>
            @endforeach
        @else
            <div class="flex-box receipt-total-vat">
                <p class="width-50 {{ $label_alignment ?? 'text-right' }}">{{ $receipt_details->vat_total_label ?? __('sale.vat').':' }}</p>
                <p class="width-50 text-right">{{ $receipt_details->vat_amount }}</p>
            </div>
        @endif
    @endif
@endif
