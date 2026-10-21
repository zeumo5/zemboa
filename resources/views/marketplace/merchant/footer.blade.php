@php
  // Keep merchant accents only when text remains AA-readable on both footer surfaces.
  $footerLuminance = static function (string $hex): float {
      $channels = array_map(static function ($channel) {
          $value = hexdec($channel) / 255;
          return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
      }, str_split(ltrim($hex, '#'), 2));
      return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
  };
  $footerInk = ($footerLuminance('#F1F5F9') + 0.05) / ($footerLuminance($merchantColor) + 0.05) >= 4.5
      ? $merchantColor : '#0F6B57';
@endphp
<footer class="merchant-footer" style="--merchant-footer-ink: {{ $footerInk }}">
  <div class="merchant-footer-identity">
    <strong>{{ $store->name }}</strong>
    @if($store->address)<p>{{ $store->address }}</p>@endif
    <p>© {{ date('Y') }} {{ $store->name }}</p>
  </div>
  <div class="merchant-footer-actions">
    <nav class="merchant-footer-nav" aria-label="{{ __('marketplace.storefront.footer_navigation') }}">
      <a class="merchant-footer-link" href="{{ route('marketplace.shops.about', ['shopSlug' => $store->slug]) }}" @if(request()->routeIs('marketplace.shops.about')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.01"/></svg>
        <span>{{ __('marketplace.storefront.about') }}</span>
      </a>
      <a class="merchant-footer-link" href="{{ route('marketplace.shops.services', ['shopSlug' => $store->slug]) }}" @if(request()->routeIs('marketplace.shops.services')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 5h12v12H2zM14 10h4l4 4v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>
        <span>{{ __('marketplace.storefront.services') }}</span>
      </a>
    </nav>
    <div class="merchant-footer-platform">
      <small>{{ __('marketplace.storefront.powered') }}</small>
      <a class="merchant-footer-link merchant-footer-back" href="{{ route('marketplace.home') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 6-6 6 6 6M4 12h16"/></svg>
        <span>{{ __('marketplace.storefront.back') }}</span>
      </a>
    </div>
  </div>
</footer>
