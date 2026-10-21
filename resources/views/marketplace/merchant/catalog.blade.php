@extends('marketplace.merchant.layout')
@section('title', __('marketplace.storefront.discover_title'))
@section('content')
<section class="merchant-section"><p class="merchant-eyebrow">{{ $store->name }}</p><h1>{{ __('marketplace.storefront.discover_title') }}</h1>@include('marketplace.merchant.discovery-navigation')@include('marketplace.merchant.catalog-list')</section>
@endsection
