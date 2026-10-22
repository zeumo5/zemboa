@extends('marketplace.layout')
@section('title', __($register ? 'buyer.register_title' : 'buyer.login_title'))
@section('content')
<section class="zm-login-section"><div class="zm-container zm-login-shell">
  <div class="zm-login-story">
    <div class="zm-login-story-top"><span>ZEMBOA</span><span>{{ __('buyer.space') }}</span></div>
    <div class="zm-login-story-main"><span class="zm-story-stamp" aria-hidden="true">Z<span>.</span></span><span class="zm-story-kicker">{{ __('buyer.space') }}</span><h1>{{ __('buyer.story_title') }}</h1><p>{{ __('buyer.story_text') }}</p></div>
    <div class="zm-login-story-bottom">ZEMBOA <span aria-hidden="true">↗</span></div>
  </div>
  <div class="zm-login-form-panel">
    <div class="zm-form-kicker">{{ __('marketplace.secure_access') }}</div>
    <h2>{{ __($register ? 'buyer.register_title' : 'buyer.login_title') }}</h2><p class="zm-login-intro">{{ __('buyer.intro') }}</p>
    @if($errors->any())<div class="zm-alert" role="alert"><strong>{{ __('buyer.errors') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" action="{{ route($register ? 'buyer.register.store' : 'buyer.login.store') }}" class="zm-auth-form">
      @csrf
      @if($register)<div class="zm-field"><label for="buyer-name">{{ __('buyer.name') }}</label><input id="buyer-name" name="name" autocomplete="name" maxlength="150" value="{{ old('name') }}" required @error('name') aria-invalid="true" @enderror></div>@endif
      <div class="zm-field"><label for="buyer-email">{{ __('buyer.email') }}</label><input id="buyer-email" name="email" type="email" inputmode="email" autocomplete="username" maxlength="254" value="{{ old('email') }}" required @error('email') aria-invalid="true" @enderror></div>
      <div class="zm-field"><label for="buyer-password">{{ __('buyer.password') }}</label><input id="buyer-password" name="password" type="password" autocomplete="{{ $register ? 'new-password' : 'current-password' }}" maxlength="255" @if($register) minlength="12" aria-describedby="buyer-password-hint" @endif required @error('password') aria-invalid="true" @enderror>@if($register)<p id="buyer-password-hint" class="buyer-help">{{ __('buyer.password_hint') }}</p>@endif</div>
      @if($register)
        <div class="zm-field"><label for="buyer-confirmation">{{ __('buyer.password_confirmation') }}</label><input id="buyer-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="255" required></div>
      @else
        <div class="buyer-opt-in"><label for="buyer-enable"><input id="buyer-enable" type="checkbox" name="enable_buyer" value="1" @checked(old('enable_buyer')) aria-describedby="buyer-enable-hint"><span>{{ __('buyer.enable_buyer') }}</span></label><p id="buyer-enable-hint" class="buyer-help">{{ __('buyer.enable_hint') }}</p></div>
      @endif
      <button class="zm-auth-submit" type="submit">{{ __($register ? 'buyer.register' : 'buyer.login') }} <span aria-hidden="true">→</span></button>
    </form>
    <div class="buyer-auth-links"><p>{{ __($register ? 'buyer.already_registered' : 'buyer.not_registered') }} <a href="{{ route($register ? 'buyer.login' : 'buyer.register') }}">{{ __($register ? 'buyer.login' : 'buyer.register') }}</a></p><a href="{{ route('login') }}">{{ __('buyer.professional') }}</a><a href="{{ route('marketplace.home') }}">← {{ __('buyer.back') }}</a></div>
  </div>
</div></section>
@endsection
