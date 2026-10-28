@extends('professional.layout')
@section('title',__('catalog.products'))
@section('content')
@php($canInventory=$actor->hasPermission('inventory.view'))
<div class="pc-heading"><div><p class="pro-eyebrow">{{ $store->name }} / {{ __('professional.products') }}</p><h1>{{ __('catalog.products') }}</h1><p>{{ __('catalog.intro') }}</p></div>@can('create',\App\Models\Product::class)@if(!$actor->hasRole('CASHIER'))<a class="pc-button" href="{{ route('professional.products.create') }}">＋ {{ __('catalog.add_product') }}</a>@endif @endcan</div>
@include('professional.catalog.feedback')
<section class="pro-panel pc-panel"><form class="pc-filters" method="get" action="{{ route('professional.products.index') }}">
<label class="pc-search">{{ __('catalog.search') }}<input type="search" name="q" maxlength="100" value="{{ $filters['q']??'' }}"></label>
<label>{{ __('catalog.category') }}<select name="category_id"><option value="">{{ __('catalog.all_categories') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
<label>{{ __('catalog.status') }}<select name="status"><option value="">{{ __('catalog.all_statuses') }}</option>@foreach(['ACTIVE','INACTIVE'] as $state)<option @selected(($filters['status']??'')===$state) value="{{ $state }}">{{ __('catalog.'.$state) }}</option>@endforeach</select></label>
<label>{{ __('catalog.sort') }}<select name="sort">@foreach(['newest','name'] as $sort)<option value="{{ $sort }}" @selected(($filters['sort']??'newest')===$sort)>{{ __('catalog.'.($sort==='name'?'name_sort':'newest')) }}</option>@endforeach</select></label>
<button class="pc-button" type="submit">{{ __('catalog.filter') }}</button><a class="pc-link" href="{{ route('professional.products.index') }}">{{ __('catalog.reset') }}</a></form>
<p class="pc-count">{{ __('catalog.results',['count'=>$products->total()]) }}</p>
@if($products->isEmpty())<div class="pc-empty"><h2>{{ __('catalog.empty') }}</h2><p>{{ __('catalog.empty_hint') }}</p></div>@else
<div class="pc-table"><table><thead><tr>@foreach(['name','category','price','stock','status','actions'] as $label)<th scope="col">{{ __('catalog.'.$label) }}</th>@endforeach</tr></thead><tbody>
@foreach($products as $product)@php($photo=$product->images->first())@php($minimum=$product->variants->sortBy('price')->first()?->price)
@php($defaultVariant=$product->variants->firstWhere('is_default',true)??$product->variants->first())
<tr><td><div class="pc-product-cell">@if($photo)<img class="pc-thumb" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo->path) }}" alt="{{ $photo->alt_text?:$product->name }}" loading="lazy">@else<span class="pc-thumb pc-placeholder" aria-label="{{ __('catalog.no_image') }}">◇</span>@endif<div><a href="{{ route('professional.products.edit',$product) }}">{{ $product->name }}</a><small>{{ __('catalog.sku') }} : {{ $defaultVariant?->sku??'—' }} · {{ $product->variants->count() }} {{ __('catalog.variant') }}(s)</small></div></div></td>
<td data-label="{{ __('catalog.category') }}">{{ $product->category?->name }}</td><td data-label="{{ __('catalog.price') }}">@if($minimum!==null)<strong>{{ number_format($minimum,str_ends_with($minimum,'.00')?0:2,',',' ') }} FCFA</strong>@else—@endif</td>
<td data-label="{{ __('catalog.stock') }}">@if($canInventory){{ $product->variants->sum(fn($variant)=>(int)($variant->stockLevel?->physical_quantity??0)-(int)($variant->stockLevel?->reserved_quantity??0)) }}@else—@endif</td>
<td data-label="{{ __('catalog.status') }}"><span class="pc-status {{ $product->status==='ACTIVE'?'is-active':'' }}">{{ __('catalog.'.$product->status) }}</span></td><td><a class="pc-link" href="{{ route('professional.products.edit',$product) }}">{{ __('catalog.edit') }} →</a></td></tr>@endforeach
</tbody></table></div>{{ $products->links() }}@endif</section>
@endsection
