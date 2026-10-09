<?php

namespace App\Actions\Orders;


use App\Models\DeliveryZone;
use App\Actions\Customers\FindOrCreateCustomer;
use App\Actions\Stock\CreateStockReservation;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    public function __construct(
        private readonly FindOrCreateCustomer $findOrCreateCustomer,
        private readonly OrderPriceCalculator $priceCalculator,
        private readonly GenerateOrderNumber $generateOrderNumber,
        private readonly CreateStockReservation $createStockReservation,
    ) {}

    public function execute(
        string $customerName,
        string $customerPhone,
        ?string $customerEmail,
        string $fulfillmentType,
        array $items,
        ?int $deliveryZoneId = null,
        ?string $deliveryAddress = null,
        ?string $deliveryArea = null,
        ?string $deliveryInstructions = null,

    ): Order {

        if (! in_array($fulfillmentType, ['PICKUP', 'DELIVERY'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'fulfillment_type' => 'Le type de livraison doit être PICKUP ou DELIVERY.',
            ]);
        }

        if ($fulfillmentType === 'DELIVERY' && $deliveryZoneId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'delivery_zone_id' => 'Une zone de livraison est obligatoire pour une livraison.',
            ]);
        }

        if (
            $fulfillmentType === 'DELIVERY'
            && ($deliveryAddress === null || trim($deliveryAddress) === '')
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'delivery_address' => 'Une adresse de livraison est obligatoire.',
            ]);
        }

        if (empty($items)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'items' => 'La commande doit contenir au moins un article.',
            ]);
        }

        $mergedItems = [];

        foreach ($items as $item) {
            $variantId = $item['product_variant_id'] ?? null;
            $quantity = $item['quantity'] ?? null;

            if (
                filter_var($variantId, FILTER_VALIDATE_INT) === false
                || (int) $variantId <= 0
            ) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'items' => 'Chaque article doit contenir une variante valide.',
                ]);
            }

            if (
                filter_var($quantity, FILTER_VALIDATE_INT) === false
                || (int) $quantity <= 0
            ) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'quantity' => 'La quantité doit être un entier strictement positif.',
                ]);
            }

            $variantId = (int) $variantId;
            $quantity = (int) $quantity;

            if (isset($mergedItems[$variantId])) {
                $mergedItems[$variantId]['quantity'] += $quantity;
            } else {
                $mergedItems[$variantId] = [
                    'product_variant_id' => $variantId,
                    'quantity' => $quantity,
                ];
            }
        }

        $items = array_values($mergedItems);

        return DB::transaction(function () use (
            $customerName,
            $customerPhone,
            $customerEmail,
            $fulfillmentType,
            $items,
            $deliveryZoneId,
            $deliveryAddress,
            $deliveryArea,
            $deliveryInstructions,
        ) {
            $customer = $this->findOrCreateCustomer->execute(
                name: $customerName,
                phone: $customerPhone,
                email: $customerEmail,
            );

            $deliveryZone = null;
            $deliveryFee = '0.00';

            if ($fulfillmentType === 'DELIVERY') {
                $deliveryZone = DeliveryZone::query()
                    ->where('status', 'ACTIVE')
                    ->findOrFail($deliveryZoneId);

                $deliveryFee = $deliveryZone->fee;
            }

            $preparedItems = [];


            foreach ($items as $item) {
                $variant = ProductVariant::query()
                    ->with('product')
                    ->findOrFail($item['product_variant_id']);

                if ($variant->status !== 'ACTIVE') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => 'Cette variante de produit n’est pas disponible à la vente.',
                    ]);
                }

                if ($variant->product->status !== 'ACTIVE') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => 'Ce produit n’est pas disponible à la vente.',
                    ]);
                }

                $quantity = $item['quantity'] ?? null;

                if (
                    filter_var($quantity, FILTER_VALIDATE_INT) === false
                    || (int) $quantity <= 0
                ) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'quantity' => 'La quantité doit être un entier strictement positif.',
                    ]);
                }

                $quantity = (int) $quantity;
                $unitPrice = $variant->price;
                $finalUnitPrice = $variant->effectivePrice();

                $preparedItems[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'final_unit_price' => $finalUnitPrice,
                ];
            }

            $amounts = $this->priceCalculator->calculate(
                items: array_map(
                    fn(array $item) => [
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'final_unit_price' => $item['final_unit_price'],
                    ],
                    $preparedItems
                ),
                deliveryFee: $deliveryFee,
            );

            $reservationExpiresAt = now()->addMinutes(30);

            $order = Order::create([
                'customer_id' => $customer->id,
                'order_number' => $this->generateOrderNumber->execute(),
                'status' => 'PENDING',
                'payment_status' => 'UNPAID',
                'fulfillment_type' => $fulfillmentType,
                'delivery_zone_name' => $deliveryZone?->name,
                'delivery_city' => $deliveryZone?->city,
                'delivery_area' => $fulfillmentType === 'DELIVERY' && $deliveryArea !== null
                    ? trim($deliveryArea)
                    : null,

                'delivery_address' => $fulfillmentType === 'DELIVERY' && $deliveryAddress !== null
                    ? trim($deliveryAddress)
                    : null,

                'delivery_instructions' => $fulfillmentType === 'DELIVERY' && $deliveryInstructions !== null
                    ? trim($deliveryInstructions)
                    : null,
                // Snapshot des informations du client au moment du checkout.
                'customer_name' => $customerName,
                'customer_phone' => $customer->phone,
                'customer_email' => $customerEmail,

                'subtotal' => $amounts['subtotal'],
                'discount_amount' => $amounts['discount_amount'],
                'delivery_fee' => $amounts['delivery_fee'],
                'total' => $amounts['total'],
                'reservation_expires_at' => $reservationExpiresAt,
            ]);

            foreach ($preparedItems as $preparedItem) {
                $variant = $preparedItem['variant'];
                $quantity = $preparedItem['quantity'];

                $unitPrice = $this->priceCalculator->toFcfa(
                    $preparedItem['unit_price']
                );

                $finalUnitPrice = $this->priceCalculator->toFcfa(
                    $preparedItem['final_unit_price']
                );

                $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_description' => null,
                    'sku' => $variant->sku,
                    'unit_price' => $unitPrice . '.00',
                    'discount_percentage' => null,
                    'discount_amount' => ($unitPrice - $finalUnitPrice) . '.00',
                    'final_unit_price' => $finalUnitPrice . '.00',
                    'quantity' => $quantity,
                    'line_total' => ($finalUnitPrice * $quantity) . '.00',
                ]);

                $this->createStockReservation->execute(
                    productVariantId: $variant->id,
                    quantity: $quantity,
                    expiresAt: $reservationExpiresAt,
                    referenceType: 'ORDER',
                    referenceId: $order->id,
                );
            }

            return $order->load('items');
        });
    }
}
