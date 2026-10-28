<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Deliveries\UpdateDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Support\DeliveryAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeliveryAgentController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = DeliveryAccess::agent($request->user('web'));
        $store = $actor->store()->firstOrFail();
        $settings = StoreSetting::where('store_id', $store->id)->first();
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:ASSIGNED,PICKED_UP,DELIVERED,FAILED']]);
        $query = DeliveryAccess::missions($actor);
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $history = $request->routeIs('courier.history');
        if ($history) {
            $query->whereIn('status', ['DELIVERED', 'FAILED']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (trim($filters['q'] ?? '') !== '') {
            $term = '%'.trim($filters['q']).'%';
            $query->whereHas('order', fn ($orders) => $orders->where(fn ($search) => $search->where('order_number', 'like', $term)->orWhere('customer_name', 'like', $term)));
        }
        $deliveries = $query->with(['order:id,store_id,order_number,customer_name,delivery_address,delivery_city,delivery_area,status'])
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->withQueryString();

        return response()->view('professional.courier.index', compact('actor', 'store', 'settings', 'counts', 'deliveries', 'filters', 'history'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(Request $request, int $deliveryId): Response
    {
        $actor = DeliveryAccess::agent($request->user('web'));
        $store = $actor->store()->firstOrFail();
        $settings = StoreSetting::where('store_id', $store->id)->first();
        $delivery = DeliveryAccess::missions($actor)->with(['order.items'])->whereKey($deliveryId)->firstOrFail();
        $order = $delivery->order;

        return response()->view('professional.courier.show', compact('actor', 'store', 'settings', 'delivery', 'order'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, int $deliveryId, UpdateDeliveryStatus $action): RedirectResponse
    {
        DeliveryAccess::agent($request->user('web'), 'deliveries.update');
        DeliveryAccess::missions($request->user('web'))->whereKey($deliveryId)->firstOrFail();
        $data = $request->validate([
            'status' => ['required', 'in:PICKED_UP,DELIVERED,FAILED'],
            'confirmed' => ['required', 'accepted'],
            'failure_reason' => ['required_if:status,FAILED', 'nullable', 'string', 'max:1000'],
            'payment_status' => ['prohibited'], 'assigned_to' => ['prohibited'], 'store_id' => ['prohibited'],
            'total' => ['prohibited'], 'delivery_fee' => ['prohibited'],
        ]);
        $action->execute($deliveryId, $data['status'], $data['failure_reason'] ?? null);

        return to_route('courier.show', $deliveryId)->with('courier_success', __('deliveries.status_updated'));
    }
}
