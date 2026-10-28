@php($state=$reservation->status==='ACTIVE'?($reservation->expires_at===null?'undated':($reservation->expires_at->isPast()||$reservation->expires_at->equalTo(now())?'overdue':'not_due')):$reservation->status)
<span class="pc-status {{ $state==='overdue'?'iv-warning':($state==='not_due'?'is-active':'') }}">{{ __('inventory.'.$state) }}</span>
