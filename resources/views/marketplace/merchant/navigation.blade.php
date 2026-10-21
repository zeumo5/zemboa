@foreach($navigation as $index => [$routeName, $label])
  @php($active = request()->routeIs($routeName) || ($routeName === 'marketplace.shops.products' && request()->routeIs('marketplace.products.show', 'marketplace.shops.categories')))
  <a class="merchant-nav-link" title="{{ __($label) }}" href="{{ route($routeName, ['shopSlug' => $store->slug]) }}" @if($active) aria-current="page" @endif>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $navigationIcons[$index] }}"/></svg>
    <span class="merchant-nav-label">{{ __($label) }}</span>@if($active)<span class="merchant-nav-dot" aria-hidden="true"></span>@endif
  </a>
@endforeach
