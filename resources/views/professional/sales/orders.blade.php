@extends('professional.layout')
@section('title',__('sales.orders'))
@section('content')
<div class="pc-heading"><div><p class="pro-eyebrow">{{ $store->name }}</p><h1>{{ __('sales.orders') }}</h1><p>{{ __('sales.orders_intro') }}</p></div>@if($actor->hasPermission('pos.sales.create'))<a class="pc-button" href="{{ route('pos.interface.index') }}">{{ __('pos_interface.title') }} →</a>@endif</div>
@include('professional.sales.nav')
<p class="pc-note">{{ __('sales.summary') }}</p><div class="ps-stats">@foreach(['total'=>'total_orders','paid'=>'paid_orders','unpaid'=>'unpaid_orders'] as $key=>$label)<article class="pro-panel"><span>{{ __('sales.'.$label) }}</span><strong>{{ (int)$summary->$key }}</strong></article>@endforeach</div>
<section class="pro-panel pc-panel"><form class="pc-filters" method="get" action="{{ route('professional.orders.index') }}">
<label class="pc-search">{{ __('sales.search') }}<input type="search" name="q" maxlength="100" value="{{ $filters['q']??'' }}"></label>
@foreach(['status'=>['PENDING','CANCELLED'],'payment'=>['UNPAID','PARTIALLY_PAID','PAID'],'mode'=>['DELIVERY','PICKUP','POS']] as $filter=>$values)<label>{{ __('sales.'.$filter) }}<select name="{{ $filter }}"><option value="">{{ __('sales.all') }}</option>@foreach($values as $value)<option value="{{ $value }}" @selected(($filters[$filter]??'')===$value)>{{ __('sales.states.'.$value) }}</option>@endforeach</select></label>@endforeach
@if($schemaReady)<label>{{ __('sales.preparation') }}<select name="preparation"><option value="">{{ __('sales.all') }}</option>@foreach(['new','preparing','ready'] as $value)<option value="{{ $value }}" @selected(($filters['preparation']??'')===$value)>{{ __('sales.'.$value) }}</option>@endforeach</select></label>@endif
@foreach(['from','to'] as $date)<label>{{ __('sales.'.$date) }}<input type="date" name="{{ $date }}" value="{{ $filters[$date]??'' }}"></label>@endforeach
<button type="submit" class="pc-button">{{ __('catalog.filter') }}</button><a class="pc-link" href="{{ route('professional.orders.index') }}">{{ __('catalog.reset') }}</a></form>
<p class="pc-count">{{ __('catalog.results',['count'=>$orders->total()]) }}</p>
@include('professional.sales.order-table'){{ $orders->links() }}</section>
@endsection
