@extends('marketplace.merchant.layout')
@section('title', $store->name)
@section('content')
<section class="merchant-hero">
  @if($settings?->banner_path)<img class="merchant-banner" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settings->banner_path) }}" alt="">@endif
  <div><p>{{ __('marketplace.storefront.welcome', ['store' => $store->name]) }}</p><h1>{{ $store->name }}</h1>@if($store->slogan)<p>{{ $store->slogan }}</p>@endif<p>{{ \Illuminate\Support\Str::limit($store->description ?? '', 240) }}</p><a class="zm-btn zm-btn-light" href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug]) }}">{{ __('marketplace.storefront.explore') }} →</a></div>
</section>
<section class="merchant-section"><h2>{{ __('marketplace.store_catalog') }}</h2>@include('marketplace.merchant.catalog-list')</section>
@endsection
