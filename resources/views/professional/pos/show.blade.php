@extends('professional.layout')
@section('title',$order->order_number)
@section('content')
<a href="{{ route('pos.interface.history') }}">← {{ __('pos_interface.back') }}</a>
<section class="pro-panel pos-sale-detail"><h1>{{ __('pos_interface.saved') }} — {{ $order->order_number }}</h1><p>{{ $order->created_at?->format('d/m/Y H:i') }} · {{ __('pos_interface.status.'.$order->payment_status) }}</p><p data-private>{{ $order->customer_name ?: (($order->customer_phone || $order->customer_email) ? __('pos_interface.customer') : __('pos_interface.anonymous')) }} @if($order->customer_phone)· {{ $order->customer_phone }}@endif</p><p>{{ __('pos_interface.cashier') }} : {{ $order->pos_document_snapshot['cashier'] ?? $order->posSession?->openedBy?->name }}</p>
@foreach($order->items as $item)<article class="pos-detail-line"><div><strong>{{ $item->product_name }}</strong><p>{{ $item->variant_description }}</p><small>{{ $item->quantity }} × {{ number_format((float)$item->final_unit_price,0,',',' ') }} FCFA</small></div><strong>{{ number_format((float)$item->line_total,0,',',' ') }} FCFA</strong></article>@endforeach
<dl class="pos-totals">@foreach(['subtotal'=>'subtotal','discount_amount'=>'discount','total'=>'total'] as $key=>$label)<div><dt>{{ __('pos_interface.'.$label) }}</dt><dd>{{ number_format((float)$order->$key,0,',',' ') }} FCFA</dd></div>@endforeach</dl>
@if($actor->hasPermission('payments.view'))@foreach($order->payments as $payment)<p>{{ $payment->method }} · {{ $payment->status }} · {{ number_format((float)$payment->amount,0,',',' ') }} FCFA</p>@if($payment->method==='CASH')<p>{{ __('pos_interface.received') }} : {{ number_format((float)$payment->cash_received,0,',',' ') }} FCFA · {{ __('pos_interface.change') }} : {{ number_format((float)$payment->change_given,0,',',' ') }} FCFA</p>@endif @endforeach @endif
@if($order->payment_status!=='PAID')<p class="pos-unpaid">{{ __('pos_interface.document_unpaid') }}</p>@endif
@if($order->status==='PENDING' && $order->payment_status!=='PAID' && $actor->hasPermission('payments.confirm') && $order->posSession?->status==='OPEN')
<form method="post" action="{{ route('pos.interface.collect',$order->id) }}" class="pos-collect">@csrf<label for="collect-cash">{{ __('pos_interface.cash_received') }}</label><input id="collect-cash" name="cash_received" type="number" min="0" step="1" required><label><input type="checkbox" name="confirmed" value="1" required> {{ __('pos_interface.confirm_cash') }}</label><button class="team-button team-primary" type="submit">{{ __('pos_interface.confirm_cash') }}</button></form>
@endif
@php($invoiceAvailable=($order->pos_document_snapshot['invoice_enabled']??false)&&($documents?->digital_invoices_enabled??true))
@php($receiptAvailable=($order->pos_document_snapshot['receipt_enabled']??false)&&($documents?->digital_receipts_enabled??true))
<div class="pos-document-links">@if($actor->hasPermission('payments.view'))
@if(is_array($order->pos_document_snapshot))
@foreach(['A4'=>'invoice','58'=>'ticket58','80'=>'ticket80'] as $format=>$label)
@if($format==='A4'?$invoiceAvailable:$receiptAvailable)<a class="team-button" href="{{ route('pos.interface.document',['orderId'=>$order->id,'format'=>$format]) }}" target="_blank" rel="noopener noreferrer">{{ __('pos_interface.'.$label) }}</a>@endif
@endforeach
@if($invoiceAvailable && class_exists(\Dompdf\Dompdf::class))<a class="team-button team-primary" href="{{ route('pos.interface.document',['orderId'=>$order->id,'format'=>'A4','download'=>'pdf']) }}">{{ __('pos_interface.download') }}</a>@endif
@foreach(['invoice'=>$invoiceAvailable,'receipt'=>$receiptAvailable] as $kind=>$enabled)@if($enabled)
@php($preferred=$kind==='invoice'?($documents?->invoice_print_format??'A4'):($documents?->receipt_print_format??'TICKET_80'))
@php($preferredFormat=['A4'=>'A4','TICKET_58'=>'58','TICKET_80'=>'80'][$preferred]??'A4')
<a class="team-button" href="{{ route('pos.interface.document',['orderId'=>$order->id,'format'=>$preferredFormat,'type'=>$kind]) }}" target="_blank" rel="noopener noreferrer">{{ __('pos_interface.print') }} — {{ $kind==='invoice'?__('pos_interface.invoice_heading'):__('pos_interface.receipt') }} ({{ $preferredFormat }})</a>
@endif @endforeach
@if(!$invoiceAvailable && !$receiptAvailable)<p>{{ __('pos_interface.documents_disabled') }}</p>@endif
@else<p>{{ __('pos_interface.legacy_document') }}</p>@endif
@endif<a class="team-button" href="{{ route('pos.interface.index') }}">{{ __('pos_interface.new') }}</a></div></section>
@endsection
