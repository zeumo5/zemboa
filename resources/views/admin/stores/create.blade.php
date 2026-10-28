@extends('admin.layout')
@section('title',__('admin.create'))
@section('content')
<a class="sa-back" href="{{ route('admin.stores.index') }}">← {{ __('admin.back') }}</a><h1>{{ __('admin.create') }}</h1>
<p class="sa-lead">{{ __('admin.creation_hint') }}</p><p>{{ __('admin.required') }}</p>
<form method="post" action="{{ route('admin.stores.store') }}" class="sa-form-grid">@csrf
<section class="sa-panel"><h2>{{ __('admin.details') }}</h2>
@foreach(['name'=>150,'slug'=>160,'email'=>255,'phone'=>255] as $field=>$max)
<label for="store-{{ $field }}">{{ __('admin.'.$field) }} @if(in_array($field,['name','slug'])) * @endif</label><input id="store-{{ $field }}" name="{{ $field }}" type="{{ $field==='email'?'email':($field==='phone'?'tel':'text') }}" value="{{ old($field) }}" maxlength="{{ $max }}" @if(in_array($field,['name','slug'])) required @endif @if($field==='slug') pattern="[a-z0-9]+(-[a-z0-9]+)*" @endif @error($field) aria-invalid="true" aria-describedby="error-{{ $field }}" @enderror>
@error($field)<p id="error-{{ $field }}" class="sa-field-error">{{ $message }}</p>@enderror
@endforeach
@foreach(['description','address'] as $field)<label for="store-{{ $field }}">{{ __('admin.'.$field) }}</label><textarea id="store-{{ $field }}" name="{{ $field }}" rows="3">{{ old($field) }}</textarea>@endforeach
</section>
<section class="sa-panel"><h2>{{ __('admin.new_owner') }}</h2><p>{{ __('admin.owner_hint') }}</p>
@foreach(['owner_name','owner_email'] as $field)<label for="{{ $field }}">{{ __('admin.'.$field) }} *</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $field==='owner_email'?'email':'text' }}" value="{{ old($field) }}" maxlength="{{ $field==='owner_name'?150:255 }}" required @error($field) aria-invalid="true" aria-describedby="error-{{ $field }}" @enderror>@error($field)<p class="sa-field-error" id="error-{{ $field }}">{{ $message }}</p>@enderror @endforeach
<button class="sa-primary sa-submit" type="submit">{{ __('admin.create') }}</button></section>
</form>
@endsection
