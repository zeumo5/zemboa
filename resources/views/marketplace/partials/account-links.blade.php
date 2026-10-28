@php($professionalDestination=\App\Support\AccountDestination::professionalRoute(auth('web')->user()))
@if($professionalDestination)<a class="account-space-link" data-professional-space href="{{ route($professionalDestination) }}">{{ __($professionalDestination==='admin.dashboard'?'access.administration':'access.back_space') }} <span aria-hidden="true">↗</span></a>
@elseif(auth('web')->check())<a class="account-space-link" href="{{ route('account.show') }}">{{ __('access.workspace') }}</a>
@endif
@if($showBuyer ?? true)
@if(auth('buyer')->user()?->canUseBuyerAccount())<a class="account-space-link" data-buyer-space href="{{ route('buyer.account') }}">{{ __('access.buyer_space') }}</a>
@elseif(!auth('web')->check())<a class="account-space-link" href="{{ route('buyer.login') }}">{{ __('access.buyer_space') }}</a>@endif
@endif
@if(!auth('web')->check())<a class="account-space-link" href="{{ route('login') }}">{{ __('access.signin') }}</a>@endif
