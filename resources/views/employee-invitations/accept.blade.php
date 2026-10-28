@extends('auth.layout')
@section('title',__('team.activation_title'))
@section('heading',__('team.activation_title'))
@section('intro',__('team.activation_intro'))
@section('form')
<div class="auth-invite-store"><strong>{{ $invitationStore->name }}</strong><span>{{ __('team.roles.'.$invitationRole) }}</span></div>
<form method="post" action="{{ route('employee.invitation.accept',['token'=>$token]) }}" class="auth-form">@csrf<input type="hidden" name="email" value="{{ $email }}">
@include('auth.password',['passwordId'=>'password','passwordLabel'=>__('team.password'),'newPassword'=>true])
<p class="auth-note">{{ __('buyer.password_hint') }}</p>
@include('auth.password',['passwordId'=>'password_confirmation','passwordName'=>'password_confirmation','passwordLabel'=>__('team.confirmation'),'newPassword'=>true])
<button type="submit" class="auth-submit">{{ __('team.activate') }}</button></form>
@endsection
