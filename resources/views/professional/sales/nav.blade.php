<nav class="pc-section-nav" aria-label="{{ __('professional.navigation') }}">
<a href="{{ route('professional.orders.index') }}" @if(request()->routeIs('professional.orders.*')) aria-current="page" @endif>{{ __('sales.orders') }}</a>
@if(\App\Support\SalesAccess::allowed($actor,'customers.view'))<a href="{{ route('professional.customers.index') }}" @if(request()->routeIs('professional.customers.*')) aria-current="page" @endif>{{ __('sales.customers') }}</a>@endif
@if(\App\Support\SalesAccess::allowed($actor,'returns.view',true))<a href="{{ route('professional.returns.index') }}" @if(request()->routeIs('professional.returns.*')) aria-current="page" @endif>{{ __('sales.returns') }}</a>@endif
</nav>
@include('professional.catalog.feedback')
