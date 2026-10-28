<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex, nofollow">
  <title>@yield('title', __('customer.title')) — ZEMBOA</title>
  <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="customer-site">
  <a class="zm-skip-link" href="#customer-main">{{ __('marketplace.skip_to_content') }}</a>
  <header class="customer-header">
    <a class="customer-brand" href="{{ route('marketplace.home') }}"><img src="{{ asset('marketplace/zemboa-mark.svg') }}" width="40" height="40" alt=""><span>ZEMBOA<span class="customer-brand-dot">.</span></span></a>
    <nav class="customer-platform-nav" aria-label="{{ __('marketplace.main_navigation') }}"><a href="{{ route('marketplace.home') }}">{{ __('marketplace.navigation_home') }}</a><a href="{{ route('marketplace.products') }}">{{ __('marketplace.navigation_products') }}</a><a href="{{ route('marketplace.stores') }}">{{ __('marketplace.navigation_stores') }}</a><a href="{{ route('buyer.account') }}" aria-current="true">{{ __('buyer.space') }}</a></nav>
    @include('marketplace.partials.cart-link')
    <details class="customer-account-menu"><summary>{{ __('customer.account_menu') }} <span aria-hidden="true">⌄</span></summary><div>@include('buyer.navigation')<form method="post" action="{{ route('buyer.logout') }}">@csrf<button type="submit">{{ __('buyer.logout') }}</button></form></div></details>
  </header>
  <div class="customer-shell">
    <aside class="customer-sidebar"><p>{{ __('customer.my_space') }}</p><nav aria-label="{{ __('customer.navigation') }}">@include('buyer.navigation')</nav><a class="customer-explore" href="{{ route('marketplace.stores') }}">{{ __('buyer.explore') }} <span aria-hidden="true">↗</span></a><p class="customer-scope">{{ __('customer.scope') }}</p></aside>
    <main id="customer-main" tabindex="-1"><nav class="customer-breadcrumb" aria-label="{{ __('buyer.space') }}"><a href="{{ route('marketplace.home') }}">ZEMBOA</a><span>/</span><a href="{{ route('buyer.account') }}">{{ __('buyer.space') }}</a><span>/</span><span>@yield('title')</span></nav>@if(auth('web')->check())<div class="workspace-preview"><p>{{ __('access.both') }}</p><p>{{ __('access.shopping_blocked') }}</p>@include('marketplace.partials.account-links')</div>@endif @yield('content')<nav class="customer-mobile-nav" aria-label="{{ __('customer.navigation') }}">@include('buyer.navigation')</nav></main>
  </div>
  <footer class="customer-footer">© {{ date('Y') }} ZEMBOA · <a href="{{ route('marketplace.stores') }}">{{ __('buyer.explore') }}</a></footer>
@include('marketplace.partials.cart-config')
</body></html>
