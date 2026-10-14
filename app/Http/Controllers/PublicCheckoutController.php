<?php
namespace App\Http\Controllers;


use App\Actions\Orders\CreateOrder;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicCheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CreateOrder $createOrder
    ) {}

    public function store(
        Request $request,
        string $shopSlug
    ): JsonResponse {
        // 1. Valider les informations fournies par le client.
        $data = $request->validate([
            'customer_name' => [
                'required', 'string', 'max:150',
            ],
            'customer_phone' => [
                'required', 'string', 'max:30',
            ],
            'customer_email' => [
                'nullable', 'email', 'max:255',
            ],
            'fulfillment_type' => [
                'required',
                Rule::in(['PICKUP', 'DELIVERY']),
            ],
            'delivery_zone_id' => [
                'nullable', 'integer', 'min:1',
            ],
            'delivery_address' => [
                'nullable', 'string', 'max:500',
            ],
            'delivery_area' => [
                'nullable', 'string', 'max:150',
            ],
            'delivery_instructions' => [
                'nullable', 'string', 'max:1000',
            ],
        ]);

        // 2. Récupérer le panier de la boutique courante.
        $items = $this->cart->items();

        if ($items === []) {
            throw ValidationException::withMessages([
                'cart' => 'Votre panier est vide.',
            ]);
        }

        // 3. Vérifier les articles et leur disponibilité.
        $summary = $this->cart->summary();

        if (! $summary['can_checkout']) {
            throw ValidationException::withMessages([
                'cart' =>
                    'Certains articles ne sont plus disponibles. Vérifiez votre panier.',
            ]);
        }

        // 4. Préparer uniquement les identifiants et quantités.
        // Aucun prix provenant du navigateur n'est accepté.
        $orderItems = [];

        foreach ($items as $variantId => $quantity) {
            $orderItems[] = [
                'product_variant_id' => (int) $variantId,
                'quantity' => (int) $quantity,
            ];
        }

        // 5. Créer la commande via la logique métier existante.
        // CreateOrder recalcule les prix et réserve le stock.
        $order = $this->createOrder->execute(
            customerName: $data['customer_name'],
            customerPhone: $data['customer_phone'],
            customerEmail: $data['customer_email'] ?? null,
            fulfillmentType: $data['fulfillment_type'],
            items: $orderItems,
            deliveryZoneId: $data['delivery_zone_id'] ?? null,
            deliveryAddress: $data['delivery_address'] ?? null,
            deliveryArea: $data['delivery_area'] ?? null,
            deliveryInstructions: $data['delivery_instructions'] ?? null
        );

        // 6. Vider le panier seulement après réussite.
        $this->cart->clear();

        // 7. Retourner les informations nécessaires au client.
        return response()->json([
            'message' => 'Commande créée avec succès.',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'delivery_fee' => $order->delivery_fee,
                'total' => $order->total,
            ],
        ], 201);
    }
}
