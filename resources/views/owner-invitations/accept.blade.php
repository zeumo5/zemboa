@extends('auth.layout')
@section('title',__('access.invitation'))
@section('heading',__('access.invitation'))
@section('intro',__('access.invite_intro'))
@section('form')
<div class="auth-invite-store"><strong>{{ $invitationStore->name }}</strong><span>{{ __('access.owner') }}</span></div>
<form method="post" action="{{ route('owner.invitation.accept',['token'=>$token]) }}" class="auth-form">@csrf<input type="hidden" name="email" value="{{ $email }}">
@include('auth.password',['passwordId'=>'password','passwordLabel'=>__('buyer.password'),'newPassword'=>true])
<p class="auth-note">{{ __('buyer.password_hint') }}</p>
@include('auth.password',['passwordId'=>'password_confirmation','passwordName'=>'password_confirmation','passwordLabel'=>__('buyer.password_confirmation'),'newPassword'=>true])
<button type="submit" class="auth-submit">{{ __('access.activate') }}</button></form>
@endsection
