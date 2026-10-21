@extends('marketplace.merchant.layout')
@section('title', __('marketplace.storefront.categories'))
@section('content')
<section class="merchant-section"><p class="merchant-eyebrow">{{ $store->name }}</p><h1>{{ __('marketplace.storefront.categories') }}</h1><p>{{ __('marketplace.storefront.categories_intro') }}</p>@include('marketplace.merchant.discovery-navigation')<div class="merchant-category-grid">@forelse($categories as $category)<a href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug, 'category' => $category->slug]) }}"><h2>{{ $category->name }}</h2>@if($category->description)<p>{{ $category->description }}</p>@endif<span aria-hidden="true">→</span></a>@empty<p class="merchant-empty-message">{{ __('marketplace.storefront.categories_empty') }}</p>@endforelse</div></section>
@endsection
