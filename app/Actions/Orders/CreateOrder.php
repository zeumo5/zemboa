<?php

namespace App\Actions\Orders;

use App\Actions\Customers\FindOrCreateCustomer;
use App\Actions\Stock\CreateStockReservation;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StoreSetting;
use App\Support\BuyerIdentity;
use App\Support\PosAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        bool $allowAnonymousPos = false,
        ?CarbonInterface $pricedAt = null,

    ): Order {
        $withoutCustomerRecord = $allowAnonymousPos && (trim($customerName) === '' || trim($customerPhone) === '');
        if ($allowAnonymousPos) {
            PosAccess::authorize(auth('web')->user());
            abort_unless($fulfillmentType === 'PICKUP', 422);
            if (trim($customerPhone) !== '') {
                $normalized = BuyerIdentity::phoneOrNull($customerPhone);
                if ($normalized === null) {
                    throw ValidationException::withMessages(['customer_phone' => __('team.invalid_phone')]);
                }
                $customerPhone = $normalized;
            }
        }

        if (! in_array($fulfillmentType, ['PICKUP', 'DELIVERY'], true)) {
            throw ValidationException::withMessages([
                'fulfillment_type' => __('orders.fulfillment_invalid'),
            ]);
        }

        if ($fulfillmentType === 'DELIVERY' && $deliveryZoneId === null) {
            throw ValidationException::withMessages([
                'delivery_zone_id' => __('orders.delivery_zone_required'),
            ]);
        }

        if (
            $fulfillmentType === 'DELIVERY'
            && ($deliveryAddress === null || trim($deliveryAddress) === '')
        ) {
            throw ValidationException::withMessages([
                'delivery_address' => __('orders.delivery_address_required'),
            ]);
        }

        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => __('orders.items_required'),
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
                throw ValidationException::withMessages([
                    'items' => __('orders.variant_invalid'),
                ]);
            }

            if (
                filter_var($quantity, FILTER_VALIDATE_INT) === false
                || (int) $quantity <= 0
            ) {
                throw ValidationException::withMessages([
                    'quantity' => __('orders.quantity_invalid'),
                ]);
            }

            $variantId = (int) $variantId;
            $quantity = (int) $quantity;

            if (isset($mergedItems[$variantId])) {
                if ($quantity > PHP_INT_MAX - $mergedItems[$variantId]['quantity']) {
                    throw ValidationException::withMessages(['quantity' => __('orders.quantity_invalid')]);
                }
                $mergedItems[$variantId]['quantity'] += $quantity;
            } else {
                $mergedItems[$variantId] = [
                    'product_variant_id' => $variantId,
                    'quantity' => $quantity,
                ];
            }
        }

        ksort($mergedItems, SORT_NUMERIC);
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
            $withoutCustomerRecord,
            $pricedAt,
        ) {

            $storeSettings = StoreSetting::query()->first();

            if (! $storeSettings || ! $storeSettings->hasCommercialConfiguration()) {
                throw ValidationException::withMessages(['fulfillment_type' => __('checkout.no_service')]);
            }

            if ($storeSettings !== null) {
                if (
                    $fulfillmentType === 'DELIVERY'
                    && ! $storeSettings->delivery_enabled
                ) {
                    throw ValidationException::withMessages([
                        'fulfillment_type' => __('orders.delivery_disabled'),
                    ]);
                }

                if (
                    $fulfillmentType === 'PICKUP'
                    && ! $storeSettings->pickup_enabled
                ) {
                    throw ValidationException::withMessages([
                        'fulfillment_type' => __('orders.pickup_disabled'),
                    ]);
                }
            }

            $pricedAt ??= now();
            $preparedItems = [];

            foreach ($items as $item) {
                $variant = ProductVariant::query()
                    ->with('product')
                    ->lockForUpdate()
                    ->findOrFail($item['product_variant_id']);

                if ($variant->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'items' => __('orders.variant_unavailable'),
                    ]);
                }

                if ($variant->product->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'items' => __('orders.product_unavailable'),
                    ]);
                }

                $quantity = $item['quantity'] ?? null;

                if (
                    filter_var($quantity, FILTER_VALIDATE_INT) === false
                    || (int) $quantity <= 0
                ) {
                    throw ValidationException::withMessages([
                        'quantity' => __('orders.quantity_invalid'),
                    ]);
                }

                $quantity = (int) $quantity;
                $unitPrice = $variant->price;
                $finalUnitPrice = $variant->effectivePrice($pricedAt);

                $preparedItems[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'final_unit_price' => $finalUnitPrice,
                ];
            }

            $deliveryZone = null;
            $deliveryFee = '0.00';

            if ($fulfillmentType === 'DELIVERY') {
                $deliveryZone = DeliveryZone::query()
                    ->where('status', 'ACTIVE')
                    ->lockForUpdate()
                    ->findOrFail($deliveryZoneId);

                $deliveryFee = $this->priceCalculator->deliveryFee($deliveryZone->getRawOriginal('fee'));
            }

            $amounts = $this->priceCalculator->calculate(
                items: array_map(
                    fn (array $item) => [
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'final_unit_price' => $item['final_unit_price'],
                    ],
                    $preparedItems
                ),
                deliveryFee: $deliveryFee,
            );

            if ($this->priceCalculator->toFcfa($amounts['total']) <= 0) {
                throw ValidationException::withMessages(['items' => __('promotions.zero_total')]);
            }

            $customer = $withoutCustomerRecord ? null : $this->findOrCreateCustomer->execute(
                name: $customerName,
                phone: $customerPhone,
                email: $customerEmail,
            );

            $reservationMinutes = $storeSettings
                ? $storeSettings->reservation_duration_minutes
                : 30;

            $reservationExpiresAt = now()->addMinutes(
                $reservationMinutes
            );

            $order = Order::create([
                'customer_id' => $customer?->id,
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
                'customer_name' => $withoutCustomerRecord ? (trim($customerName) ?: null) : $customerName,
                'customer_phone' => $withoutCustomerRecord ? (trim($customerPhone) ?: null) : $customer?->phone,
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
                    'unit_price' => $unitPrice.'.00',
                    'discount_percentage' => null,
                    'discount_amount' => ($unitPrice - $finalUnitPrice).'.00',
                    'final_unit_price' => $finalUnitPrice.'.00',
                    'quantity' => $quantity,
                    'line_total' => ($finalUnitPrice * $quantity).'.00',
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
