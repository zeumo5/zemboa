@extends('marketplace.layout')
@section('title', __('marketplace.login'))
@section('content')
<section class="zm-login-section"><div class="zm-container zm-login-shell">
  <div class="zm-login-story"><div class="zm-login-story-top"><span>ACCOUNT / ZEMBOA</span><span>01 — CM</span></div><div class="zm-login-story-main"><span class="zm-story-stamp">Z<span>.</span></span><span class="zm-story-kicker">{{ __('marketplace.professional_space') }}</span><h1>{{ __('marketplace.login_story_title') }}</h1><p>{{ __('marketplace.login_story_text') }}</p></div><div class="zm-login-story-bottom">ZEMBOA <span>↗</span></div></div>
  <div class="zm-login-form-panel"><div class="zm-form-kicker">{{ __('marketplace.secure_access') }}</div><h2>{{ __('marketplace.login_title') }}</h2><p class="zm-login-intro">{{ __('marketplace.login_subtitle') }}</p>
  @if($errors->any())<div class="zm-alert" role="alert"><strong>{{ __('marketplace.login_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="POST" action="{{ route('login.store') }}" class="zm-auth-form">@csrf
    <div class="zm-field"><label for="email">{{ __('marketplace.email') }}</label><input id="email" type="email" name="email" autocomplete="username" inputmode="email" value="{{ old('email') }}" placeholder="nom@exemple.com" required autofocus></div>
    <div class="zm-field"><label for="password">{{ __('marketplace.password') }}</label><input id="password" type="password" name="password" autocomplete="current-password" placeholder="••••••••••" required></div>
    <button class="zm-auth-submit" type="submit">{{ __('marketplace.login_submit') }} <span aria-hidden="true">→</span></button>
  </form><div class="zm-login-small-note"><span aria-hidden="true">◇</span>{{ __('marketplace.login_only_pro') }}</div><a class="zm-login-back" href="{{ route('marketplace.home') }}">← {{ __('marketplace.back_to_marketplace') }}</a></div>
</div></section>
@endsection
