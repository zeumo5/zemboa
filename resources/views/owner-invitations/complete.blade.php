@extends('auth.layout')
@section('title',__('access.complete'))
@section('heading',__('access.complete'))
@section('intro',__('access.complete_hint'))
@section('links')<a class="auth-submit" href="{{ route('login') }}">{{ __('access.signin') }}</a>@endsection
