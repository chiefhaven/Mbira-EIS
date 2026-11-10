<table class="table table-slim receipt-items product-table receipt-product-table" style="width: 100%; font-size: 12px; table-layout: fixed; overflow-wrap: anywhere;">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: 27%;">{{ $receipt_details->table_product_label }}</th>
            <th class="text-right" style="width: 14%;">{{ $receipt_details->table_qty_label }}</th>
            @if(empty($receipt_details->hide_price))
                <th class="text-right" style="width: 18%;">{{ $receipt_details->table_unit_price_label }}</th>
                <th class="text-right" style="width: 18%;">{{ $receipt_details->receipt_item_discount_label }}</th>
                <th class="text-right" style="width: 18%;">{{ $receipt_details->table_subtotal_label }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse($receipt_details->lines as $line)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td class="description" style="overflow-wrap: anywhere;">
                    @if(!empty($line['image']))
                        <img src="{{ $line['image'] }}" alt="Image" width="50">
                    @endif
                    {{ implode(' ', array_unique(array_filter(array_map('trim', [$line['name'], $line['product_variation'] ?? '', $line['variation'] ?? '']), 'strlen'))) }}
                    @if(!empty($line['tax_code']))<b>({{ $line['tax_code'] }})</b>@endif
                    @foreach(['sub_sku', 'brand', 'cat_code', 'product_custom_fields'] as $field)
                        @if(!empty($line[$field])), {{ $line[$field] }}@endif
                    @endforeach
                    @if(!empty($line['product_description']))
                        <br><small>{!! $line['product_description'] !!}</small>
                    @endif
                    @if(!empty($line['sell_line_note']))
                        <br><small>{!! $line['sell_line_note'] !!}</small>
                    @endif
                    @if(!empty($line['lot_number']))
                        <br>{{ $line['lot_number_label'] }}: {{ $line['lot_number'] }}
                    @endif
                    @if(!empty($line['product_expiry']))
                        <br>{{ $line['product_expiry_label'] }}: {{ $line['product_expiry'] }}
                    @endif
                    @if(!empty($line['warranty_name']))<br><small>{{ $line['warranty_name'] }}</small>@endif
                    @if(!empty($line['warranty_exp_date']))<small>- {{ @format_date($line['warranty_exp_date']) }}</small>@endif
                    @if(!empty($line['warranty_description']))<small>{{ $line['warranty_description'] }}</small>@endif
                    @if(!empty($receipt_details->show_base_unit_details) && $line['quantity'] && $line['base_unit_multiplier'] !== 1)
                        <br><small>
                            1 {{ $line['units'] }} = {{ $line['base_unit_multiplier'] }} {{ $line['base_unit_name'] }}
                            @if(empty($receipt_details->hide_price))
                                <br>{{ $line['base_unit_price'] }} x {{ $line['orig_quantity'] }} = {{ $line['line_total'] }}
                            @endif
                        </small>
                    @endif
                </td>
                <td class="text-right">{{ $line['quantity'] }} {{ $line['units'] }}</td>
                @if(empty($receipt_details->hide_price))
                    <td class="text-right">{{ $line['unit_price_before_discount'] ?? $line['unit_price_exc_tax'] ?? $line['unit_price'] }}</td>
                    <td class="text-right">
                        {{ $line['total_line_discount'] ?? '0.00' }}
                        @if(!empty($line['line_discount_percent']))<small>({{ $line['line_discount_percent'] }}%)</small>@endif
                    </td>
                    <td class="text-right">{{ $line['line_total'] }}</td>
                @endif
            </tr>
            @if(!empty($line['modifiers']))
                @foreach($line['modifiers'] as $modifier)
                    <tr>
                        <td></td>
                        <td style="overflow-wrap: anywhere;">
                            {{ implode(' ', array_unique(array_filter(array_map('trim', [$modifier['name'], $modifier['variation'] ?? '']), 'strlen'))) }}
                            @if(!empty($modifier['sub_sku'])), {{ $modifier['sub_sku'] }}@endif
                            @if(!empty($modifier['cat_code'])), {{ $modifier['cat_code'] }}@endif
                            @if(!empty($modifier['sell_line_note']))<br><small>{!! $modifier['sell_line_note'] !!}</small>@endif
                        </td>
                        <td class="text-right">{{ $modifier['quantity'] }} {{ $modifier['units'] }}</td>
                        @if(empty($receipt_details->hide_price))
                            <td class="text-right">{{ $modifier['unit_price_exc_tax'] }}</td>
                            <td class="text-right">0.00</td>
                            <td class="text-right">{{ $modifier['line_total'] }}</td>
                        @endif
                    </tr>
                @endforeach
            @endif
        @empty
            <tr><td colspan="{{ empty($receipt_details->hide_price) ? 6 : 3 }}">&nbsp;</td></tr>
        @endforelse
    </tbody>
</table>
