@extends('marketplace.layout')
@section('title', __('marketplace.products_directory'))
@section('content')
<section class="zm-page-hero">
  <div class="zm-container">
    <a class="zm-back" href="{{ route('marketplace.home') }}">← {{ __('marketplace.back_to_marketplace') }}</a>
    <span class="zm-page-eyebrow">ZEMBOA / {{ __('marketplace.navigation_products') }}</span>
    <h1>{{ __('marketplace.products_directory') }}<span class="zm-title-dot">.</span></h1>
    <p>{{ __('marketplace.products_intro') }}</p>
    <span class="zm-page-hero-watermark" aria-hidden="true">Z</span>
  </div>
</section>
<section class="zm-section zm-directory-section">
  <div class="zm-container">
    <form class="zm-search zm-search-page" data-zm-search method="get" action="{{ route('marketplace.products') }}">
      <label class="zm-sr-only" for="products-q">{{ __('marketplace.search') }}</label>
      <input id="products-q" type="search" name="q" maxlength="80" value="{{ $search }}" placeholder="{{ __('marketplace.search') }}">
      <button type="submit">{{ __('marketplace.search_button') }} →</button>
    </form>
    @if($search !== '')
      <div class="zm-search-feedback" role="status" aria-live="polite">
        <span>{{ __('marketplace.search_results_for') }} <strong>« {{ $search }} »</strong> · {{ $products->total() }} {{ __('marketplace.results') }}</span>
        <a href="{{ route('marketplace.products') }}">{{ __('marketplace.clear_search') }} <span aria-hidden="true">×</span></a>
      </div>
    @endif
    @if($products->count() === 0)
      <div class="zm-empty-state zm-empty-products">
        <div class="zm-empty-icon" aria-hidden="true">◈</div>
        <div>
          <span class="zm-empty-eyebrow">ZEMBOA MARKETPLACE</span>
          <h3>{{ __('marketplace.empty_products_title') }}</h3>
          <p>{{ __('marketplace.no_products') }}</p>
        </div>
        <a href="{{ route('marketplace.stores') }}" class="zm-empty-link">{{ __('marketplace.explore_stores') }} ↗</a>
      </div>
    @else
      <div class="zm-products-grid">
        @foreach($products as $product)
          @include('marketplace.components.product-card')
        @endforeach
      </div>
      <div class="zm-pagination">{{ $products->links() }}</div>
    @endif
  </div>
</section>
@endsection
