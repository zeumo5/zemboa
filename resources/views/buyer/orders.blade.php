@extends('buyer.layout')
@section('title', __('customer.orders'))
@section('content')
<div class="customer-page-heading"><h1>{{ __('customer.orders') }}</h1><p>{{ __('customer.privacy_hint') }}</p></div>
<div class="customer-history">@forelse($orders as $order)@include('buyer.order-card')@empty @include('buyer.empty') @endforelse</div>
@if($orders->hasPages())<div class="customer-pagination">{{ $orders->links() }}</div>@endif
@endsection
