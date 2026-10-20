@extends('marketplace.layout')
@section('title', __('marketplace.home_page_title'))
@section('content')
<section class="zm-hero">
  <div class="zm-container zm-hero-grid">
    <div class="zm-hero-content">
      <div class="zm-hero-eyebrow"><span class="zm-hero-eyebrow-mark"></span>{{ __('marketplace.hero_badge') }}</div>
      <h1>{{ __('marketplace.hero_line1') }}<br><span>{{ __('marketplace.hero_line2') }}</span></h1>
      <p class="zm-hero-subtitle">{{ __('marketplace.hero_description') }}</p>
      <div class="zm-hero-buttons"><a class="zm-btn zm-btn-lime" href="{{ route('marketplace.stores') }}">{{ __('marketplace.explore_stores') }} <span aria-hidden="true">↗</span></a><a class="zm-hero-text-link" href="#products">{{ __('marketplace.explore_products') }} <span aria-hidden="true">→</span></a></div>
      <div class="zm-hero-facts"><span><span class="zm-fact-icon">✓</span>{{ __('marketplace.fact_stores') }}</span><span><span class="zm-fact-icon">✓</span>{{ __('marketplace.fact_prices') }}</span></div>
    </div>
    <div class="zm-hero-visual" aria-hidden="true">
      <div class="zm-visual-glow"></div>
      <div class="zm-hero-visual-top"><span>THE NEW LOCAL</span><span>001 — CM</span></div>
      <div class="zm-visual-main"><div class="zm-visual-outline">Z</div><div class="zm-visual-spotlight"></div><div class="zm-visual-monogram">Z<span>.</span></div></div>
      <div class="zm-hero-visual-bottom"><span>ZEMBOA <span class="zm-visual-line"></span> {{ __('marketplace.discovery') }}</span><span class="zm-visual-small-arrow">↗</span></div>
      <div class="zm-floating-tag">{{ __('marketplace.hero_art_label') }} <span aria-hidden="true">✦</span></div>
    </div>
  </div>
</section>

<section class="zm-search-strip" aria-label="{{ __('marketplace.search') }}"><div class="zm-container zm-search-strip-inner"><div class="zm-search-label"><span class="zm-search-label-icon">⌕</span><span>{{ __('marketplace.find_something') }}</span></div><form class="zm-search" data-zm-search action="{{ route('marketplace.home') }}" method="get"><label class="zm-sr-only" for="home-q">{{ __('marketplace.search') }}</label><input id="home-q" type="search" name="q" maxlength="80" value="{{ $search }}" placeholder="{{ __('marketplace.search') }}"><button type="submit">{{ __('marketplace.search_button') }} <span aria-hidden="true">→</span></button></form></div></section>

<section class="zm-discover zm-section"><div class="zm-container"><div class="zm-section-heading"><div><div class="zm-section-kicker"><span class="zm-kicker-line"></span>{{ __('marketplace.discover_eyebrow') }}</div><h2>{{ __('marketplace.discover_title') }}</h2><p>{{ __('marketplace.discover_description') }}</p></div></div><div class="zm-editorial-grid"><div class="zm-editorial-card zm-edit-fashion"><span class="zm-editorial-index">01 — ZEMBOA</span><div class="zm-editorial-art zm-fashion-art">✧</div><div class="zm-editorial-bottom"><strong>{{ __('marketplace.discover_fashion') }}</strong><span>↗</span></div></div><div class="zm-editorial-card zm-edit-tech"><span class="zm-editorial-index">02 — ZEMBOA</span><div class="zm-editorial-art zm-tech-art"><div></div></div><div class="zm-editorial-bottom"><strong>{{ __('marketplace.discover_tech') }}</strong><span>↗</span></div></div><div class="zm-editorial-card zm-edit-home"><span class="zm-editorial-index">03 — ZEMBOA</span><div class="zm-editorial-art zm-home-art">◒</div><div class="zm-editorial-bottom"><strong>{{ __('marketplace.discover_home') }}</strong><span>↗</span></div></div></div></div></section>

<section class="zm-section zm-store-section" id="stores"><div class="zm-container"><div class="zm-section-heading zm-section-heading-split"><div><div class="zm-section-kicker"><span class="zm-kicker-line"></span>01 / {{ __('marketplace.navigation_stores') }}</div><h2>{{ __('marketplace.shops_title') }}</h2><p>{{ __('marketplace.shops_intro') }}</p></div><a class="zm-see-all" href="{{ route('marketplace.stores') }}">{{ __('marketplace.shops_directory') }} <span aria-hidden="true">↗</span></a></div>
@if($stores->isEmpty())
  <div class="zm-empty-state"><div class="zm-empty-icon"><span aria-hidden="true">Z.</span></div><div><span class="zm-empty-eyebrow">ZEMBOA MARKETPLACE</span><h3>{{ __('marketplace.empty_stores_title') }}</h3><p>{{ __('marketplace.empty_stores_desc') }}</p></div><a class="zm-empty-link" href="#products">{{ __('marketplace.explore_products') }} ↗</a></div>
@else
  <div class="zm-stores-grid">@foreach($stores as $store) @include('marketplace.components.store-card') @endforeach</div>
@endif
</div></section>

<section class="zm-section zm-product-section" id="products"><div class="zm-container"><div class="zm-section-heading zm-section-heading-split"><div><div class="zm-section-kicker"><span class="zm-kicker-line"></span>02 / {{ __('marketplace.navigation_products') }}</div><h2>{{ __('marketplace.products_title') }}</h2><p>{{ __('marketplace.products_intro') }}</p></div>@if($search !== '')<a class="zm-see-all" href="{{ route('marketplace.home') }}#products">{{ __('marketplace.clear_search') }} ×</a>@endif</div>
@if($search !== '')
  <div class="zm-search-feedback" role="status" aria-live="polite">
    <span>{{ __('marketplace.search_results_for') }} <strong>« {{ $search }} »</strong> · {{ $products->total() }} {{ __('marketplace.results') }}</span>
    <a href="{{ route('marketplace.home') }}#products">{{ __('marketplace.clear_search') }} <span aria-hidden="true">×</span></a>
  </div>
@endif
@if($products->count() === 0)
  @if(app()->environment('local') && $search === '')
    <div class="zm-preview-banner"><div><span class="zm-preview-badge">{{ __('marketplace.preview_badge') }}</span><h3>{{ __('marketplace.preview_title') }}</h3><p>{{ __('marketplace.preview_description') }}</p></div><span class="zm-preview-watermark" aria-hidden="true">Z</span></div>
    <div class="zm-preview-grid" aria-label="{{ __('marketplace.preview_badge') }}">
      <article class="zm-preview-product"><div class="zm-preview-image zm-prev-1"><span class="zm-preview-category">LIFESTYLE</span><div class="zm-prev-shape zm-prev-shape-1"></div><span class="zm-prev-watermark">01</span></div><div class="zm-preview-body"><span>{{ __('marketplace.demo_item1_cat') }}</span><h3>{{ __('marketplace.demo_item1') }}</h3><p>{{ __('marketplace.demo_not_for_sale') }}</p></div></article>
      <article class="zm-preview-product"><div class="zm-preview-image zm-prev-2"><span class="zm-preview-category">TECH</span><div class="zm-prev-shape zm-prev-shape-2"></div><span class="zm-prev-watermark">02</span></div><div class="zm-preview-body"><span>{{ __('marketplace.demo_item2_cat') }}</span><h3>{{ __('marketplace.demo_item2') }}</h3><p>{{ __('marketplace.demo_not_for_sale') }}</p></div></article>
      <article class="zm-preview-product"><div class="zm-preview-image zm-prev-3"><span class="zm-preview-category">DESIGN</span><div class="zm-prev-shape zm-prev-shape-3"></div><span class="zm-prev-watermark">03</span></div><div class="zm-preview-body"><span>{{ __('marketplace.demo_item3_cat') }}</span><h3>{{ __('marketplace.demo_item3') }}</h3><p>{{ __('marketplace.demo_not_for_sale') }}</p></div></article>
    </div>
  @else
    <div class="zm-empty-state zm-empty-products"><div class="zm-empty-icon">↗</div><div><span class="zm-empty-eyebrow">ZEMBOA MARKETPLACE</span><h3>{{ __('marketplace.empty_products_title') }}</h3><p>{{ __('marketplace.no_products') }}</p></div><a href="{{ route('marketplace.stores') }}" class="zm-empty-link">{{ __('marketplace.explore_stores') }} ↗</a></div>
  @endif
@else
  <div class="zm-products-grid">@foreach($products as $product) @include('marketplace.components.product-card') @endforeach</div><div class="zm-pagination">{{ $products->fragment('products')->links() }}</div>
@endif
</div></section>

<section class="zm-bottom-cta"><div class="zm-container zm-bottom-cta-inner"><div><span>{{ __('marketplace.bottom_eyebrow') }}</span><h2>{{ __('marketplace.bottom_title') }}</h2><p>{{ __('marketplace.bottom_description') }}</p></div><a class="zm-btn zm-btn-light" href="{{ route('marketplace.stores') }}">{{ __('marketplace.explore_stores') }} ↗</a></div></section>
@endsection
