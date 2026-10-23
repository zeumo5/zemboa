@extends('marketplace.merchant.layout')
@section('title', __('marketplace.storefront.categories_title'))
@section('content')
@php
  $categoryBanner = $settings?->banner_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($settings->banner_path)
      ? \Illuminate\Support\Facades\Storage::disk('public')->url($settings->banner_path) : null;
@endphp
<section class="merchant-section premium-categories">
  <header class="categories-hero @if($categoryBanner) categories-hero-with-image @endif">
    @if($categoryBanner)<img class="categories-banner" src="{{ $categoryBanner }}" alt="" decoding="async">@endif
    <div class="categories-hero-content"><nav class="categories-breadcrumb" aria-label="{{ __('marketplace.storefront.categories') }}"><a href="{{ route('marketplace.shops.show', ['shopSlug' => $store->slug]) }}">{{ $store->name }}</a><span aria-hidden="true">/</span><span>{{ __('marketplace.storefront.categories') }}</span></nav>
    <h1>{{ __('marketplace.storefront.categories_title') }}</h1><p>{{ __('marketplace.storefront.categories_intro') }}</p>
    @include('marketplace.merchant.discovery-navigation')</div>
  </header>
  <div class="premium-category-grid">
  @forelse($categories as $category)
    <article class="premium-category-card">
      <span class="category-symbol" aria-hidden="true">@include('marketplace.partials.category-icon')</span>
      <div class="category-card-content"><h2>{{ $category->name }}</h2>@if($category->description)<p class="category-description">{{ $category->description }}</p>@endif
      <p class="category-product-count">@include('marketplace.partials.category-icon')<span>{{ trans_choice('marketplace.storefront.category_product_count', $category->public_products_count, ['count' => $category->public_products_count]) }}</span></p>
      <a class="category-card-link" href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug, 'category' => $category->slug]) }}" aria-label="{{ __('marketplace.storefront.view_category') }} : {{ $category->name }}">{{ __('marketplace.storefront.view_category') }}<span aria-hidden="true">&rarr;</span></a></div>
      <span class="category-decoration" aria-hidden="true">@include('marketplace.partials.category-icon')</span>
    </article>
  @empty
    <p class="categories-empty">{{ __('marketplace.storefront.categories_empty') }}</p>
  @endforelse
  </div>
</section>
@endsection
