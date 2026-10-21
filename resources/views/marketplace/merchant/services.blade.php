@extends('marketplace.merchant.layout')
@section('title', __('marketplace.storefront.services'))
@section('content')
@php
  $publishedServices = $settings && ($settings->delivery_enabled || $settings->pickup_enabled || $settings->allow_reservations || $settings->allow_partial_payments || ($settings->returns_enabled && $settings->return_window_days > 0));
  $publishedHours = collect($settings?->opening_hours ?? [])->filter(fn ($hours) => !empty($hours['open']) && !empty($hours['close']));
@endphp
<section class="merchant-section merchant-prose"><p class="merchant-eyebrow">{{ $store->name }}</p><h1>{{ __('marketplace.storefront.services') }}</h1>
@if($publishedServices)
<ul class="zm-merchant-services">
@if($settings->delivery_enabled)<li>{{ __('marketplace.storefront.delivery') }}</li>@endif
@if($settings->pickup_enabled)<li>{{ __('marketplace.storefront.pickup') }}</li>@endif
@if($settings->allow_reservations)<li>{{ __('marketplace.storefront.reservation') }}</li>@endif
@if($settings->allow_partial_payments)<li>{{ __('marketplace.storefront.deposit', ['percent' => $settings->minimum_deposit_percent]) }}</li>@endif
@if($settings->returns_enabled && $settings->return_window_days > 0)<li>{{ __('marketplace.storefront.returns', ['days' => $settings->return_window_days]) }}</li>@endif
</ul>
<p>{{ __('marketplace.storefront.policy') }}</p>
@else<p>{{ __('marketplace.storefront.unset') }}</p>@endif
@if($publishedHours->isNotEmpty())<h2>{{ __('marketplace.storefront.hours') }}</h2><dl class="merchant-hours">@foreach($publishedHours as $day => $hours)<div><dt>{{ __('marketplace.storefront.days.'.$day) }}</dt><dd>{{ $hours['open'] }} – {{ $hours['close'] }}</dd></div>@endforeach</dl>@endif
@if($store->address)<address>{{ $store->address }}</address>@endif
<p>{{ __('marketplace.storefront.payments') }}</p>
</section>
@endsection
