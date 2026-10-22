<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F6B57">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ZEMBOA') — ZEMBOA</title>
    <meta name="description" content="{{ __('marketplace.tagline') }}">
   @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body id="top" tabindex="-1">
  <a class="zm-skip-link" href="#main-content">{{ __('marketplace.skip_to_content') }}</a>
  <div class="zm-announcement"><div class="zm-container zm-announcement-inner"><span><span class="zm-dot"></span>{{ __('marketplace.top_note') }}</span><span class="zm-announcement-right">ZEMBOA <span class="zm-announcement-sep">/</span> CAMEROON</span></div></div>
  <header class="zm-header">
    <div class="zm-container zm-nav-shell">
      <a class="zm-brand" href="{{ route('marketplace.home') }}" aria-label="ZEMBOA — {{ __('marketplace.back_to_marketplace') }}">
        <span class="zm-brand-symbol" aria-hidden="true"><img src="{{ asset('marketplace/zemboa-mark.svg') }}" width="42" height="42" alt=""></span><span class="zm-brand-text">ZEMBOA<span>.</span></span>
      </a>
      <button class="zm-menu-toggle" type="button" aria-controls="zm-main-nav" aria-expanded="false" aria-label="{{ __('marketplace.main_navigation') }}">
        <span class="zm-menu-bars" aria-hidden="true"><span></span><span></span><span></span></span>
        <span class="zm-menu-label">{{ __('marketplace.menu') }}</span>
      </button>
      <nav id="zm-main-nav" class="zm-nav" aria-label="{{ __('marketplace.main_navigation') }}">
        <a href="{{ route('marketplace.home') }}" @if(request()->routeIs('marketplace.home')) aria-current="page" @endif>{{ __('marketplace.navigation_home') }}</a>
        <a href="{{ route('marketplace.products') }}" @if(request()->routeIs('marketplace.products', 'marketplace.products.*')) aria-current="page" @endif>{{ __('marketplace.navigation_products') }}</a>
        <a href="{{ route('marketplace.stores') }}" @if(request()->routeIs('marketplace.stores', 'marketplace.shops.*')) aria-current="page" @endif>{{ __('marketplace.navigation_stores') }}</a>
        <a href="{{ route(auth('buyer')->check() ? 'buyer.account' : 'buyer.login') }}" @if(request()->routeIs('buyer.*')) aria-current="page" @endif>{{ __('buyer.space') }}</a>
      </nav>
      <div class="zm-nav-right">
        <details class="zm-header-search">
          <summary aria-label="{{ __('marketplace.global_search') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></summary>
          <form class="zm-search zm-header-search-form" method="get" action="{{ route('marketplace.products') }}">
            <label class="zm-sr-only" for="header-search-q">{{ __('marketplace.global_search') }}</label><input id="header-search-q" type="search" name="q" maxlength="80" placeholder="{{ __('marketplace.global_search') }}"><button type="submit">{{ __('marketplace.search_button') }}</button>
          </form>
        </details>
        <div class="zm-language" aria-label="{{ __('marketplace.language_label') }}">
          <button type="button" data-locale="fr" class="{{ app()->getLocale() === 'fr' ? 'active' : '' }}" aria-pressed="{{ app()->getLocale() === 'fr' ? 'true' : 'false' }}">FR</button>
          <span>/</span>
          <button type="button" data-locale="en" class="{{ app()->getLocale() === 'en' ? 'active' : '' }}" aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}">EN</button>
        </div>
        @guest
          <a href="{{ route('login') }}" class="zm-nav-login" aria-label="{{ __('marketplace.pro_space') }}">{{ __('marketplace.pro_space') }} <span aria-hidden="true">↗</span></a>
        @endguest
        @auth
          <a href="{{ route('account.show') }}" class="zm-nav-login" aria-label="{{ __('marketplace.my_account') }}">{{ __('marketplace.my_account') }} <span aria-hidden="true">↗</span></a>
        @endauth
      </div>
    </div>
  </header>
  <main id="main-content" tabindex="-1">@yield('content')</main>
  <footer class="zm-footer">
    <div class="zm-container zm-footer-top">
      <div><a class="zm-brand zm-brand-footer" href="{{ route('marketplace.home') }}"><span class="zm-brand-symbol" aria-hidden="true"><img src="{{ asset('marketplace/zemboa-mark.svg') }}" width="42" height="42" alt=""></span><span class="zm-brand-text">ZEMBOA<span>.</span></span></a><p>{{ __('marketplace.footer_pitch') }}</p></div>
      <div class="zm-footer-links"><div><strong>{{ __('marketplace.explore') }}</strong><a href="{{ route('marketplace.home') }}">{{ __('marketplace.navigation_home') }}</a><a href="{{ route('marketplace.products') }}">{{ __('marketplace.navigation_products') }}</a><a href="{{ route('marketplace.stores') }}">{{ __('marketplace.navigation_stores') }}</a></div><div><strong>{{ __('marketplace.platform') }}</strong><a href="{{ route('login') }}">{{ __('marketplace.pro_space') }}</a><span>{{ __('marketplace.footer_location') }}</span></div></div>
    </div>
    <div class="zm-container zm-footer-bottom"><span>© {{ date('Y') }} ZEMBOA. {{ __('marketplace.all_rights') }}</span><span>{{ __('marketplace.footer_info') }}</span><a class="zm-back-top" href="#top">{{ __('marketplace.back_to_top') }} <span aria-hidden="true">↑</span></a></div>
  </footer>
  <script>
  document.querySelectorAll('[data-locale]').forEach(button => {
    button.addEventListener('click', async () => {
      const locale = button.dataset.locale;
      if (button.disabled || button.classList.contains('active')) return;
      button.disabled = true;
      try {
        const isGuest = @json(auth()->guest());
        const endpoint = isGuest ? @json(route('guest.locale.update')) : @json(route('account.locale.update'));
        const response = await fetch(endpoint, {
          method: isGuest ? 'POST' : 'PATCH',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
          body: JSON.stringify({locale})
        });
        if (!response.ok) throw new Error('Locale request failed');
        window.location.reload();
      } catch (_) { button.disabled = false; }
    });
  });
  </script>
</body>
</html>
