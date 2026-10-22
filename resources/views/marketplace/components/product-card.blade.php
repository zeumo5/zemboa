<article class="zm-product-card">
  <a class="zm-product-image" href="{{ route('marketplace.products.show', ['shopSlug' => $product->store_slug, 'product' => $product->id]) }}">
  @if($product->image_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}" alt="{{ $product->name }}" loading="lazy" decoding="async">@else<div class="zm-product-no-photo"><span aria-hidden="true">◈</span><span>{{ __('marketplace.no_photo') }}</span></div>@endif
  @if($product->is_featured)<span class="zm-featured">{{ __('marketplace.featured') }}</span>@endif
  </a>
  <div class="zm-product-info"><a class="zm-product-store" href="{{ route('marketplace.shops.show', ['shopSlug' => $product->store_slug]) }}">{{ $product->store_name }} ↗</a><h3><a href="{{ route('marketplace.products.show', ['shopSlug' => $product->store_slug, 'product' => $product->id]) }}">{{ $product->name }}</a></h3><div class="zm-product-price"><strong>{{ $product->display_price === null ? __('marketplace.price_unavailable') : number_format((float) $product->display_price, 0, ',', ' ') . ' FCFA' }}</strong><a href="{{ route('marketplace.products.show', ['shopSlug' => $product->store_slug, 'product' => $product->id]) }}" aria-label="{{ __('marketplace.view_product') }} : {{ $product->name }}">↗</a></div>@include('marketplace.partials.cart-card-action')</div>
</article>
