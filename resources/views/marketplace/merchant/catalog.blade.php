@extends('marketplace.merchant.layout')
@section('title', __('marketplace.storefront.discover_title'))
@section('content')
<section class="merchant-section premium-catalog">
  @php($bannerUrl = \App\Support\StoreBranding::url($settings,'banner'))
  <header class="catalog-intro @if($bannerUrl) catalog-intro-with-image @endif">
    @if($bannerUrl)<img class="catalog-banner" src="{{ $bannerUrl }}" alt="" decoding="async">@endif
    <div class="catalog-intro-content"><a class="catalog-breadcrumb" href="{{ route('marketplace.shops.show', ['shopSlug' => $store->slug]) }}">{{ $store->name }}</a><h1>{{ __('marketplace.storefront.discover_title') }}</h1>@if($store->slogan || $store->description)<p>{{ \Illuminate\Support\Str::limit($store->slogan ?: $store->description, 150) }}</p>@endif
    @include('marketplace.merchant.discovery-navigation')</div>
  </header>
  @include('marketplace.merchant.catalog-list')
</section>
@endsection
