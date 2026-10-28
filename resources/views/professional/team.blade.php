@extends('professional.layout')
@section('title', __('team.title'))
@section('content')
<section class="pro-hero"><div><p class="pro-eyebrow">{{ $store->name }}</p><h1>{{ __('team.title') }}</h1><p>{{ __('team.intro') }}</p></div></section>
@if(session('status'))<p class="team-feedback" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="team-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="team-layout"><section class="pro-panel">
<form method="get" action="{{ route('employees.index') }}" class="team-filters">
<label>{{ __('team.search') }}<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="150" type="search"></label>
<label>{{ __('team.role') }}<select name="role"><option value="">{{ __('team.all') }}</option>@foreach(\App\Support\EmployeeAccess::ROLES as $role)<option value="{{ $role }}" @selected(($filters['role'] ?? '')===$role)>{{ __('team.roles.'.$role) }}</option>@endforeach</select></label>
<label>{{ __('team.status') }}<select name="status"><option value="">{{ __('team.all') }}</option>@foreach(['INVITED','ACTIVE','INACTIVE'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '')===$status)>{{ __('team.statuses.'.$status) }}</option>@endforeach</select></label>
<button class="team-button" type="submit">{{ __('team.filter') }}</button><a href="{{ route('employees.index') }}">{{ __('team.reset') }}</a></form>
<div class="team-members">@forelse($employees as $employee)<article class="team-member">
<div class="team-member-heading"><div><h2>{{ $employee->name }}</h2><p data-private>{{ $employee->email }}</p><p data-private>{{ $employee->phone ?: '—' }}</p></div><span class="team-badge">{{ __('team.statuses.'.$employee->status) }}</span></div>
<dl><div><dt>{{ __('team.role') }}</dt><dd>@foreach($employee->roles as $role){{ __('team.roles.'.$role->code) }}@unless($loop->last), @endunless @endforeach</dd></div><div><dt>{{ __('team.invitation') }}</dt><dd>{{ __('team.states.'.$employee->invitation_state) }}</dd></div><div><dt>{{ __('team.created') }}</dt><dd>{{ $employee->created_at?->format('d/m/Y H:i') }}</dd></div></dl>
@if(\App\Support\EmployeeAccess::employee($employee))<div class="team-actions" aria-label="{{ __('team.actions') }} — {{ $employee->name }}">
@if($employee->status==='INVITED' && $employee->email_verified_at===null)
<form method="post" action="{{ route('employees.invitation.resend', $employee->id) }}">@csrf<button type="submit" class="team-button">{{ __('team.resend') }}</button></form>
<form method="post" action="{{ route('employees.invitation.revoke', $employee->id) }}">@csrf @method('DELETE')<button type="submit" class="team-button team-danger">{{ __('team.revoke') }}</button></form>
@endif
@if($employee->email_verified_at!==null)<form method="post" action="{{ route('employees.update-status', $employee->id) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $employee->status==='INACTIVE'?'ACTIVE':'INACTIVE' }}"><button type="submit" class="team-button {{ $employee->status==='INACTIVE'?'':'team-danger' }}">{{ $employee->status==='INACTIVE'?__('team.enable'):__('team.disable') }}</button></form>@endif
</div>@endif</article>@empty<p class="pro-empty">{{ __('team.empty') }}</p>@endforelse</div>
{{ $employees->links() }}<p class="pro-api-note">{{ __('team.state_hint') }}</p></section>
<section class="pro-panel team-invite"><h2>{{ __('team.invite') }}</h2><p>{{ __('team.form_hint') }}</p>
<form method="post" action="{{ route('employees.store') }}">@csrf
<label for="team-name">{{ __('team.name') }} *</label><input id="team-name" name="name" value="{{ old('name') }}" maxlength="150" autocomplete="name" required>
<label for="team-email">{{ __('team.email') }} *</label><input id="team-email" name="email" value="{{ old('email') }}" type="email" maxlength="255" autocomplete="email" required>
<label for="team-phone">{{ __('team.phone') }} *</label><input id="team-phone" name="phone" value="{{ old('phone') }}" type="tel" maxlength="64" autocomplete="tel" aria-describedby="team-phone-hint" required><small id="team-phone-hint">{{ __('team.phone_hint') }}</small>
<label for="team-role">{{ __('team.role') }} *</label><select id="team-role" name="role" required>@foreach(\App\Support\EmployeeAccess::ROLES as $role)<option value="{{ $role }}" @selected(old('role')===$role)>{{ __('team.roles.'.$role) }}</option>@endforeach</select>
<button class="team-button team-primary" type="submit">{{ __('team.invite') }}</button></form></section></div>
@endsection
