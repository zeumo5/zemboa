<div class="merchant-filter-links"><a href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug, 'q' => $search]) }}" @if($selectedCategory === '') aria-current="page" @endif>{{ __('marketplace.storefront.all') }}</a>@foreach($categories as $category)<a href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug, 'category' => $category->slug, 'q' => $search]) }}" @if($selectedCategory === $category->slug) aria-current="page" @endif>{{ $category->name }}</a>@endforeach</div>
<p class="merchant-result-count">{{ $products->total() }} {{ __('marketplace.storefront.results') }}@if($search !== '') · {{ $search }}@endif</p>
@if($products->isEmpty())<p>{{ __('marketplace.storefront.empty') }}</p>@else
<div class="zm-products-grid">@foreach($products as $product)
@php
  $cardVariants = $variantGroups->get($product->id, collect());
  $available = $cardVariants->contains(fn ($variant) => $variant->stockLevel && $variant->stockLevel->availableQuantity() > 0);
  $knownUnavailable = $cardVariants->isNotEmpty() && $cardVariants->every(fn ($variant) => $variant->stockLevel !== null) && !$available;
@endphp
<article class="zm-product-card merchant-product-card">
  <a class="zm-product-image merchant-product-image" href="{{ route('marketplace.products.show', ['shopSlug' => $store->slug, 'product' => $product->id]) }}" aria-label="{{ $product->name }} — {{ __('marketplace.storefront.view_details') }}">@if($product->image_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}" alt="{{ $product->name }}" width="640" height="640" loading="lazy" decoding="async">@else<span class="zm-product-no-photo">{{ __('marketplace.no_photo') }}</span>@endif
  @if(app()->environment('demo'))<span class="merchant-demo-tag">{{ __('marketplace.storefront.demo_image') }}</span>@endif</a>
  <div class="merchant-product-body"><span class="merchant-product-category">{{ $product->category_name }}</span><h3><a href="{{ route('marketplace.products.show', ['shopSlug' => $store->slug, 'product' => $product->id]) }}">{{ $product->name }}</a></h3>
    <div class="merchant-product-price"><strong>{{ $product->display_price === null ? __('marketplace.price_unavailable') : number_format((float) $product->display_price, 0, ',', ' ') . ' FCFA' }}</strong>@if($available || $knownUnavailable)<span class="merchant-stock @if($knownUnavailable) merchant-stock-empty @endif">{{ $available ? __('marketplace.storefront.stock') : __('marketplace.storefront.out') }}</span>@endif</div>
    @include('marketplace.partials.cart-card-action', ['showCartIcon' => true])
  </div>
  <details class="zm-merchant-variants"><summary>{{ __('marketplace.storefront.variants') }}</summary><ul>@foreach($cardVariants as $variant)<li>@include('marketplace.partials.variant-label') · {{ number_format((float) $variant->effectivePrice(), 0, ',', ' ') }} FCFA · {{ !$variant->stockLevel ? __('marketplace.storefront.stock_unknown') : ($variant->stockLevel->availableQuantity() > 0 ? __('marketplace.storefront.stock') : __('marketplace.storefront.out')) }}</li>@endforeach</ul></details>
</article>
@endforeach</div>
@if($products->hasPages())
  <div class="zm-pagination">{{ $products->links() }}</div>
@endif
@endif
