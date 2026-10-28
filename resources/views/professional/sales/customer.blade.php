@extends('professional.layout')
@section('title',__('sales.customer'))
@section('content')
<a class="pc-link" href="{{ route('professional.customers.index',['source'=>$source]) }}">← {{ __('sales.back_customers') }}</a><div class="pc-heading"><div><h1>{{ $customer?->name?:($sample->customer_name?:__('sales.anonymous')) }}</h1><p>{{ __('sales.'.$source) }}</p></div></div>@include('professional.sales.nav')
<p class="pc-feedback">{{ __('sales.identity_note') }}</p><section class="pro-panel pc-form"><h2>{{ $customer?__('sales.profile'):__('sales.snapshot') }}</h2><div class="ps-facts"><div><small>{{ __('sales.phone') }}</small><strong>{{ $customer?->phone?:($sample->customer_phone?:'—') }}</strong></div><div><small>{{ __('sales.email') }}</small><strong>{{ $customer?($customer->email?:'—'):($sample->customer_email?:'—') }}</strong></div><div><small>{{ __('sales.orders') }}</small><strong>{{ $summary->orders_count }}</strong></div><div><small>{{ __('sales.ordered_total') }}</small><strong>@include('professional.sales.money',['amount'=>$summary->ordered_total])</strong></div></div><p class="pc-note">{{ __('sales.ordered_hint') }}</p></section>
<section class="pro-panel pc-panel"><h2>{{ __('sales.history') }}</h2>@include('professional.sales.order-table'){{ $orders->links() }}</section>
@endsection
