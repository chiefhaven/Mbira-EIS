@foreach($featured_products as $variation)
	<div class="col-md-3 col-xs-4 product_list no-print">
		<div class="product_box hover:tw-shadow-lg hover:tw-animate-pulse" data-toggle="tooltip" data-placement="bottom" data-variation_id="{{$variation->id}}" title="{{$variation->product->product_description}} @if($variation->product->type == 'variable')- {{$variation->name}} @endif">

		<div class="image-container" 
			style="background-image: url(
					@if(count($variation->media) > 0)
						{{$variation->media->first()->display_url}}
					@elseif(!empty($variation->product->image_url))
						{{$variation->product->image_url}}
					@else
						{{asset('/img/default.png')}}
					@endif
				);
			background-repeat: no-repeat; background-position: center;
			background-size: contain;">
			
		</div>

		<div class="text_div">
			<small class="text text-muted">{{$variation->product->product_description}}
			@if($variation->product->type == 'variable')
				- {{$variation->name}}
			@endif
			</small>

			<small class="text-muted">
				({{$variation->sub_sku}})
			</small>
		</div>
			
		</div>
	</div>
@endforeach