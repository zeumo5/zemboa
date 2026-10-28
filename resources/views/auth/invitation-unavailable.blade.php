@extends('auth.layout')
@section('title',__('access.invalid'))
@section('heading',__('access.invalid'))
@section('intro',__('access.invalid_hint'))
@section('links')<a href="{{ route('login') }}">{{ __('access.signin') }}</a>@endsection
