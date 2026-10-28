@extends('admin.layout')
@section('title',__('admin.stores'))
@section('content')
<div class="sa-page-heading"><h1>{{ __('admin.stores') }}</h1><a class="sa-primary" href="{{ route('admin.stores.create') }}">+ {{ __('admin.create') }}</a></div>
<form class="sa-filters sa-panel" method="get" action="{{ route('admin.stores.index') }}">
<label>{{ __('admin.search') }}<input type="search" name="q" maxlength="150" value="{{ $filters['q']??'' }}" placeholder="{{ __('admin.search_hint') }}"></label>
<label>{{ __('admin.status') }}<select name="status"><option value="">{{ __('admin.any') }}</option>@foreach(__('admin.statuses') as $value=>$label)<option value="{{ $value }}" @selected(($filters['status']??'')===$value)>{{ $label }}</option>@endforeach</select></label>
<button class="sa-primary">{{ __('admin.filter') }}</button><a href="{{ route('admin.stores.index') }}">{{ __('admin.reset') }}</a></form>
<section class="sa-panel">@include('admin.stores.table',['showOwners'=>true])<div class="sa-pagination">{{ $stores->links() }}</div></section>
@endsection
