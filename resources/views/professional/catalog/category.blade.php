@extends('professional.layout')
@section('title',__($category->exists?'catalog.edit_category':'catalog.add_category'))
@section('content')
<a class="pc-link" href="{{ route('professional.categories.index') }}">← {{ __('catalog.categories') }}</a><div class="pc-heading"><div><h1>{{ __($category->exists?'catalog.edit_category':'catalog.add_category') }}</h1><p>{{ __('catalog.category_hint') }}</p></div></div>
@include('professional.catalog.feedback')
<form method="post" class="pro-panel pc-form" action="{{ route($category->exists?'professional.categories.update':'professional.categories.store',$category->exists?$category:[]) }}">@csrf
@if($category->exists)@method('PUT')<input type="hidden" name="version" value="{{ old('version',\App\Support\CatalogAccess::version($category)) }}">@endif
<div class="pc-fields"><label>{{ __('catalog.name') }} *<input name="name" maxlength="150" value="{{ old('name',$category->name) }}" required></label><label>{{ __('catalog.slug') }} *<input name="slug" maxlength="180" value="{{ old('slug',$category->slug) }}" required></label><label class="pc-wide">{{ __('catalog.description') }}<textarea name="description" rows="5">{{ old('description',$category->description) }}</textarea></label><label>{{ __('catalog.status') }}<select name="status">@foreach(['ACTIVE','INACTIVE'] as $state)<option value="{{ $state }}" @selected(old('status',$category->status??'ACTIVE')===$state)>{{ __('catalog.'.$state) }}</option>@endforeach</select></label></div>
<p class="pc-note">{{ __('catalog.category_image_note') }}</p><div class="pc-form-actions"><a class="pc-link" href="{{ route('professional.categories.index') }}">{{ __('catalog.cancel') }}</a><button type="submit" class="pc-button">{{ __('catalog.save') }}</button></div></form>
@endsection
