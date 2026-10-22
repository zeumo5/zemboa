@extends('marketplace.layout')
@section('title', __('buyer.account_title'))
@section('content')
<section class="zm-section"><div class="zm-container buyer-account">
  <p class="zm-form-kicker">{{ __('buyer.space') }}</p><h1>{{ __('buyer.welcome', ['name' => $buyer->name]) }}</h1>
  <p>{{ __('buyer.account_ready') }}</p><p>{{ $buyer->email }}</p><p>{{ __('buyer.account_scope') }}</p>
  <div class="buyer-account-actions"><a class="zm-btn zm-btn-dark" href="{{ route('marketplace.stores') }}">{{ __('buyer.explore') }}</a><form method="post" action="{{ route('buyer.logout') }}">@csrf<button class="zm-btn zm-btn-light" type="submit">{{ __('buyer.logout') }}</button></form></div>
</div></section>
@endsection
