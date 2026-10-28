@extends('auth.layout')
@section('auth-variant','auth-admin')
@section('title',__('admin.login'))
@section('heading',__('admin.login'))
@section('intro',__('access.admin_intro'))
@section('form')
<form method="post" action="{{ route('admin.login.store') }}" class="auth-form">@csrf
<div class="auth-field"><label for="email">{{ __('admin.email') }}</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required></div>
@include('auth.password',['passwordId'=>'password','passwordLabel'=>__('admin.password')])
<button class="auth-submit" type="submit">{{ __('admin.connect') }}</button></form>
<p class="auth-note">{{ __('access.recovery_help') }}</p>
@endsection
@section('links')<a href="{{ route('login') }}">{{ __('access.signin') }}</a>@endsection
