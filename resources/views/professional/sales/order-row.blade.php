<tr>
<td><a class="pc-link" href="{{ route('professional.orders.show',$row) }}">{{ $row->order_number }}</a><small class="ps-subtle">{{ $row->created_at?->format('d/m/Y H:i') }}</small></td>
<td>{{ $row->customer_name?:__('sales.anonymous') }}<small class="ps-subtle">{{ $row->buyer_user_id?__('sales.account_linked'):__('sales.no_account') }}</small></td>
<td data-label="{{ __('sales.total') }}"><strong>@include('professional.sales.money',['amount'=>$row->total])</strong></td>
<td><span class="pc-status {{ $row->payment_status==='PAID'?'is-active':'ps-warning' }}">{{ __('sales.states.'.$row->payment_status) }}</span></td>
<td>{{ __('sales.states.'.$row->status) }}<small class="ps-subtle">{{ $row->pos_session_id?__('sales.not_applicable'):__('sales.'.($row->ready_at?'ready':($row->preparation_started_at?'preparing':'new'))) }}</small></td>
<td>{{ __('sales.states.'.($row->pos_session_id?'POS':$row->fulfillment_type)) }}</td>
</tr>
