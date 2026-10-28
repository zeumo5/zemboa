@foreach([['buyer.account', 'dashboard', 'grid'], ['buyer.orders', 'orders', 'orders'], ['buyer.profile', 'profile', 'profile']] as [$routeName, $label, $icon])
<a href="{{ route($routeName) }}" @if(request()->routeIs($routeName) || ($routeName === 'buyer.orders' && request()->routeIs('buyer.orders.show'))) aria-current="page" @endif><span class="customer-nav-icon">@include('buyer.icon', ['icon' => $icon])</span><span>{{ __('customer.'.$label) }}</span></a>
@endforeach
