@foreach([['admin.dashboard','dashboard','grid'],['admin.stores.index','stores','orders'],['admin.stores.create','create','profile']] as [$route,$label,$icon])
<a href="{{ route($route) }}" @if(request()->routeIs($route) || ($route==='admin.stores.index' && request()->routeIs('admin.stores.show'))) aria-current="page" @endif>@include('buyer.icon',['icon'=>$icon])<span>{{ __('admin.'.$label) }}</span></a>
@endforeach
<a href="{{ route('marketplace.home') }}">@include('marketplace.partials.commerce-icon',['icon'=>'store'])<span>{{ __('admin.platform') }}</span></a>
