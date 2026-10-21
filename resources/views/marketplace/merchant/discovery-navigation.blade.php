<nav class="merchant-discovery-tabs" aria-label="{{ __('marketplace.storefront.discovery_navigation') }}">
  <a href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug]) }}" @if(request()->routeIs('marketplace.shops.products')) aria-current="page" @endif>{{ __('marketplace.storefront.all_products') }}</a>
  <a href="{{ route('marketplace.shops.categories', ['shopSlug' => $store->slug]) }}" @if(request()->routeIs('marketplace.shops.categories')) aria-current="page" @endif>{{ __('marketplace.storefront.categories') }}</a>
</nav>
