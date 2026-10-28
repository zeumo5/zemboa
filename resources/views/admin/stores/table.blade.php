@if($stores->isEmpty())<p class="sa-empty">{{ __('admin.empty') }}</p>@else
<table class="sa-table"><thead><tr><th>{{ __('admin.name') }}</th>@if($showOwners)<th>{{ __('admin.owner') }}</th>@endif<th>{{ __('admin.status') }}</th><th>{{ __('admin.date') }}</th><th>{{ __('admin.view') }}</th></tr></thead><tbody>
@foreach($stores as $store)<tr><td data-label="{{ __('admin.name') }}"><strong>{{ $store->name }}</strong><small>{{ $store->slug }}</small></td>
@if($showOwners)<td data-label="{{ __('admin.owner') }}" data-private>@forelse($store->users as $owner)<div>{{ $owner->name }}<small>{{ $owner->email }}</small></div>@empty{{ __('admin.not_recorded') }}@endforelse</td>@endif
<td data-label="{{ __('admin.status') }}"><span class="sa-badge sa-badge-{{ strtolower($store->status) }}">{{ __('admin.statuses.'.$store->status) }}</span></td><td data-label="{{ __('admin.date') }}">{{ $store->created_at?->format('d/m/Y') }}</td><td><a class="sa-secondary" href="{{ route('admin.stores.show',['storeId'=>$store->id]) }}">{{ __('admin.view') }} →</a></td></tr>@endforeach
</tbody></table>@endif
