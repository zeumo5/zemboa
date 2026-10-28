@extends('auth.layout')
@section('title',__('access.signin'))
@section('heading',__('marketplace.professional_space'))
@section('intro',__('access.pro_intro'))
@section('form')
<form method="post" action="{{ route('login.store') }}" class="auth-form">@csrf
<div class="auth-field"><label for="email">{{ __('marketplace.email') }}</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required></div>
@include('auth.password',['passwordId'=>'password','passwordLabel'=>__('marketplace.password')])
<button type="submit" class="auth-submit">{{ __('marketplace.login_submit') }}</button></form>
<p class="auth-note">{{ __('access.invite_help') }}</p><p class="auth-note">{{ __('access.recovery_help') }}</p>
@endsection
@section('links')<a href="{{ route('buyer.login') }}">{{ __('access.buyer_space') }}</a>@endsection
