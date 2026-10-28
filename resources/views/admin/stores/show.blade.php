@extends('admin.layout')
@section('title',$store->name)
@section('content')
<a class="sa-back" href="{{ route('admin.stores.index') }}">← {{ __('admin.back') }}</a>
<div class="sa-page-heading"><h1>{{ $store->name }}</h1><span class="sa-badge sa-badge-{{ strtolower($store->status) }}">{{ __('admin.statuses.'.$store->status) }}</span></div>
<div class="sa-form-grid"><section class="sa-panel"><h2>{{ __('admin.details') }}</h2><dl class="sa-facts">
@foreach(['slug','description','phone','email','address'] as $field)<div><dt>{{ __('admin.'.$field) }}</dt><dd @if(in_array($field,['phone','email','address'])) data-private @endif>{{ $store->$field ?: __('admin.not_recorded') }}</dd></div>@endforeach</dl>
@if($store->status==='ACTIVE')<a class="sa-secondary" href="{{ route('marketplace.shops.show',['shopSlug'=>$store->slug]) }}">{{ __('professional.view_store') }} →</a>@endif
</section><section class="sa-panel"><h2>{{ __('admin.owner') }}</h2>
@forelse($owners as $owner)<div class="sa-owner"><strong data-private>{{ $owner->name }}</strong><p data-private>{{ $owner->email }}</p><span class="sa-badge">{{ __('admin.user_statuses.'.$owner->status) }}</span></div>@empty<p>{{ __('admin.not_recorded') }}</p>@endforelse
@php($owner=$owners->count()===1?$owners->first():null)
@if($owner)<h3>{{ __('access.invitation_state') }}</h3><dl class="sa-invitation-facts">
@php($currentLink=$invitationLinks->get($owner->email))
@if($currentLink && $owner->status==='INVITED')<div><dt>{{ __('access.link_issued') }}</dt><dd>{{ $currentLink->created_at }}</dd></div>@if(\Illuminate\Support\Carbon::parse($currentLink->created_at)->addMinutes((int)config('auth.passwords.users.expire'))->isPast())<div><dd>{{ __('access.link_expired') }}</dd></div>@endif<p>{{ __('access.delivery_unknown') }}</p>
@elseif(!$owner->email_verified_at)<div><dd>{{ __('access.no_link') }}</dd></div>@endif
@if($owner->email_verified_at)<div><dt>{{ __('access.accepted') }}</dt><dd>{{ $owner->email_verified_at->format('d/m/Y H:i') }}</dd></div>@endif
<div><dt>{{ __('access.account_state') }}</dt><dd>{{ __('admin.user_statuses.'.$owner->status) }}</dd></div><div><dt>{{ __('access.shop_state') }}</dt><dd>{{ __('admin.statuses.'.$store->status) }}</dd></div></dl>@endif
@if($owner && $owner->status==='INVITED' && !$owner->email_verified_at && in_array($store->status,['PENDING','ACTIVE']) && !$owner->roles()->where('code','!=','STORE_OWNER')->exists())
<p>{{ __('admin.invite_hint') }}</p><form method="post" action="{{ route('admin.stores.owner-invitation.send',['storeId'=>$store->id]) }}">@csrf<button class="sa-primary sa-submit">{{ __('admin.invite') }}</button></form>
@else<p>{{ __('admin.invite_unavailable') }}</p>@endif
</section>
<section class="sa-panel"><h2>{{ __('admin.activation') }}</h2><p>{{ __('admin.activation_hint') }}</p><form method="post" action="{{ route('admin.stores.update-status',['storeId'=>$store->id]) }}">@csrf @method('PATCH')
<label for="status">{{ __('admin.status') }}</label><select id="status" name="status" required>@foreach(['ACTIVE','INACTIVE'] as $status)@if($store->status!==$status)<option value="{{ $status }}">{{ __('admin.statuses.'.$status) }}</option>@endif @endforeach</select>
<label for="reason">{{ __('admin.reason') }} *</label><textarea id="reason" name="reason" minlength="10" maxlength="500" required rows="3" aria-describedby="reason-help">{{ old('reason') }}</textarea><p id="reason-help">{{ __('admin.reason_hint') }}</p><button class="sa-primary sa-submit">{{ __('admin.apply') }}</button></form></section>
<section class="sa-panel"><h2>{{ __('admin.history') }}</h2>@forelse($events as $event)<article class="sa-event"><strong>{{ __('admin.statuses.'.$event->old_status) }} → {{ __('admin.statuses.'.$event->new_status) }}</strong><p data-private>{{ $event->reason }}</p><time>{{ $event->created_at }}</time></article>@empty<p>{{ __('admin.no_history') }}</p>@endforelse{{ $events->links() }}</section></div>
@endsection
