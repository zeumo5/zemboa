@extends('buyer.layout')
@section('title', __('customer.dashboard'))
@section('content')
<section class="customer-hero"><span>{{ __('customer.personal') }}</span><h1>{{ __('customer.welcome') }}</h1><p>{{ __('customer.intro') }}</p></section>
<div class="customer-statistics">@foreach([['orders', 'total', 'total_hint'], ['pending', 'pending', 'pending_hint'], ['paid_orders', 'paid', 'paid_hint']] as [$label, $field, $hint])<section class="customer-stat"><h2>{{ __('customer.'.$label) }}</h2><strong>{{ (int) $stats->$field }}</strong><p>{{ __('customer.'.$hint) }}</p></section>@endforeach</div>
<div class="customer-dashboard-grid"><section class="customer-panel"><div class="customer-panel-heading"><h2>{{ __('customer.recent') }}</h2><a href="{{ route('buyer.orders') }}">{{ __('customer.all_orders') }} &rarr;</a></div><div class="customer-order-list">@forelse($orders as $order)@include('buyer.order-card')@empty @include('buyer.empty') @endforelse</div></section>@include('buyer.profile-card')</div>
<aside class="customer-global-note"><strong>{{ __('customer.scope') }}</strong><p>{{ __('customer.no_link') }}</p></aside>
@endsection
