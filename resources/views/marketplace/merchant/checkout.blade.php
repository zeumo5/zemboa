@extends('marketplace.merchant.layout')
@section('title', __('checkout.title'))
@section('content')
@php
  $service = $settings && ($fulfillment === 'PICKUP' ? $settings->pickup_enabled : ($settings->delivery_enabled && $zone));
  $canConfirm = $service && $summary['can_checkout'] && $token;
@endphp
<section class="merchant-section merchant-commerce commerce-checkout">
  <div class="checkout-layout">
    <div class="checkout-main">
      <header class="commerce-page-heading">
        <a class="cart-back" href="{{ route('marketplace.shops.cart', ['shopSlug' => $store->slug]) }}">@include('marketplace.partials.commerce-icon', ['icon' => 'arrow']){{ __('checkout.back') }}</a>
        <h1>{{ __('checkout.title') }}</h1><p class="commerce-intro">{{ __('checkout.notice') }}</p>
        @unless($buyer)<p class="commerce-guest">{{ __('checkout.guest') }} <a href="{{ route('buyer.login') }}">{{ __('buyer.login_title') }}</a></p>@endunless
      </header>
      @if($errors->any())<div class="commerce-errors" role="alert" tabindex="-1"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
      <div class="commerce-assurance checkout-assurance">@include('marketplace.partials.commerce-icon', ['icon' => 'shield'])<div><strong>{{ __('checkout.unpaid') }}</strong><span>{{ __('checkout.notice') }}</span></div></div>
      @unless($summary['can_checkout'])<p class="commerce-errors" role="status">{{ __('checkout.invalid_cart') }}</p>@endunless
      <section class="commerce-card">
        <div class="commerce-card-heading"><h2><span class="commerce-step">1</span>{{ __('checkout.articles') }}</h2><a class="commerce-text-action" href="{{ route('marketplace.shops.cart', ['shopSlug' => $store->slug]) }}">{{ __('checkout.edit_cart') }}</a></div>
        <ul class="checkout-items">@foreach($summary['details'] as $line)@include('marketplace.partials.checkout-line', ['compact' => false])@endforeach</ul>
      </section>
      <section class="commerce-card">
        <div class="commerce-card-heading"><h2><span class="commerce-step">2</span>{{ __('checkout.fulfillment') }}</h2></div>
        <form method="get" action="{{ route('marketplace.shops.checkout', ['shopSlug' => $store->slug]) }}" class="checkout-mode-form">
          <fieldset><legend class="zm-sr-only">{{ __('checkout.fulfillment') }}</legend><div class="checkout-modes">
            @if($settings?->pickup_enabled)<label class="checkout-mode" for="checkout-pickup"><input id="checkout-pickup" type="radio" name="fulfillment_type" value="PICKUP" @checked($fulfillment === 'PICKUP')><span class="commerce-icon-box">@include('marketplace.partials.commerce-icon', ['icon' => 'store'])</span><span><strong>{{ __('checkout.pickup') }}</strong><small>{{ __('checkout.pickup_help') }}</small></span></label>@endif
            @if($settings?->delivery_enabled && $zones->isNotEmpty())<label class="checkout-mode" for="checkout-delivery"><input id="checkout-delivery" type="radio" name="fulfillment_type" value="DELIVERY" @checked($fulfillment === 'DELIVERY')><span class="commerce-icon-box">@include('marketplace.partials.commerce-icon', ['icon' => 'truck'])</span><span><strong>{{ __('checkout.delivery') }}</strong><small>{{ __('checkout.delivery_help') }}</small></span></label>@endif
          </div></fieldset>
          @if($settings?->delivery_enabled && $zones->isNotEmpty())<div class="zm-field"><label for="checkout-zone">{{ __('checkout.zone') }}</label><select id="checkout-zone" name="delivery_zone_id">@foreach($zones as $candidate)<option value="{{ $candidate->id }}" @selected($candidate->id === $zone?->id)>{{ $candidate->name }} — {{ $candidate->city }} · {{ number_format((float) $candidate->fee, 0, ',', ' ') }} FCFA</option>@endforeach</select></div>@endif
          @if(!$service || ($settings?->pickup_enabled && $settings?->delivery_enabled && $zones->isNotEmpty()) || ($settings?->delivery_enabled && $zones->count() > 1))
            <div class="checkout-mode-actions"><p class="commerce-muted">{{ __('checkout.mode_help') }}</p><button class="zm-btn commerce-secondary" type="submit" @disabled(!$settings || (!$settings->pickup_enabled && !$settings->delivery_enabled))>{{ __('checkout.refresh') }}</button></div>
          @endif
        </form>
        @unless($service)<p class="commerce-errors" role="status">{{ __('checkout.no_service') }}</p>@endunless
      </section>
      <section class="commerce-card">
        <div class="commerce-card-heading"><h2><span class="commerce-step">3</span>{{ __('checkout.contact') }}</h2></div>
        @if($canConfirm)
        <form id="merchant-checkout-confirm" method="post" action="{{ route('marketplace.shops.checkout.store', ['shopSlug' => $store->slug]) }}" class="checkout-contact-form">
          @csrf<input type="hidden" name="checkout_token" value="{{ $token }}"><input type="hidden" name="fulfillment_type" value="{{ $fulfillment }}">
          @if($fulfillment === 'DELIVERY')<input type="hidden" name="delivery_zone_id" value="{{ $zone->id }}">@endif
          <div class="checkout-fields">
          @foreach(['customer_name' => ['checkout.name', 'text', 'name', 150, $buyer?->name], 'customer_phone' => ['checkout.phone', 'tel', 'tel', 64, $buyer?->phone], 'customer_email' => ['checkout.email', 'email', 'email', 150, $buyer?->email]] as $field => [$label, $type, $autocomplete, $max, $value])
            <div class="zm-field @if($field === 'customer_email') checkout-field-wide @endif"><label for="{{ $field }}">{{ __($label) }}@if($field !== 'customer_email')<span class="commerce-required" aria-hidden="true"> *</span>@endif</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" value="{{ old($field, $value) }}" @required($field !== 'customer_email') @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>@error($field)<span class="commerce-field-error" id="{{ $field }}-error">{{ $message }}</span>@enderror</div>
          @endforeach
          @if($fulfillment === 'DELIVERY')
            @foreach(['delivery_address' => ['checkout.address', 500], 'delivery_area' => ['checkout.area', 150], 'delivery_instructions' => ['checkout.instructions', 1000]] as $field => [$label, $max])
            <div class="zm-field checkout-field-wide"><label for="{{ $field }}">{{ __($label) }}</label><textarea id="{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" @required($field === 'delivery_address') @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>{{ old($field) }}</textarea>@error($field)<span class="commerce-field-error" id="{{ $field }}-error">{{ $message }}</span>@enderror</div>
            @endforeach
          @endif
          </div><p class="commerce-muted">{{ __('checkout.contact_help') }}</p>
        </form>
        @else<p class="commerce-card-body commerce-muted">{{ __('checkout.invalid_cart') }}</p>@endif
      </section>
    </div>
    <aside class="checkout-aside" aria-label="{{ __('checkout.summary') }}">
      <a class="commerce-merchant-card" href="{{ route('marketplace.shops.show', ['shopSlug' => $store->slug]) }}">
        @if($settings?->logo_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settings->logo_path) }}" width="50" height="50" alt="">@else<span class="commerce-merchant-letter">{{ mb_strtoupper(mb_substr(preg_replace('/^DEMO\s*-\s*/u', '', $store->name), 0, 1)) }}</span>@endif
        <span><strong>{{ $store->name }}</strong>@if($store->slogan)<small>{{ $store->slogan }}</small>@endif</span>
        @include('marketplace.partials.commerce-icon', ['icon' => 'store'])
      </a>
      <section class="commerce-card checkout-summary">
        <h2><span class="commerce-icon-box">@include('marketplace.partials.commerce-icon', ['icon' => 'cart'])</span>{{ __('checkout.summary') }}</h2>
        <ul class="checkout-summary-items">@foreach($summary['details'] as $line)@include('marketplace.partials.checkout-line', ['compact' => true])@endforeach</ul>
        <dl class="commerce-totals"><div><dt>{{ __('checkout.subtotal') }}</dt><dd>{{ number_format((float) $total['subtotal'] - (float) $total['discount_amount'], 0, ',', ' ') }} FCFA</dd></div><div><dt>{{ __('checkout.fee') }}</dt><dd>{{ number_format((float) $total['delivery_fee'], 0, ',', ' ') }} FCFA</dd></div></dl>
        <div class="commerce-grand-total"><span>{{ __('checkout.total') }}</span><strong>{{ number_format((float) $total['total'], 0, ',', ' ') }} FCFA</strong></div>
      </section>
      <div class="commerce-information"><strong>{{ __('checkout.important') }}</strong><p>{{ __('checkout.notice') }}</p></div>
      <button class="zm-btn commerce-primary checkout-confirm" type="submit" form="merchant-checkout-confirm" @disabled(!$canConfirm)>@include('marketplace.partials.commerce-icon', ['icon' => 'lock']){{ __('checkout.confirm') }}</button>
      <p class="commerce-muted checkout-payment-note">{{ __('checkout.unpaid') }}</p>
    </aside>
  </div>
</section>
@endsection
