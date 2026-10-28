<nav class="pc-section-nav" aria-label="{{ __('inventory.title') }}">
@foreach(['index'=>'title','reservations'=>'reservations','movements'=>'history'] as $route=>$label)<a href="{{ route('professional.inventory.'.$route) }}" @if(request()->routeIs('professional.inventory.'.$route)||($route==='reservations'&&request()->routeIs('professional.inventory.reservation'))) aria-current="page" @endif>{{ __('inventory.'.$label) }}</a>@endforeach
@if($actor->hasPermission('inventory.manage')&&!$actor->hasRole('CASHIER'))<a href="{{ route('professional.inventory.receive') }}">＋ {{ __('inventory.receive') }}</a><a href="{{ route('professional.inventory.adjust') }}">{{ __('inventory.adjust') }}</a>@endif
</nav>
