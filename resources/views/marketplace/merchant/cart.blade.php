@extends('marketplace.merchant.layout')
@section('title', __('cart.title'))
@section('content')
<section class="merchant-section">
  @include('marketplace.partials.cart-back')
  <div class="cart-heading"><h1>{{ __('cart.title') }}</h1>@include('marketplace.partials.cart-link', ['merchantCartStore' => $store])</div><p>{{ __('cart.session_note') }}</p>
  @if($otherStore)
    <p>{{ __('cart.other_store') }} <a href="{{ route('marketplace.shops.cart', ['shopSlug' => $otherStore->slug]) }}">{{ $otherStore->name }}</a></p>
  @elseif($hasForeignCart)
    <p>{{ __('cart.store_unavailable') }}</p>
  @endif
  <div data-cart-page data-clear-url="{{ route('public.shops.cart.clear', ['shopSlug' => $store->slug]) }}" data-url="{{ route('public.shops.cart.index', ['shopSlug' => $store->slug]) }}" data-items-url="{{ route('public.shops.cart.store', ['shopSlug' => $store->slug]) }}">
    <p data-cart-status role="status" aria-live="polite">{{ __('cart.loading') }}</p>
    <div class="cart-columns"><div data-cart-items></div><aside class="cart-summary" data-cart-summary aria-label="{{ __('cart.summary') }}" hidden>
      <h2>{{ __('cart.summary') }}</h2><p><span>{{ __('cart.products') }}</span><strong data-cart-products>0</strong></p>
      <p><span>{{ __('cart.units') }}</span><strong data-cart-units>0</strong></p>
      <p class="cart-total"><span>{{ __('cart.subtotal') }}</span><strong data-cart-total></strong></p>
      <p data-cart-unidentified hidden>{{ __('cart.unidentified') }}</p>
    </aside></div>
    <div class="cart-page-actions"><a class="zm-btn zm-btn-light" href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug]) }}">{{ __('cart.continue') }}</a>
      <button type="button" class="zm-btn cart-clear-button" data-cart-clear hidden><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>{{ __('cart.clear') }}</button>
    </div>
    <dialog class="cart-clear-dialog" data-cart-clear-dialog aria-labelledby="cart-clear-title" aria-describedby="cart-clear-description">
      <h2 id="cart-clear-title">{{ __('cart.clear_confirm_title') }}</h2><p id="cart-clear-description">{{ __('cart.clear_confirm_description') }}</p>
      <div class="cart-page-actions"><button type="button" class="zm-btn zm-btn-light" data-cart-clear-cancel autofocus>{{ __('cart.cancel') }}</button><button type="button" class="zm-btn cart-clear-button" data-cart-clear-confirm>{{ __('cart.clear_confirm') }}</button></div>
    </dialog>
    <noscript><p>{{ __('cart.javascript') }}</p></noscript>
  </div>
</section>
@endsection
