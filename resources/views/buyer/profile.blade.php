@extends('buyer.layout')
@section('title', __('customer.profile'))
@section('content')
<div class="customer-page-heading"><h1>{{ __('customer.profile') }}</h1><p>{{ __('customer.profile_intro') }}</p></div>
<section class="customer-panel customer-profile-panel"><dl class="customer-profile-facts"><div><dt>{{ __('buyer.name') }}</dt><dd data-private>{{ $buyer->name }}</dd></div><div><dt>{{ __('buyer.phone') }}</dt><dd data-private>{{ $phone ?? __('buyer.phone_missing') }}</dd></div><div><dt>{{ __('buyer.email_optional') }}</dt><dd data-private>{{ $buyer->email ?: '—' }}</dd></div></dl><p class="customer-muted">{{ __('buyer.phone_unverified') }}</p><p class="customer-privacy">{{ __('customer.readonly') }}</p><form method="post" action="{{ route('buyer.logout') }}">@csrf<button class="customer-button customer-button-secondary" type="submit">{{ __('buyer.logout') }}</button></form></section>
@endsection
