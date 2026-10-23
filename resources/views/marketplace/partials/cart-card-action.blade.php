@if((int) $product->cart_available_count < 1)
  <p class="cart-card-unavailable" data-cart-unavailable="{{ $product->id }}">{{ __('cart.unavailable') }}</p>
@elseif((int) $product->cart_variant_count === 1)
  <form data-cart-form data-cart-direct="{{ $product->id }}" method="post" action="{{ route('public.shops.cart.store', ['shopSlug' => $product->store_slug]) }}" class="cart-card-action">
    @csrf<input type="hidden" name="product_variant_id" value="{{ $product->cart_variant_id }}"><input type="hidden" name="quantity" value="1">
    <button type="submit" class="zm-btn zm-btn-dark" aria-label="{{ __('cart.add') }} : {{ $product->name }}">@if($showCartIcon ?? false) @include('marketplace.partials.commerce-icon', ['icon' => 'cart']) @endif{{ __('cart.add') }}</button>
    <p data-cart-status role="status" aria-live="polite"></p><noscript>{{ __('cart.javascript') }}</noscript>
  </form>
@else
  <a class="zm-btn zm-btn-dark cart-card-action" data-cart-options="{{ $product->id }}" href="{{ route('marketplace.products.show', ['shopSlug' => $product->store_slug, 'product' => $product->id]) }}">@if($showCartIcon ?? false) @include('marketplace.partials.commerce-icon', ['icon' => 'cart']) @endif{{ __('cart.choose_options') }}</a>
@endif
