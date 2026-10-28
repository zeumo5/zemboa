@extends('auth.layout')
@section('title',__($register?'buyer.register_title':'buyer.login_title'))
@section('heading',__($register?'buyer.register_title':'buyer.login_title'))
@section('intro',__($register?'access.register_intro':'access.buyer_intro'))
@section('form')
@if(auth('web')->check())<p class="auth-note">{{ __('access.shopping_blocked') }}</p>@endif
<form method="post" action="{{ route($register?'buyer.register.store':'buyer.login.store') }}" class="auth-form">@csrf
@if($register)
<div class="auth-field"><label for="buyer-name">{{ __('buyer.name') }}</label><input id="buyer-name" name="name" autocomplete="name" maxlength="150" value="{{ old('name') }}" required></div>
<div class="auth-field"><label for="buyer-phone">{{ __('buyer.phone') }}</label><input id="buyer-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="{{ old('phone') }}" maxlength="64" required aria-describedby="buyer-phone-hint"><small id="buyer-phone-hint">{{ __('buyer.phone_hint') }}</small></div>
<div class="auth-field"><label for="buyer-email">{{ __('buyer.email_optional') }}</label><input id="buyer-email" name="email" type="email" inputmode="email" autocomplete="email" value="{{ old('email') }}" maxlength="254"></div>
@else
<div class="auth-field"><label for="buyer-identifier">{{ __('buyer.identifier') }}</label><input id="buyer-identifier" name="identifier" autocomplete="username" value="{{ old('identifier',old('email')) }}" maxlength="254" required></div>
@endif
@include('auth.password',['passwordId'=>'buyer-password','passwordLabel'=>__('buyer.password'),'newPassword'=>$register])
@if($register)<p class="auth-note">{{ __('buyer.password_hint') }}</p>
@include('auth.password',['passwordId'=>'buyer-confirmation','passwordName'=>'password_confirmation','passwordLabel'=>__('buyer.password_confirmation'),'newPassword'=>true])
@else
<details class="auth-opt-in"><summary>{{ __('buyer.enable_buyer') }}</summary><label class="auth-check"><input type="checkbox" name="enable_buyer" value="1" @checked(old('enable_buyer'))><span>{{ __('buyer.enable_hint') }}</span></label></details>
@endif
<button type="submit" class="auth-submit">{{ __($register?'buyer.register':'buyer.login') }}</button></form>
@if(!$register)<p class="auth-note">{{ __('access.recovery_help') }}</p>@endif
@endsection
@section('links')<p>{{ __($register?'buyer.already_registered':'buyer.not_registered') }} <a href="{{ route($register?'buyer.login':'buyer.register') }}">{{ __($register?'buyer.login':'buyer.register') }}</a></p><a href="{{ route('login') }}">{{ __('buyer.professional') }}</a>@endsection
