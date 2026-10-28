<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('admin.space')) — ZEMBOA</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-site">
<a class="zm-skip-link" href="#admin-main">{{ __('marketplace.skip_to_content') }}</a>
<header class="sa-header">
    <a class="sa-brand" href="{{ route('admin.dashboard') }}"><img src="{{ asset('marketplace/zemboa-mark.svg') }}" width="38" height="38" alt=""><strong>ZEMBOA<span>.</span></strong></a>
    <span class="sa-space">{{ __('admin.space') }}</span>
    <details class="sa-menu"><summary aria-label="{{ __('admin.menu') }}"><span class="sa-avatar" aria-hidden="true">Z</span><span><span data-private>{{ auth('web')->user()->name }}</span><small>SUPER_ADMIN</small></span><span aria-hidden="true">⌄</span></summary><div>
        @include('admin.navigation')
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button>{{ __('admin.logout') }}</button></form>
    </div></details>
</header>
<div class="sa-shell">
    <aside class="sa-sidebar"><p>{{ __('admin.space') }}</p><nav aria-label="{{ __('admin.menu') }}">@include('admin.navigation')</nav></aside>
    <main id="admin-main" tabindex="-1">
        @if(session('success'))<p class="sa-notice" role="status">{{ session('success') }}</p>@endif
        @if($errors->any())<div class="sa-errors" role="alert"><strong>{{ __('admin.validation') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</div>
</body></html>
