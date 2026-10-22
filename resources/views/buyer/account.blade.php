@extends('marketplace.layout')
@section('title', __('buyer.account_title'))
@section('content')
<section class="zm-section"><div class="zm-container buyer-account">
  <p class="zm-form-kicker">{{ __('buyer.space') }}</p><h1>{{ __('buyer.welcome', ['name' => $buyer->name]) }}</h1>
  <p>{{ __('buyer.account_ready') }}</p>@if($buyer->email)<p>{{ __('buyer.email') }} : {{ $buyer->email }}</p>@endif<p>{{ __('buyer.phone') }} : {{ $phone ?? __('buyer.phone_missing') }}</p><p class="buyer-help">{{ __('buyer.phone_unverified') }}</p><p>{{ __('buyer.account_scope') }}</p>
  <div class="buyer-account-actions"><a class="zm-btn zm-btn-dark" href="{{ route('marketplace.stores') }}">{{ __('buyer.explore') }}</a><form method="post" action="{{ route('buyer.logout') }}">@csrf<button class="zm-btn zm-btn-light" type="submit">{{ __('buyer.logout') }}</button></form></div>
</div></section>
@endsection
