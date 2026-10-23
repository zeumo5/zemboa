<li class="checkout-line @if($compact ?? false) checkout-line-compact @endif">
  <div class="commerce-photo">@if(!empty($line['image_url']))<img src="{{ $line['image_url'] }}" alt="" width="112" height="112" loading="lazy">@else<span>{{ __('cart.no_photo') }}</span>@endif</div>
  <div class="checkout-line-info">
    @if(!empty($line['product_url']))<a class="commerce-product-name" href="{{ $line['product_url'] }}">{{ $line['product_name'] }}</a>@else<strong class="commerce-product-name">{{ $line['product_name'] ?? __('cart.unavailable') }}</strong>@endif
    @if(!empty($line['variant_label']))<p class="commerce-variant">{{ $line['variant_label'] }}</p>@endif
    @if($compact ?? false)<p class="commerce-muted">{{ $line['quantity'] }} × {{ isset($line['final_unit_price']) ? number_format((float) $line['final_unit_price'], 0, ',', ' ').' FCFA' : '—' }}</p>@endif
  </div>
  @unless($compact ?? false)<div class="commerce-metric"><span>{{ __('cart.quantity') }}</span><strong>{{ $line['quantity'] }}</strong></div><div class="commerce-metric"><span>{{ __('cart.unit_price') }}</span><strong>{{ isset($line['final_unit_price']) ? number_format((float) $line['final_unit_price'], 0, ',', ' ').' FCFA' : '—' }}</strong></div>@endunless
  <strong class="checkout-line-total">{{ isset($line['line_total']) ? number_format((float) $line['line_total'], 0, ',', ' ').' FCFA' : '—' }}</strong>
</li>
