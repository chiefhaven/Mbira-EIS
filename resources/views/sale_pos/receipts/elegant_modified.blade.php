<table style="width:100%;">
	<tbody>
		<tr>
			<td>

@if(!empty($receipt_details->header_text))
	<div class="row invoice-info">
		<div class="col-xs-12">
			{!! $receipt_details->header_text !!}
		</div>
	</div>
@endif

<!-- business information here -->
<div class="row invoice-info">

	<div class="col-xs-12 text-center color-555">
		
		<!-- Logo -->
		@if(!empty($receipt_details->logo))
			<img src="{{$receipt_details->logo}}" class="img">
			<br/>
		@endif

		<!-- Shop & Location Name  -->
		@if(!empty($receipt_details->display_name))
			<p>
				<strong>{{$receipt_details->business_name ?: $receipt_details->location_name}}</strong>
				@if(!empty($receipt_details->business_name) && !empty($receipt_details->location_name))
					<br/><small>{{$receipt_details->location_name}}</small>
				@endif
				@if(!empty($receipt_details->address))
					<br/>{!! $receipt_details->address !!}
				@endif

				@if(!empty($receipt_details->contact))
					, {!! $receipt_details->contact !!}
				@endif

				@if(!empty($receipt_details->website))
					, {{ $receipt_details->website }}
				@endif

				@if(!empty($receipt_details->tax_info1))
					, {{ $receipt_details->tax_label1 }} {{ $receipt_details->tax_info1 }}
				@endif

				@if(!empty($receipt_details->tax_info2))
					, {{ $receipt_details->tax_label2 }} {{ $receipt_details->tax_info2 }}
				@endif

				@if(!empty($receipt_details->location_custom_fields))
					, {{ $receipt_details->location_custom_fields }}
				@endif
			</p>
		@endif

		<!-- Table information-->
        @if(!empty($receipt_details->table_label) || !empty($receipt_details->table))
        	<p>
				@if(!empty($receipt_details->table_label))
					{!! $receipt_details->table_label !!}
				@endif
				{{$receipt_details->table}}
			</p>
        @endif

		<!-- Waiter info -->
		@if(!empty($receipt_details->service_staff_label) || !empty($receipt_details->service_staff))
        	<p>
				@if(!empty($receipt_details->service_staff_label))
					{!! $receipt_details->service_staff_label !!}
				@endif
				{{$receipt_details->service_staff}}
			</p>
        @endif
	</div>

	<div class="col-xs-12 text-center">

		@include('sale_pos.receipts.partial.vat_registered')
		@if(!empty($receipt_details->invoice_heading))
			<h3>{!! $receipt_details->invoice_heading !!}</h3>
		@endif
		<p>
			@if(!empty($receipt_details->invoice_no_prefix))
				{!! $receipt_details->invoice_no_prefix !!}
			@endif

			{{$receipt_details->invoice_no}}

			@if(!empty($receipt_details->date_label))
				, {{$receipt_details->date_label}}
				{{$receipt_details->invoice_date}}
			@endif
			@include('sale_pos.receipts.partial.eis_invoice_number')
		</p>
		<p>
			<strong>{{ $receipt_details->customer_label }}</strong> {{$receipt_details->customer_name ?? ''}} <br>
			@if(!empty($receipt_details->customer_info))
				{!! str_replace('<br>', ', ', $receipt_details->customer_info) !!}
			@endif
			@if(!empty($receipt_details->client_id_label))
				, 
				<strong>{{ $receipt_details->client_id_label }}</strong> {{ $receipt_details->client_id }}
			@endif
			@if(!empty($receipt_details->customer_tax_label))
				,
				<strong>{{ $receipt_details->customer_tax_label }}</strong> {{ $receipt_details->customer_tax_number }}
			@endif
			@if(!empty($receipt_details->customer_custom_fields))
				, {!! $receipt_details->customer_custom_fields !!}
			@endif
			@if(!empty($receipt_details->sales_person_label))
				<br>
				<strong>{{ $receipt_details->sales_person_label }}</strong> {{ $receipt_details->sales_person }}
			@endif
		</p>
		<p>
			@if(!empty($receipt_details->sub_heading_line1))
				{{ $receipt_details->sub_heading_line1 }}
			@endif
			@if(!empty($receipt_details->sub_heading_line2))
				, {{ $receipt_details->sub_heading_line2 }}
			@endif
			@if(!empty($receipt_details->sub_heading_line3))
				, {{ $receipt_details->sub_heading_line3 }}
			@endif
			@if(!empty($receipt_details->sub_heading_line4))
				, {{ $receipt_details->sub_heading_line4 }}
			@endif		
			@if(!empty($receipt_details->sub_heading_line5))
				, {{ $receipt_details->sub_heading_line5 }}
			@endif
		</p>
	</div>
</div>


<div class="row color-555">
	<div class="col-xs-12">
		<br/>
		@include('sale_pos.receipts.partial.product_table')
	</div>
</div>

<div class="row invoice-info color-555" style="page-break-inside: avoid !important">
	<div class="col-md-6 invoice-col width-50">
		<table class="table table-condensed">
			@if(!empty($receipt_details->payments))
				@foreach($receipt_details->payments as $payment)
					<tr>
						<td>{{$payment['method']}} ({{$payment['date']}}):</td>
						<td>{{$payment['amount']}}</td>
					</tr>
				@endforeach
			@endif
		</table>
		<b class="pull-left">Authorized Signatory</b>
	</div>

	<div class="col-md-6 invoice-col width-50">
		<table class="table-no-side-cell-border table-no-top-cell-border width-100">
			<tbody>
				<tr class="color-555">
					<td style="width:50%">
						{!! $receipt_details->subtotal_label !!}
					</td>
					<td class="text-right">
						{{$receipt_details->subtotal}}
					</td>
				</tr>
				
				<!-- Shipping Charges -->
				@if(!empty($receipt_details->shipping_charges))
					<tr class="color-555">
						<td style="width:50%">
							{!! $receipt_details->shipping_charges_label !!}
						</td>
						<td class="text-right">
							{{$receipt_details->shipping_charges}}
						</td>
					</tr>
				@endif

				<!-- Discount -->
				@if( !empty($receipt_details->discount) )
					<tr class="color-555">
						<td>
							{!! $receipt_details->discount_label !!}
						</td>

						<td class="text-right">
							(-) {{$receipt_details->discount}}
						</td>
					</tr>
				@endif

				<!-- Total -->
				<tr>
					<th style="background-color: #357ca5 !important; color: white !important" class="font-23 padding-10">
						{!! $receipt_details->total_label !!}
					</th>
					<td class="text-right font-23 padding-10" style="background-color: #357ca5 !important; color: white !important">
						{{$receipt_details->total}}
					</td>
				</tr>
			</tbody>
        </table>
		@include('sale_pos.receipts.partial.vat_summary')
	</div>
</div>

<div class="row color-555">
	<div class="col-xs-6">
		{{$receipt_details->additional_notes}}
	</div>
</div>
{{-- Barcode --}}
@if($receipt_details->show_barcode)
<br>
<div class="row">
		<div class="col-xs-12">
			<img class="center-block" src="data:image/png;base64,{{DNS1D::getBarcodePNG($receipt_details->invoice_no, 'C128', 2,30,array(39, 48, 54), true)}}">
		</div>
</div>
@endif

@if(!empty($receipt_details->footer_text))
	<div class="row color-555">
		<div class="col-xs-12">
			{!! $receipt_details->footer_text !!}
		</div>
	</div>
@endif

			</td>
		</tr>
	</tbody>
</table>