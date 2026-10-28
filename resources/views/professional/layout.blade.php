<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex,nofollow">
<title>@yield('title') — ZEMBOA</title><link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head><body class="professional-site">
<a class="zm-skip-link" href="#professional-main">{{ __('marketplace.skip_to_content') }}</a>
<header class="pro-header"><a class="pro-brand" href="{{ route($actor->hasRole('CASHIER') ? 'pos.interface.index' : 'professional.dashboard') }}"><img src="{{ asset('marketplace/zemboa-mark.svg') }}" width="40" height="40" alt=""><strong>ZEMBOA<span>.</span></strong></a><span class="pro-space">{{ __('professional.space') }}</span><a class="pro-store-link" href="{{ route('marketplace.shops.show',['shopSlug'=>$store->slug]) }}">{{ __('professional.view_store') }} <span aria-hidden="true">→</span></a>
<details class="pro-menu"><summary aria-label="{{ __('professional.menu') }} — {{ $store->name }}"><span class="pro-store-logo">@if($settings?->logo_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settings->logo_path) }}" alt="">@else{{ mb_strtoupper(mb_substr(preg_replace('/^DEMO\s*-\s*/u','',$store->name),0,1)) }}@endif</span><span>{{ $store->name }}</span><span aria-hidden="true">⌄</span></summary><div>@include('professional.navigation')<form method="post" action="{{ route('logout') }}">@csrf<button type="submit">{{ __('buyer.logout') }}</button></form></div></details></header>
<div class="pro-shell"><aside class="pro-sidebar"><div class="pro-role-context"><p><strong>{{ $actor->name }}</strong><br>{{ __('team.roles.'.$actor->roles()->first()?->code) }}</p></div><nav aria-label="{{ __('professional.navigation') }}">@include('professional.navigation')</nav><p class="pro-api-note">{{ __('professional.api_note') }}</p></aside>
<main id="professional-main" tabindex="-1">@yield('content')
<nav class="pro-mobile-navigation" aria-label="{{ __('professional.navigation') }}">
    @can('viewAny',\App\Models\StockLevel::class)<a href="{{ route('professional.inventory.index') }}" @if(request()->routeIs('professional.inventory.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'grid'])<span>{{ __('professional.stock') }}</span></a>@endcan
    @unless($actor->hasRole('CASHIER'))<a href="{{ route('professional.dashboard') }}" @if(request()->routeIs('professional.dashboard')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'grid'])<span>{{ __('professional.dashboard') }}</span></a>@endunless
    @if($actor->hasPermission('pos.sales.create'))<a href="{{ route('pos.interface.index') }}" @if(request()->routeIs('pos.interface.index')) aria-current="page" @endif>@include('marketplace.partials.commerce-icon',['icon'=>'cart'])<span>{{ __('pos_interface.title') }}</span></a>@endif
    @if($actor->hasRole('CASHIER') && $actor->hasPermission('orders.view'))<a href="{{ route('pos.interface.history') }}" @if(request()->routeIs('pos.interface.history','pos.interface.show')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'orders'])<span>{{ __('pos_interface.history') }}</span></a>@endif
    @can('viewAny',\App\Models\Order::class)
    <a href="{{ route('professional.orders.index') }}" @if(request()->routeIs('professional.orders.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'orders'])<span>{{ __('professional.orders') }}</span></a>
    @endcan
    @if(\App\Support\SalesAccess::allowed($actor,'customers.view'))<a href="{{ route('professional.customers.index') }}" @if(request()->routeIs('professional.customers.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'profile'])<span>{{ __('professional.customers') }}</span></a>@endif
    @if(\App\Support\SalesAccess::allowed($actor,'returns.view',true))<a href="{{ route('professional.returns.index') }}" @if(request()->routeIs('professional.returns.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'orders'])<span>{{ __('sales.returns') }}</span></a>@endif
    @if(\App\Support\EmployeeAccess::owner($actor, (int)$store->id))<a href="{{ route('employees.index') }}" @if(request()->routeIs('employees.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'profile'])<span>{{ __('team.title') }}</span></a>@endif
    @if($actor->hasPermission('products.view'))<a href="{{ route('professional.products.index') }}" @if(request()->routeIs('professional.products.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'grid'])<span>{{ __('professional.products') }}</span></a>@endif
    @can('viewAny',\App\Models\Category::class)<a href="{{ route('professional.categories.index') }}" @if(request()->routeIs('professional.categories.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'grid'])<span>{{ __('professional.categories') }}</span></a>@endcan
    @if($actor->hasPermission('products.view') && ($actor->hasRole('STORE_OWNER') || $actor->hasRole('STORE_MANAGER')))<a href="{{ route('professional.promotions.index') }}" @if(request()->routeIs('professional.promotions.*')) aria-current="page" @endif>@include('buyer.icon',['icon'=>'grid'])<span>{{ __('promotions.title') }}</span></a>@endif
    <a href="{{ route('marketplace.shops.products',['shopSlug'=>$store->slug]) }}">@include('marketplace.partials.commerce-icon',['icon'=>'store'])<span>{{ __('professional.catalog') }}</span></a>
</nav></main></div>
<footer class="pro-footer">© {{ date('Y') }} ZEMBOA · {{ $store->name }} · {{ __('professional.store_status.'.$store->status) }}</footer>
</body></html>
