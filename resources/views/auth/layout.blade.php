<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>@yield('title') — ZEMBOA</title><link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="auth-ui @yield('auth-variant')"><a class="zm-skip-link" href="#auth-main">{{ __('marketplace.skip_to_content') }}</a>
<header class="auth-top"><a href="{{ route('marketplace.home') }}">← {{ __('marketplace.back_to_marketplace') }}</a><div class="auth-languages" aria-label="{{ __('marketplace.language_label') }}">@foreach(['fr','en'] as $language)<button type="button" data-auth-language="{{ $language }}" data-url="{{ auth('web')->check()?route('account.locale.update'):route('guest.locale.update') }}" data-method="{{ auth('web')->check()?'PATCH':'POST' }}" aria-pressed="{{ app()->getLocale()===$language?'true':'false' }}">{{ strtoupper($language) }}</button>@endforeach</div></header>
<main id="auth-main" class="auth-main"><a class="auth-brand" href="{{ route('marketplace.home') }}"><img src="{{ asset('marketplace/zemboa-mark.svg') }}" width="52" height="52" alt=""><span><strong>ZEMBOA<span>.</span></strong><small>{{ __('access.brand_note') }}</small></span></a>
<section class="auth-card"><h1>@yield('heading')</h1><p class="auth-intro">@yield('intro')</p>
@if($errors->any())<div class="auth-alert" role="alert"><strong>{{ __('buyer.errors') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('form')
<div class="auth-links">@yield('links')</div></section>
<p class="auth-language-error" role="status" data-auth-language-error hidden>{{ __('access.language_error') }}</p>
@if(auth('web')->check() || auth('buyer')->check())<div class="auth-context">@include('marketplace.partials.account-links')@if(auth('web')->check() && auth('buyer')->check())<p>{{ __('access.both') }}</p>@endif</div>@endif
</main></body></html>
