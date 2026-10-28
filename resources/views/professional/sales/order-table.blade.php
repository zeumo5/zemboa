<div class="pc-table"><table><thead><tr>@foreach(['number','name','total','payment','status','mode'] as $label)<th scope="col">{{ __('sales.'.$label) }}</th>@endforeach</tr></thead><tbody>
@forelse($orders as $row)@include('professional.sales.order-row')@empty<tr><td colspan="6">{{ __('sales.none') }}</td></tr>@endforelse
</tbody></table></div>
