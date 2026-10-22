@php
  $savedCart = session('zemboa.cart', []);
  $cartCounts = \App\Services\CartService::sessionCounts();
  $cartCount = $cartCounts['distinct_products'] . ($cartCounts['unidentified_lines'] ? '+' : '');
  $hasCartItems = $cartCounts['units'] > 0;
  $cartOwner = $hasCartItems ? (int) ($savedCart['store_id'] ?? 0) : 0;
  $cartStore = $cartOwner ? \App\Models\Store::query()->where('status', 'ACTIVE')->find($cartOwner) : null;
  $currentCartStore = $merchantCartStore ?? null;
  $emptyCartUrl = $currentCartStore ? route('marketplace.shops.cart', ['shopSlug' => $currentCartStore->slug]) : route('marketplace.stores');
  $cartHref = $cartStore ? route('marketplace.shops.cart', ['shopSlug' => $cartStore->slug]) : ($hasCartItems ? route('marketplace.stores') : $emptyCartUrl);
  $cartName = $cartStore?->name ?? ($hasCartItems ? __('cart.store_unavailable_short') : ($currentCartStore?->name ?? __('cart.empty_short')));
  $foreignCart = $hasCartItems && $currentCartStore && $cartOwner !== $currentCartStore->id;
  $cartBadgeLabel = __('cart.badge_label', ['count' => $cartCount, 'store' => $cartName]) . ($cartCounts['unidentified_lines'] ? '. ' . __('cart.unidentified') : '');
@endphp
<a class="zm-cart-link" data-cart-badge data-cart-owner="{{ $cartOwner }}" data-cart-current-store="{{ $currentCartStore?->id ?? 0 }}" data-cart-empty-url="{{ $emptyCartUrl }}" data-cart-empty-name="{{ $currentCartStore?->name ?? __('cart.empty_short') }}" href="{{ $cartHref }}" aria-label="{{ $cartBadgeLabel }}" title="{{ $cartBadgeLabel }}" @if(request()->routeIs('marketplace.shops.cart') && !$foreignCart) aria-current="page" @endif>
  <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 3h3l3 12h11l3-9H6M9 19a1 1 0 1 0 0 .01M19 19a1 1 0 1 0 0 .01"/></svg>
  <span class="zm-cart-count" data-cart-count aria-live="polite" aria-atomic="true">{{ $cartCount }}</span>
  <span class="zm-cart-origin" data-cart-origin @if(!$foreignCart) hidden @endif>{{ __('cart.foreign_badge') }}</span>
</a>
