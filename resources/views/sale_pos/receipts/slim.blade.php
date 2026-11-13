<!-- business information here -->
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <!-- <link rel="stylesheet" href="style.css"> -->
        <title>Receipt-{{$receipt_details->invoice_no}}</title>
    </head>
    <body>
        <div class="ticket">
        	@if(empty($receipt_details->letter_head))
				@if(!empty($receipt_details->logo))
					<div class="text-box centered receipt-logo">
						<img src="{{$receipt_details->logo}}" alt="Logo">
					</div>
				@endif
				<div class="text-box receipt-header">
				<!-- Logo -->
				<p class="centered">
					<!-- Header text -->
					@if(!empty($receipt_details->header_text))
						<span class="headings">{!! $receipt_details->header_text !!}</span>
						<br/>
					@endif

					<!-- business information here -->
					@if(!empty($receipt_details->display_name))
						<span class="headings">
							{{$receipt_details->business_name ?: $receipt_details->location_name}}
						</span>
						<br/>
						@if(!empty($receipt_details->business_name) && !empty($receipt_details->location_name))
							<small>{{$receipt_details->location_name}}</small>
							<br/>
						@endif
					@endif
					
					@if(!empty($receipt_details->address))
						{!! $receipt_details->address !!}
						<br/>
					@endif

					@if(!empty($receipt_details->contact))
						{!! $receipt_details->contact !!}
					@endif
					@if(!empty($receipt_details->contact) && !empty($receipt_details->website))
						, 
					@endif
					@if(!empty($receipt_details->website))
						{{ $receipt_details->website }}
					@endif
					@if(!empty($receipt_details->location_custom_fields))
						<br>{{ $receipt_details->location_custom_fields }}
					@endif

					@if(!empty($receipt_details->sub_heading_line1))
						{{ $receipt_details->sub_heading_line1 }}<br/>
					@endif
					@if(!empty($receipt_details->sub_heading_line2))
						{{ $receipt_details->sub_heading_line2 }}<br/>
					@endif
					@if(!empty($receipt_details->sub_heading_line3))
						{{ $receipt_details->sub_heading_line3 }}<br/>
					@endif
					@if(!empty($receipt_details->sub_heading_line4))
						{{ $receipt_details->sub_heading_line4 }}<br/>
					@endif		
					@if(!empty($receipt_details->sub_heading_line5))
						{{ $receipt_details->sub_heading_line5 }}<br/>
					@endif

					@if(!empty($receipt_details->tax_info1))
						<br><span class="tax-id"><b>{{ $receipt_details->tax_label1 }}</b> {{ $receipt_details->tax_info1 }}</span>
						@if(!empty($receipt_details->tax_office_name))
							<br>{{ $receipt_details->tax_office_name }}
						@endif
					@endif

					@if(!empty($receipt_details->tax_info2))
						<span class="tax-id"><b>{{ $receipt_details->tax_label2 }}</b> {{ $receipt_details->tax_info2 }}</span>
					@endif
				@endif
				</p>
				</div>
				<!-- Title of receipt -->
				@include('sale_pos.receipts.partial.vat_registered')
				@if(!empty($receipt_details->invoice_heading))
					<div class="receipt-title">{!! $receipt_details->invoice_heading !!}</div>
				@endif
				@if(!empty($receipt_details->letter_head))
					<div class="text-box">
						<img style="width: 100%;margin-bottom: 10px;" src="{{$receipt_details->letter_head}}">
					</div>
				@endif
			<div class="textbox-info receipt-meta-first receipt-left-aligned">
				<p class="f-left"><strong>{!! $receipt_details->invoice_no_prefix !!}</strong></p>
				<p class="f-right">
					{{$receipt_details->invoice_no}}
				</p>
			</div>
			@if(!empty($receipt_details->eis_invoice_number))
				<div class="textbox-info receipt-eis-number">
					@include('sale_pos.receipts.partial.eis_invoice_number')
				</div>
			@endif
			<div class="textbox-info receipt-left-aligned">
				<p class="f-left"><strong>{!! $receipt_details->date_label !!}</strong></p>
				<p class="f-right">
					{{$receipt_details->invoice_date}}
				</p>
			</div>
			
			@if(!empty($receipt_details->due_date_label))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->due_date_label}}</strong></p>
					<p class="f-right">{{$receipt_details->due_date ?? ''}}</p>
				</div>
			@endif

			@if(!empty($receipt_details->sales_person_label))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->sales_person_label}}</strong></p>
				
					<p class="f-right">{{$receipt_details->sales_person}}</p>
				</div>
			@endif
			@if(!empty($receipt_details->commission_agent_label))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->commission_agent_label}}</strong></p>
				
					<p class="f-right">{{$receipt_details->commission_agent}}</p>
				</div>
			@endif

			@if(!empty($receipt_details->brand_label) || !empty($receipt_details->repair_brand))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->brand_label}}</strong></p>
				
					<p class="f-right">{{$receipt_details->repair_brand}}</p>
				</div>
			@endif

			@if(!empty($receipt_details->device_label) || !empty($receipt_details->repair_device))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->device_label}}</strong></p>
				
					<p class="f-right">{{$receipt_details->repair_device}}</p>
				</div>
			@endif
			
			@if(!empty($receipt_details->model_no_label) || !empty($receipt_details->repair_model_no))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->model_no_label}}</strong></p>
				
					<p class="f-right">{{$receipt_details->repair_model_no}}</p>
				</div>
			@endif
			
			@if(!empty($receipt_details->serial_no_label) || !empty($receipt_details->repair_serial_no))
				<div class="textbox-info">
					<p class="f-left"><strong>{{$receipt_details->serial_no_label}}</strong></p>
				
					<p class="f-right">{{$receipt_details->repair_serial_no}}</p>
				</div>
			@endif

			@if(!empty($receipt_details->repair_status_label) || !empty($receipt_details->repair_status))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{!! $receipt_details->repair_status_label !!}
					</strong></p>
					<p class="f-right">
						{{$receipt_details->repair_status}}
					</p>
				</div>
        	@endif

        	@if(!empty($receipt_details->repair_warranty_label) || !empty($receipt_details->repair_warranty))
	        	<div class="textbox-info">
	        		<p class="f-left"><strong>
	        			{!! $receipt_details->repair_warranty_label !!}
	        		</strong></p>
	        		<p class="f-right">
	        			{{$receipt_details->repair_warranty}}
	        		</p>
	        	</div>
        	@endif

        	<!-- Waiter info -->
			@if(!empty($receipt_details->service_staff_label) || !empty($receipt_details->service_staff))
	        	<div class="textbox-info">
	        		<p class="f-left"><strong>
	        			{!! $receipt_details->service_staff_label !!}
	        		</strong></p>
	        		<p class="f-right">
	        			{{$receipt_details->service_staff}}
					</p>
	        	</div>
	        @endif

	        @if(!empty($receipt_details->table_label) || !empty($receipt_details->table))
	        	<div class="textbox-info">
	        		<p class="f-left"><strong>
	        			@if(!empty($receipt_details->table_label))
							<b>{!! $receipt_details->table_label !!}</b>
						@endif
	        		</strong></p>
	        		<p class="f-right">
	        			{{$receipt_details->table}}
	        		</p>
	        	</div>
	        @endif

			@if (!empty($receipt_details->sell_custom_field_1_value))
				<div class="textbox-info">
					<p class="f-left"><strong>{!! $receipt_details->sell_custom_field_1_label !!}</strong></p>
					<p class="f-right">
						{{$receipt_details->sell_custom_field_1_value}}
					</p>
				</div>
			@endif
			@if (!empty($receipt_details->sell_custom_field_2_value))
				<div class="textbox-info">
					<p class="f-left"><strong>{!! $receipt_details->sell_custom_field_2_label !!}</strong></p>
					<p class="f-right">
						{{$receipt_details->sell_custom_field_2_value}}
					</p>
				</div>
			@endif
			@if (!empty($receipt_details->sell_custom_field_3_value))
				<div class="textbox-info">
					<p class="f-left"><strong>{!! $receipt_details->sell_custom_field_3_label !!}</strong></p>
					<p class="f-right">
						{{$receipt_details->sell_custom_field_3_value}}
					</p>
				</div>
			@endif
			@if (!empty($receipt_details->sell_custom_field_4_value))
				<div class="textbox-info">
					<p class="f-left"><strong>{!! $receipt_details->sell_custom_field_4_label !!}</strong></p>
					<p class="f-right">
						{{$receipt_details->sell_custom_field_4_value}}
					</p>
				</div>
			@endif

			<!-- customer info -->
			<div class="textbox-info receipt-customer receipt-left-aligned">
				<p><strong>
					{{$receipt_details->customer_label ?? ''}}
				</strong></p>
				@if(!empty($receipt_details->customer_info))
					<div class="bw">
					{!! $receipt_details->customer_info !!}
					</div>
				@endif
	        </div>
			
			@if(!empty($receipt_details->client_id_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{{ $receipt_details->client_id_label }}
					</strong></p>
					<p class="f-right">
						{{ $receipt_details->client_id }}
					</p>
				</div>
			@endif
			
			@if(!empty($receipt_details->customer_tax_label))
				<div class="textbox-info receipt-left-aligned">
					<p class="f-left"><strong>
						{{ $receipt_details->customer_tax_label }}
					</strong></p>
					<p class="f-right">
						{{ $receipt_details->customer_tax_number }}
					</p>
				</div>
			@endif

			@if(!empty($receipt_details->customer_custom_fields))
				<div class="textbox-info">
					<p class="centered">
						{!! $receipt_details->customer_custom_fields !!}
					</p>
				</div>
			@endif
			
			@if(!empty($receipt_details->customer_rp_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{{ $receipt_details->customer_rp_label }}
					</strong></p>
					<p class="f-right">
						{{ $receipt_details->customer_total_rp }}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->shipping_custom_field_1_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{!!$receipt_details->shipping_custom_field_1_label!!} 
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->shipping_custom_field_1_value ?? ''!!}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->shipping_custom_field_2_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{!!$receipt_details->shipping_custom_field_2_label!!} 
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->shipping_custom_field_2_value ?? ''!!}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->shipping_custom_field_3_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{!!$receipt_details->shipping_custom_field_3_label!!} 
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->shipping_custom_field_3_value ?? ''!!}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->shipping_custom_field_4_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{!!$receipt_details->shipping_custom_field_4_label!!} 
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->shipping_custom_field_4_value ?? ''!!}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->shipping_custom_field_5_label))
				<div class="textbox-info">
					<p class="f-left"><strong>
						{!!$receipt_details->shipping_custom_field_5_label!!} 
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->shipping_custom_field_5_value ?? ''!!}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->sale_orders_invoice_no))
				<div class="textbox-info">
					<p class="f-left"><strong>
						@lang('restaurant.order_no')
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->sale_orders_invoice_no ?? ''!!}
					</p>
				</div>
			@endif

			@if(!empty($receipt_details->sale_orders_invoice_date))
				<div class="textbox-info">
					<p class="f-left"><strong>
						@lang('lang_v1.order_dates')
					</strong></p>
					<p class="f-right">
						{!!$receipt_details->sale_orders_invoice_date ?? ''!!}
					</p>
				</div>
			@endif
            @include('sale_pos.receipts.partial.product_table')
			@if(!empty($receipt_details->total_quantity_label))
				<div class="flex-box">
					<p class="left text-right">
						{!! $receipt_details->total_quantity_label !!}
					</p>
					<p class="width-50 text-right">
						{{$receipt_details->total_quantity}}
					</p>
				</div>
			@endif
			@if(!empty($receipt_details->total_items_label))
				<div class="flex-box">
					<p class="left text-right">
						{!! $receipt_details->total_items_label !!}
					</p>
					<p class="width-50 text-right">
						{{$receipt_details->total_items}}
					</p>
				</div>
			@endif
			@if(empty($receipt_details->hide_price))
                <!-- Shipping Charges -->
				@if(!empty($receipt_details->shipping_charges))
					<div class="flex-box">
						<p class="left text-right">
							{!! $receipt_details->shipping_charges_label !!}
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->shipping_charges}}
						</p>
					</div>
				@endif

				@if(!empty($receipt_details->packing_charge))
					<div class="flex-box">
						<p class="left text-right">
							{!! $receipt_details->packing_charge_label !!}
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->packing_charge}}
						</p>
					</div>
				@endif

				<!-- Discount -->
				@if( !empty($receipt_details->discount) )
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->discount_label !!}
						</p>

						<p class="width-50 text-right">
							(-) {{$receipt_details->discount}}
						</p>
					</div>
				@endif

				@if( !empty($receipt_details->total_line_discount) )
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->line_discount_label !!}
						</p>

						<p class="width-50 text-right">
							(-) {{$receipt_details->total_line_discount}}
						</p>
					</div>
				@endif

				@if( !empty($receipt_details->additional_expenses) )
					@foreach($receipt_details->additional_expenses as $key => $val)
						<div class="flex-box">
							<p class="width-50 text-right">
								{{$key}}:
							</p>

							<p class="width-50 text-right">
								(+) {{$val}}
							</p>
						</div>
					@endforeach
				@endif

				@if(!empty($receipt_details->reward_point_label) )
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->reward_point_label !!}
						</p>

						<p class="width-50 text-right">
							(-) {{$receipt_details->reward_point_amount}}
						</p>
					</div>
				@endif

				@if( $receipt_details->round_off_amount > 0)
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->round_off_label !!} 
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->round_off}}
						</p>
					</div>
				@endif

				@include('sale_pos.receipts.partial.vat_summary')
				<div class="flex-box">
					<p class="width-50 text-right">
						{!! $receipt_details->total_label !!}
					</p>
					<p class="width-50 text-right">
						{{$receipt_details->total}}
					</p>
				</div>
				@if(!empty($receipt_details->total_in_words))
				<p colspan="2" class="text-right mb-0">
					<small>
					({{$receipt_details->total_in_words}})
					</small>
				</p>
				@endif
				@if(!empty($receipt_details->payments))
					@foreach($receipt_details->payments as $payment)
						<div class="flex-box">
							<p class="width-50 text-right">{{$payment['method']}} ({{$payment['date']}}):</p>
							<p class="width-50 text-right">{{$payment['amount']}}</p>
						</div>
					@endforeach
				@endif

				<!-- Total Paid-->
				@if(!empty($receipt_details->total_paid))
					<div class="flex-box">
						<p class="width-50 text-right">
							Total Paid:
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->total_paid}}
						</p>
					</div>
				@endif

				<!-- Total Due-->
				@if(!empty($receipt_details->total_due) && !empty($receipt_details->total_due_label))
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->total_due_label !!}
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->total_due}}
						</p>
					</div>
				@endif
				@if(!empty($receipt_details->total_previous_due))
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->total_previous_due_label !!}
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->total_previous_due}}
						</p>
					</div>
				@endif
				@if(!empty($receipt_details->all_due))
					<div class="flex-box">
						<p class="width-50 text-right">
							{!! $receipt_details->all_bal_label !!}
						</p>
						<p class="width-50 text-right">
							{{$receipt_details->all_due}}
						</p>
					</div>
				@endif
				
			@endif
            @if(!empty($receipt_details->additional_notes))
	            <p class="centered receipt-notes">
	            	{!! nl2br($receipt_details->additional_notes) !!}
	            </p>
            @endif

            <div class="receipt-footer">
            {{-- Barcode --}}
			@if($receipt_details->show_barcode)
				<img class="center-block receipt-barcode" src="data:image/png;base64,{{DNS1D::getBarcodePNG($receipt_details->invoice_no, 'C128', 2,30,array(39, 48, 54), true)}}">
			@endif

			@if($receipt_details->show_qr_code && (!empty($receipt_details->qr_code_text) || !empty($receipt_details->qr_code_is_offline)))
				@if(!empty($receipt_details->qr_code_text))
					<img class="center-block receipt-qr" src="data:image/png;base64,{{DNS2D::getBarcodePNG($receipt_details->qr_code_text, 'QRCODE')}}">
				@endif
				@if(!empty($receipt_details->qr_code_is_offline))
					<div class="center-block mt-2">***Offline***</div>
				@endif
			@endif
			
			@if(!empty($receipt_details->footer_text))
				<p class="centered receipt-footer-text">
					{!! $receipt_details->footer_text !!}
				</p>
			@endif
            </div>
			
        </div>
        <!-- <button id="btnPrint" class="hidden-print">Print</button>
        <script src="script.js"></script> -->
    </body>
</html>

<style type="text/css">
.ticket {
	--receipt-ink: #000;
	--receipt-muted: #444;
	width: 100%;
	max-width: 80mm;
	margin: 0 auto;
	padding: 4px 2px;
	color: var(--receipt-ink);
	background: #fff;
	font-family: "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
	font-size: 12px;
	line-height: 1.35;
	font-variant-numeric: tabular-nums;
}
.ticket * { box-sizing: border-box; }
.ticket p { margin: 0; }
.ticket img { max-width: 100%; height: auto; }
.ticket .f-8 { font-size: 8px !important; }
.ticket, .ticket * { font-weight: 400 !important; }

.ticket .centered { text-align: center; }
.ticket .text-right { text-align: right; }
.ticket .text-center { text-align: center; }
.ticket .width-100 { width: 100%; }
.ticket .bw { word-break: break-word; overflow-wrap: anywhere; }
.ticket .text-box { width: 100%; }

/* Header */
.ticket .receipt-logo { margin-bottom: 6px; }
.ticket .receipt-logo img { max-height: 80px; width: auto; display: inline-block; }
.ticket .receipt-header { color: var(--receipt-muted); font-size: 11px; }
.ticket .headings {
	display: inline-block;
	color: var(--receipt-ink);
	font-size: 17px;
	font-weight: 800;
	letter-spacing: .04em;
	text-transform: uppercase;
	margin-bottom: 2px;
}
.ticket .tax-id { display: inline-block; margin: 0 3px; color: var(--receipt-ink); }
.ticket .eis-legal-marker {
	display: block;
	margin: 0 0 6px;
	color: var(--receipt-ink);
	font-size: 10px !important;
	font-weight: 700 !important;
	letter-spacing: .12em;
	line-height: 1.4 !important;
	text-align: center;
	text-transform: uppercase;
	white-space: nowrap;
}
.ticket .eis-legal-marker + br { display: none; }
.ticket .receipt-footer-text .eis-legal-marker { margin: 8px 0 0; }
.ticket .receipt-title {
	margin: 10px 0 8px;
	text-align: center;
	font-size: 13px;
	font-weight: 800;
	letter-spacing: .18em;
	text-transform: uppercase;
}
.ticket .sub-headings { font-size: 13px !important; font-weight: 700 !important; }

/* Meta rows */
.ticket .textbox-info {
	clear: both;
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
	padding: 2px 0;
}
.ticket .textbox-info::after { content: none; }
.ticket .textbox-info p.f-left,
.ticket .textbox-info p.f-right { float: none; }
.ticket .textbox-info p.f-left { flex: 0 0 auto; color: var(--receipt-muted); }
.ticket .textbox-info p.f-left strong { font-weight: 600; }
.ticket .textbox-info p.f-right { flex: 1 1 auto; text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.ticket .receipt-left-aligned { justify-content: flex-start; text-align: left; }
.ticket .receipt-left-aligned p.f-right { text-align: left; }
.ticket .textbox-info > p.centered { flex: 1 1 100%; }
.ticket .receipt-meta-first { margin-top: 2px; }
.ticket .receipt-eis-number { justify-content: flex-start; font-weight: 600; text-align: left; overflow-wrap: anywhere; }
.ticket .receipt-eis-number br { display: none; }
.ticket .receipt-eis-number > span { display: contents; }
.ticket .receipt-eis-number strong { flex: 0 0 auto; color: var(--receipt-muted); font-weight: 600; }
.ticket .receipt-customer {
	display: block;
	margin-top: 6px;
	text-align: left;
}
.ticket .receipt-customer .bw,
.ticket .receipt-customer .bw * { text-align: left !important; }
.ticket .receipt-customer strong { color: var(--receipt-muted); font-weight: 600; }

/* Items */
.ticket table { border-collapse: collapse; }
.ticket .receipt-items { margin: 10px 0 6px; border-bottom: 1px dashed var(--receipt-ink); }
.ticket .receipt-items thead th {
	padding: 5px 2px;
	border-bottom: 1px dashed var(--receipt-ink);
	font-size: 10px !important;
	font-weight: 700;
	letter-spacing: .06em;
	text-transform: uppercase;
	text-align: left;
}
.ticket .receipt-items thead th.text-right { text-align: right; }
.ticket .receipt-items tbody td { padding: 4px 2px; vertical-align: top; }
.ticket .receipt-items td.description { font-weight: 600; }
.ticket .receipt-items td.description small,
.ticket .receipt-items td.description .f-8 { font-weight: 400; color: var(--receipt-muted); }
.ticket .table-f-12 th, .ticket .table-f-12 td { font-size: 12px; word-break: break-word; }
.ticket td.serial_number, .ticket th.serial_number { width: 6%; color: var(--receipt-muted); }
.ticket td.description, .ticket th.description { width: 36%; }
.ticket td.quantity, .ticket th.quantity { width: 14%; }
.ticket td.unit_price, .ticket th.unit_price { width: 22%; }
.ticket td.price, .ticket th.price { width: 22%; }

/* Totals */
.ticket .flex-box {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	width: 100%;
	padding: 2px 0;
}
.ticket .flex-box p { width: 50%; margin: 0; white-space: nowrap; }
.ticket .flex-box p:first-child { color: var(--receipt-muted); }
.ticket .flex-box p:last-child { font-weight: 600; color: var(--receipt-ink); }
.ticket .flex-box.grand-total { margin: 4px 0 6px; }
.ticket .flex-box.grand-total p {
	color: var(--receipt-ink);
	font-size: 16px !important;
	font-weight: 800 !important;
	text-transform: uppercase;
}

/* Tax summary */
.ticket .receipt-tax-summary { margin: 8px 0; }
.ticket .receipt-tax-summary th { padding: 4px 2px; font-size: 10px !important; letter-spacing: .06em; text-transform: uppercase; }
.ticket .receipt-tax-summary td { padding: 2px; }
.ticket .receipt-tax-summary td.right { text-align: right; font-weight: 600; }

/* Footer */
.ticket .receipt-notes { margin: 8px 0; color: var(--receipt-muted); font-size: 11px; }
.ticket .receipt-footer {
	margin-top: 10px;
	text-align: center;
}
.ticket .receipt-footer img { display: block; margin: 6px auto; }
.ticket .receipt-barcode { max-width: 90%; }
.ticket .receipt-qr { width: 120px; max-width: 60%; image-rendering: pixelated; }
.ticket .receipt-footer-text { margin-top: 6px; font-size: 11px; color: var(--receipt-muted); }

/* Legacy helpers still used by layout options */
.table-info { width: 100%; }
.table-info tr:first-child td, .table-info tr:first-child th { padding-top: 8px; }
.table-info th { text-align: left; }
.table-info td { text-align: right; }
.logo { float: left; width: 35%; padding: 10px; }
.text-with-image { float: left; width: 65%; }

@media print {
	.ticket {
		--receipt-muted: #000;
		max-width: 100%;
		padding: 0;
		-webkit-print-color-adjust: exact;
		print-color-adjust: exact;
	}
	.ticket, .ticket * { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
	.ticket tr, .ticket .flex-box, .ticket .receipt-footer img { page-break-inside: avoid; break-inside: avoid; }
	.hidden-print, .hidden-print * { display: none !important; }
}
</style>
