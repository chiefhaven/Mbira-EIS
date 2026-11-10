@if(!empty($receipt_details->eis_invoice_number))
	@if(!empty($receipt_details->vat_registered))
		<br/><strong>{{ $receipt_details->vat_registered_label }}</strong>
	@endif
	<br/>
	<span style="word-break: break-all; overflow-wrap: anywhere;">
		<strong>{{ __('sale.eis_invoice_number') }}:</strong> {{ $receipt_details->eis_invoice_number }}
	</span>
@endif