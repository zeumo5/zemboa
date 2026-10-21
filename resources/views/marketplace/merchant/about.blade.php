@extends('marketplace.merchant.layout')
@section('title', __('marketplace.storefront.about'))
@section('content')
<section class="merchant-section merchant-prose"><p class="merchant-eyebrow">{{ $store->name }}</p><h1>{{ __('marketplace.storefront.about') }}</h1><p class="zm-merchant-description">{{ $store->description ?: __('marketplace.store_description_default') }}</p><h2>{{ __('marketplace.storefront.contact') }}</h2>@if($store->address || $store->phone || $store->email)<address>@if($store->address)<p>{{ $store->address }}</p>@endif @if($store->phone)<p>{{ $store->phone }}</p>@endif @if($store->email)<p>{{ $store->email }}</p>@endif</address>@else<p class="merchant-empty-message">{{ __('marketplace.storefront.contact_unset') }}</p>@endif</section>
@endsection
