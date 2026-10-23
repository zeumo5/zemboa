@extends('marketplace.merchant.layout')
@section('title', $item->name)
@section('content')
<div class="premium-product" data-product-detail>
@include('marketplace.partials.cart-back')
<div class="zm-crumb"><a href="{{ route('marketplace.shops.show', ['shopSlug' => $store->slug]) }}">{{ $store->name }}</a><span aria-hidden="true">&rsaquo;</span><span>{{ $item->name }}</span></div>
<section class="premium-product-card"><div class="premium-gallery">@if($images->count() > 1)<div class="premium-thumbnails">@foreach($images as $image)<button type="button" data-product-image="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" aria-label="{{ $image->alt_text ?: $item->name }} - {{ $loop->iteration }}"><img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" alt="" loading="lazy"></button>@endforeach</div>@endif<div class="zm-detail-visual">@if($item->image_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="{{ $item->name }}" decoding="async">@else<div class="zm-product-no-photo"><span aria-hidden="true">◈</span><span>{{ __('marketplace.no_photo') }}</span></div>@endif</div></div><div class="zm-detail-info"><span class="zm-detail-eyebrow">{{ $item->category_name }}</span><h1>{{ $item->name }}</h1><a class="zm-detail-store-link" href="{{ route('marketplace.shops.show', ['shopSlug' => $store->slug]) }}">{{ __('marketplace.shop_label') }} : <strong>{{ $store->name }}</strong> ↗</a><div class="zm-detail-divider"></div><span class="zm-detail-price-label">{{ __('marketplace.price') }}</span><div class="zm-detail-price">{{ $item->display_price === null ? __('marketplace.price_unavailable') : number_format((float) $item->display_price, 0, ',', ' ') . ' FCFA' }}</div><div class="zm-detail-divider"></div><p class="zm-detail-description">{{ $item->description }}</p>
<p class="premium-stock" data-product-stock>{{ $variants->contains(fn ($variant) => ($variant->stockLevel?->availableQuantity() ?? 0) > 0) ? __('marketplace.storefront.stock') : __('marketplace.storefront.out') }}</p><div class="premium-purchase"><h2>{{ __('cart.add') }}</h2>  <form data-cart-form data-cart-product data-cart-url="{{ route('public.shops.cart.index', ['shopSlug' => $store->slug]) }}" method="post" action="{{ route('public.shops.cart.store', ['shopSlug' => $store->slug]) }}">
    @csrf
    <div class="cart-controls"><label for="cart-variant">{{ __('cart.variant') }}</label>
    <select id="cart-variant" name="product_variant_id" required>
    @foreach($variants as $variant)
      <option value="{{ $variant->id }}" @disabled(($variant->stockLevel?->availableQuantity() ?? 0) < 1)>@include('marketplace.partials.variant-label') &mdash; {{ number_format((float) $variant->effectivePrice(), 0, ',', ' ') }} FCFA</option>
    @endforeach
    </select><label for="cart-quantity">{{ __('cart.quantity_add') }}</label><span data-quantity-stepper class="cart-stepper"><button type="button" data-step="-1" aria-label="{{ __('cart.decrease') }}" disabled>&minus;</button><input id="cart-quantity" name="quantity" type="number" min="1" step="1" value="1" required><button type="button" data-step="1" aria-label="{{ __('cart.increase') }}">+</button></span>
    <button class="zm-btn zm-btn-dark" type="submit" @disabled(!$variants->contains(fn ($variant) => ($variant->stockLevel?->availableQuantity() ?? 0) > 0))>{{ __('cart.add') }}</button></div>
    <p data-cart-status role="status" aria-live="polite"></p>
    <noscript><p>{{ __('cart.javascript') }}</p></noscript>
  </form>
  <div data-cart-current hidden class="cart-current"></div>
  <a class="zm-btn zm-btn-light" href="{{ route('marketplace.shops.cart', ['shopSlug' => $store->slug]) }}">{{ __('cart.view') }}</a>
  <p>{{ __('cart.session_note') }}</p></div></div></section>
<div class="premium-lower"><section class="premium-secondary"><h2>{{ __('marketplace.storefront.variants') }}</h2>@foreach($variants as $variant)<div class="premium-variant" data-product-variant="{{ $variant->id }}" data-stock="{{ ($variant->stockLevel?->availableQuantity() ?? 0) > 0 ? __('marketplace.storefront.stock') : __('marketplace.storefront.out') }}" data-price="{{ number_format((float) $variant->effectivePrice(), 0, ',', ' ') }} FCFA"><strong>@include('marketplace.partials.variant-label')</strong><span>{{ number_format((float) $variant->effectivePrice(), 0, ',', ' ') }} FCFA</span><span>{{ $variant->stockLevel && $variant->stockLevel->availableQuantity() > 0 ? __('marketplace.storefront.stock') : __('marketplace.storefront.out') }}</span></div>@endforeach</section>
@if($item->description)<section class="premium-secondary"><h2>{{ __('marketplace.product_information') }}</h2><p class="premium-description">{{ $item->description }}</p></section>@endif</div>
</div>
@endsection
