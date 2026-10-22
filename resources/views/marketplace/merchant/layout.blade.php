<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', $store->name) — {{ $store->name }}</title>
  <meta name="description" content="{{ \Illuminate\Support\Str::limit($store->description ?: $store->slogan ?: $store->name, 160) }}">
  <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
  $merchantColor = preg_match('/^#[0-9a-fA-F]{6}$/', $settings?->primary_color ?? '') ? $settings->primary_color : '#0F6B57';
  $navigationIcons = ['M3 10 12 3l9 7M5 9v12h5v-7h4v7h5V9','M5 7h14l2 14H3L5 7Zm3 0V5a4 4 0 0 1 8 0v2','M12 11v6m0-10v.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z','M1 4h13v13H1zM14 9h5l4 5v3h-9M8 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm13 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z'];
  $navigation = [
    ['marketplace.shops.show', 'marketplace.navigation_home'],
    ['marketplace.shops.products', 'marketplace.storefront.discover'],
    ['marketplace.shops.about', 'marketplace.storefront.about'],
    ['marketplace.shops.services', 'marketplace.storefront.services'],
  ];
@endphp
<body class="merchant-site" style="--merchant-color: {{ $merchantColor }}">
  <a class="zm-skip-link" href="#merchant-main">{{ __('marketplace.skip_to_content') }}</a>
  <header class="merchant-header">
    <a class="merchant-brand" href="{{ route('marketplace.shops.show', ['shopSlug' => $store->slug]) }}">
      @if($settings?->logo_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settings->logo_path) }}" width="52" height="52" alt="">@else<span class="merchant-monogram" aria-hidden="true">{{ mb_strtoupper(mb_substr(preg_replace('/^DEMO\s*-\s*/u', '', $store->name), 0, 1)) }}</span>@endif
      <span>{{ $store->name }}</span>
    </a>
    <form class="zm-search merchant-search" method="get" action="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug]) }}">
      <label class="zm-sr-only" for="merchant-q">{{ __('marketplace.storefront.search') }}</label><input id="merchant-q" type="search" name="q" maxlength="80" value="{{ $search ?? '' }}" placeholder="{{ __('marketplace.storefront.search') }}"><button type="submit">{{ __('marketplace.search_button') }}</button>
    </form>
    <div class="merchant-header-actions">
    @include('marketplace.partials.cart-link', ['merchantCartStore' => $store])
    <a class="merchant-explore" href="{{ route('marketplace.stores') }}">{{ __('marketplace.storefront.explore_stores') }}</a>
    <button class="merchant-menu-trigger" type="button" data-merchant-open aria-controls="merchant-drawer" aria-expanded="false" aria-label="{{ __('marketplace.storefront.open_nav') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <details class="merchant-language"><summary>{{ strtoupper(app()->getLocale()) }}</summary><div>
      @foreach(['fr', 'en'] as $locale)<form method="post" action="{{ auth()->guest() ? route('guest.locale.update') : route('account.locale.update') }}" data-merchant-locale>@csrf @auth @method('PATCH') @endauth<input type="hidden" name="locale" value="{{ $locale }}"><button type="submit" aria-label="{{ __('marketplace.storefront.language') }} {{ strtoupper($locale) }}">{{ strtoupper($locale) }}</button></form>@endforeach
    </div></details>
    </div>
  </header>
  <div class="merchant-shell">
    <aside class="merchant-sidebar" id="merchant-sidebar"><button class="merchant-sidebar-toggle" type="button" data-merchant-collapse aria-controls="merchant-sidebar-navigation" aria-expanded="true" aria-label="{{ __('marketplace.storefront.collapse_navigation') }}" data-collapse-label="{{ __('marketplace.storefront.collapse_navigation') }}" data-expand-label="{{ __('marketplace.storefront.expand_navigation') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m14 7-5 5 5 5"/></svg></button><p class="merchant-nav-caption">{{ __('marketplace.storefront.menu') }}</p><nav id="merchant-sidebar-navigation" aria-label="{{ __('marketplace.storefront.menu') }}">@include('marketplace.merchant.navigation')</nav><p class="merchant-sidebar-note">{{ $store->slogan ?: $store->name }}</p></aside>
    <main id="merchant-main" tabindex="-1">
      @if(app()->environment('demo'))<p class="zm-merchant-notice">{{ __('marketplace.storefront.demo') }}</p>@endif
      @yield('content')
    </main>
  </div>
  <dialog id="merchant-drawer" class="merchant-drawer" aria-labelledby="merchant-drawer-title">
    <div class="merchant-drawer-heading"><strong id="merchant-drawer-title">{{ $store->name }}</strong><button type="button" data-merchant-close aria-label="{{ __('marketplace.storefront.close_nav') }}">×</button></div>
    <nav aria-label="{{ __('marketplace.storefront.menu') }}">@include('marketplace.merchant.navigation')</nav>
    <a class="merchant-explore" href="{{ route('marketplace.stores') }}">{{ __('marketplace.storefront.explore_stores') }}</a>
  </dialog>
  @include('marketplace.merchant.footer')
@include('marketplace.partials.cart-config')
</body></html>
