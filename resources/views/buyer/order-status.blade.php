@php
  $statusKey = 'customer.order_status.'.$order->status;
  $paymentKey = 'customer.payment_states.'.$order->payment_status;
@endphp
<div class="customer-statuses"><span class="customer-badge">{{ \Illuminate\Support\Facades\Lang::has($statusKey) ? __($statusKey) : $order->status }}</span><span class="customer-badge {{ $order->payment_status === 'PAID' ? 'customer-badge-paid' : 'customer-badge-unpaid' }}">{{ \Illuminate\Support\Facades\Lang::has($paymentKey) ? __($paymentKey) : $order->payment_status }}</span></div>
