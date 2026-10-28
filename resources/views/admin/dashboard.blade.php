@extends('admin.layout')
@section('title',__('admin.dashboard'))
@section('content')
<section class="sa-hero"><div><p>SUPER_ADMIN · ZEMBOA</p><h1>{{ __('admin.welcome') }}</h1><p>{{ __('admin.intro') }}</p></div><time datetime="{{ now()->toDateString() }}">{{ now()->format('d/m/Y') }}</time></section>
<div class="sa-statistics">
@foreach([['active',(int)$counts->get('ACTIVE',0),'store'],['pending',(int)$counts->get('PENDING',0),'shield'],['inactive',(int)$counts->get('INACTIVE',0)+(int)$counts->get('SUSPENDED',0),'truck'],['professionals',$professionals,'cart']] as [$label,$value,$icon])
<section class="sa-stat"><span>@include('marketplace.partials.commerce-icon',['icon'=>$icon])</span><div><strong>{{ number_format($value,0,',',' ') }}</strong><h2>{{ __('admin.'.$label) }}</h2></div></section>
@endforeach
</div>
<div class="sa-columns"><section class="sa-panel"><div class="sa-heading"><h2>{{ __('admin.recent') }}</h2><a href="{{ route('admin.stores.index') }}">{{ __('admin.all') }} →</a></div>
@include('admin.stores.table',['showOwners'=>false])
</section><div class="sa-stack"><section class="sa-panel"><h2>{{ __('admin.create') }}</h2><p>{{ __('admin.creation_hint') }}</p><a class="sa-primary" href="{{ route('admin.stores.create') }}">{{ __('admin.create') }} →</a></section>
<section class="sa-panel"><h2>{{ __('admin.distribution') }}</h2><dl class="sa-facts">@forelse($distribution as $row)<div><dt>{{ __('admin.statuses.'.$row->status) }}</dt><dd>{{ $row->count }}</dd></div>@empty<p>{{ __('admin.empty') }}</p>@endforelse</dl></section></div></div>
<p class="sa-future">{{ __('admin.future') }}</p>
@endsection
