<?php
namespace App\Http\Controllers;


use App\Models\Order;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Liste des commandes de la boutique courante.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewer($request);

        $orders = Order::query()
            ->select([
                'id',
                'order_number',
                'status',
                'payment_status',
                'fulfillment_type',
                'customer_name',
                'customer_phone',
                'total',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * Détails d'une commande de la boutique courante.
     */
    public function show(
        Request $request,
        int $orderId
    ): JsonResponse {
        $this->authorizeViewer($request);

        $order = Order::query()
            ->select([
                'id',
                'order_number',
                'status',
                'payment_status',
                'fulfillment_type',
                'customer_name',
                'customer_phone',
                'delivery_city',
                'delivery_area',
                'delivery_address',
                'subtotal',
                'discount_amount',
                'delivery_fee',
                'total',
                'created_at',
            ])
            ->with([
                'items:id,order_id,product_name,sku,quantity,final_unit_price,line_total',
            ])
            ->whereKey($orderId)
            ->firstOrFail();

        return response()->json([
            'order' => $order,
        ]);
    }

    /**
     * Propriétaire ou gestionnaire uniquement.
     */
    private function authorizeViewer(Request $request): User
    {
        $user = $request->user();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !==
                app(TenantContext::class)->storeId() ||
            ! $user->hasPermission('orders.view') ||
            (
                ! $user->hasRole('STORE_OWNER') &&
                ! $user->hasRole('STORE_MANAGER')
            )
        ) {
            throw new AuthorizationException(
                'Consultation des commandes non autorisée.'
            );
        }

        return $user;
    }
}
