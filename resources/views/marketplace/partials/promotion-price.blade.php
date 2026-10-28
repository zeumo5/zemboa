@php
$calculator=app(\App\Actions\Orders\OrderPriceCalculator::class);
$regular=$base===null?null:$calculator->toFcfa((string)$base);
$current=$net===null?null:$calculator->toFcfa((string)$net);
$reduced=$regular!==null && $current!==null && $regular>$current;
$percent=$reduced?intdiv(($regular-$current)*100,$regular):0;
@endphp
<span class="promotion-price"><s data-product-original @if(!$reduced) hidden @endif>{{ $regular!==null?number_format($regular,0,',',' ').' FCFA':'' }}</s><strong data-product-net>{{ $current!==null?number_format($current,0,',',' ').' FCFA':__('marketplace.price_unavailable') }}</strong><span class="promotion-reduction" data-product-reduction @if(!$reduced) hidden @endif>{{ $reduced?'−'.($percent?:'<1').'%':'' }}</span></span>
