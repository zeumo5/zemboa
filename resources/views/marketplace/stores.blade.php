@extends('marketplace.layout')
@section('title', __('marketplace.shops_directory'))
@section('content')
<section class="zm-page-hero"><div class="zm-container"><a class="zm-back" href="{{ route('marketplace.home') }}">← {{ __('marketplace.back_to_marketplace') }}</a><span class="zm-page-eyebrow">ZEMBOA / {{ __('marketplace.navigation_stores') }}</span><h1>{{ __('marketplace.shops_directory') }}<span class="zm-title-dot">.</span></h1><p>{{ __('marketplace.shops_intro') }}</p><span class="zm-page-hero-watermark" aria-hidden="true">Z</span></div></section>
<section class="zm-section zm-directory-section"><div class="zm-container"><form class="zm-search zm-search-page" data-zm-search method="get" action="{{ route('marketplace.stores') }}"><label class="zm-sr-only" for="stores-q">{{ __('marketplace.search') }}</label><input id="stores-q" name="q" maxlength="80" value="{{ $search }}" placeholder="{{ __('marketplace.search') }}"><button type="submit">{{ __('marketplace.search_button') }} →</button></form>
@if($search !== '')
<div class="zm-search-feedback" role="status" aria-live="polite"><span>{{ __('marketplace.search_results_for') }} <strong>« {{ $search }} »</strong> · {{ $stores->total() }} {{ __('marketplace.results') }}</span><a href="{{ route('marketplace.stores') }}">{{ __('marketplace.clear_search') }} <span aria-hidden="true">×</span></a></div>
@endif
@if($stores->count() === 0)<div class="zm-empty-state zm-empty-directory"><div class="zm-empty-icon"><span>Z.</span></div><div><span class="zm-empty-eyebrow">ZEMBOA MARKETPLACE</span><h3>{{ __('marketplace.empty_stores_title') }}</h3><p>{{ __('marketplace.no_stores') }}</p></div><a href="{{ route('marketplace.home') }}" class="zm-empty-link">{{ __('marketplace.back_to_marketplace') }} ↗</a></div>
@else<div class="zm-stores-grid zm-stores-directory">@foreach($stores as $store) @include('marketplace.components.store-card') @endforeach</div><div class="zm-pagination">{{ $stores->links() }}</div>@endif</div></section>
@endsection
