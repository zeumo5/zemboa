@extends('marketplace.merchant.layout')
@section('title', __('checkout.created'))
@section('content')
<section class="merchant-section">
  <h1>{{ __('checkout.created') }} — {{ $order->order_number }}</h1>
  <p>{{ __('checkout.status') }} : {{ $order->status }} · {{ $order->payment_status }}</p>
  @if($order->payment_status === 'UNPAID')<p>{{ __('checkout.unpaid') }}</p>@endif
  <ul>@foreach($order->items as $item)<li>{{ $item->product_name }} · {{ $item->sku }} × {{ $item->quantity }} — {{ number_format((float) $item->line_total, 0, ',', ' ') }} FCFA</li>@endforeach</ul>
  <p>{{ __('checkout.fee') }} : {{ number_format((float) $order->delivery_fee, 0, ',', ' ') }} FCFA</p>
  <p>{{ __('checkout.total') }} : <strong>{{ number_format((float) $order->total, 0, ',', ' ') }} FCFA</strong></p>
  <p>{{ __('checkout.reserved_until') }} : {{ $order->reservation_expires_at?->format('d/m/Y H:i') }}</p>
  @unless($order->buyer_user_id)<p>{{ __('checkout.guest') }}</p>@endunless
  <a class="zm-btn zm-btn-light" href="{{ route('marketplace.shops.products', ['shopSlug' => $store->slug]) }}">{{ __('checkout.view_catalog') }}</a>
</section>
@endsection
