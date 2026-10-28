<?php

namespace App\Services;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\OrderPriceCalculator;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Support\BuyerIdentity;
use App\Support\PublicShopping;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** HTTP checkout orchestration only. CreateOrder remains the sole order action. */
final class PublicCheckout
{
    public function __construct(private CartService $cart, private CreateOrder $createOrder) {}

    public function ready(): void
    {
        PublicShopping::assertAllowed();
        BuyerIdentity::assertSchemaReady();
        foreach (['buyer_user_id', 'checkout_key_hash', 'checkout_request_hash', 'checkout_actor_hash', 'checkout_expires_at'] as $column) {
            abort_unless(Schema::hasColumn('orders', $column), 503, __('checkout.setup'));
        }
        abort_unless(Schema::hasIndex('orders', 'orders_checkout_key_unique', 'unique'), 503, __('checkout.setup'));
    }

    private function actor(): string
    {
        $buyer = Auth::guard('buyer')->user();
        abort_if($buyer && ! $buyer->canUseBuyerAccount(), 403);
        $identity = $buyer ? 'buyer:'.$buyer->id : 'guest:'.session()->getId();
        abort_unless(is_string(config('app.key')) && strlen(config('app.key')) >= 32, 503);

        return hash_hmac('sha256', $identity, config('app.key'));
    }

    private function cartHash(): string
    {
        return hash('sha256', json_encode(session('zemboa.cart', []), JSON_THROW_ON_ERROR));
    }

    /** Server-priced review. Only financial fields enter the signed digest. */
    public function quote(string $fulfillment = 'PICKUP', ?int $zoneId = null, ?CarbonInterface $at = null): array
    {
        $summary = $this->cart->summary($at ?? now());
        $fee = $fulfillment === 'DELIVERY' && $zoneId !== null
            ? app(OrderPriceCalculator::class)->deliveryFee(DeliveryZone::where('status', 'ACTIVE')->findOrFail($zoneId)->getRawOriginal('fee')) : '0.00';
        $lines = array_map(fn ($line) => [
            'id' => $line['product_variant_id'] ?? null, 'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'] ?? '0.00', 'final_unit_price' => $line['final_unit_price'] ?? '0.00',
        ], $summary['details']);
        $total = app(OrderPriceCalculator::class)->calculate($lines, $fee);

        return ['summary' => $summary, 'total' => $total,
            'hash' => hash('sha256', json_encode([$lines, $total, $fulfillment, $zoneId], JSON_THROW_ON_ERROR))];
    }

    public function token(?array $quote = null): ?string
    {
        $this->ready();
        if (! $this->cart->items()) {
            return null;
        }
        $payload = base64_encode(json_encode(['store' => app(TenantContext::class)->storeId(),
            'actor' => $this->actor(), 'cart' => $this->cartHash(), 'quote' => ($quote ?? $this->quote())['hash'], 'expires' => now()->addHours(2)->timestamp], JSON_THROW_ON_ERROR));
        $token = $payload.'.'.hash_hmac('sha256', $payload, config('app.key'));
        session()->put('zemboa.checkout.'.app(TenantContext::class)->storeId(), $token);

        return $token;
    }

    public function submit(array $data, ?string $token): Order
    {
        $this->ready();
        $storeId = app(TenantContext::class)->storeId();
        $token ??= session('zemboa.checkout.'.$storeId);
        if (! $token && ! $this->cart->items()) {
            throw ValidationException::withMessages(['cart' => __('orders.cart_empty')]);
        }
        if (! $token) {
            throw ValidationException::withMessages(['checkout_token' => __('promotions.review_required')]);
        }
        abort_unless(strlen($token) <= 2048 && substr_count($token, '.') === 1, 419, __('checkout.expired'));
        [$encoded, $signature] = explode('.', $token, 2);
        abort_unless(hash_equals(hash_hmac('sha256', $encoded, config('app.key')), $signature), 419, __('checkout.expired'));
        $intent = json_decode(base64_decode($encoded, true) ?: '', true);
        abort_unless(is_array($intent) && ($intent['store'] ?? null) === $storeId
            && ($intent['actor'] ?? null) === $this->actor() && is_int($intent['expires'] ?? null)
            && $intent['expires'] > now()->timestamp && is_string($intent['cart'] ?? null) && is_string($intent['quote'] ?? null), 419, __('checkout.expired'));
        $key = hash('sha256', $storeId.'|'.$intent['actor'].'|'.$intent['cart']);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = Order::where('checkout_key_hash', $key)->first();
        if ($existing) {
            return $this->replay($existing, $fingerprint, $intent['actor']);
        }
        abort_unless(hash_equals($intent['cart'], $this->cartHash()), 409, __('checkout.changed'));
        $summary = $this->cart->summary();
        if (! $summary['can_checkout']) {
            throw ValidationException::withMessages(['cart' => __('orders.items_unavailable')]);
        }
        $items = [];
        foreach ($this->cart->items() as $id => $quantity) {
            $items[] = ['product_variant_id' => (int) $id, 'quantity' => (int) $quantity];
        }
        try {
            $order = DB::transaction(function () use ($data, $items, $key, $fingerprint, $intent) {
                $ids = array_column($items, 'product_variant_id');
                sort($ids, SORT_NUMERIC);
                ProductVariant::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($data['fulfillment_type'] === 'DELIVERY') {
                    DeliveryZone::whereKey($data['delivery_zone_id'])->lockForUpdate()->firstOrFail();
                }
                $at = now();
                $quote = $this->quote($data['fulfillment_type'], $data['delivery_zone_id'], $at);
                if (! hash_equals($intent['quote'], $quote['hash'])) {
                    throw ValidationException::withMessages(['checkout_token' => __('promotions.quote_changed')]);
                }
                $order = $this->createOrder->execute(
                    customerName: $data['customer_name'], customerPhone: $data['customer_phone'],
                    customerEmail: $data['customer_email'], fulfillmentType: $data['fulfillment_type'], items: $items,
                    deliveryZoneId: $data['delivery_zone_id'], deliveryAddress: $data['delivery_address'],
                    deliveryArea: $data['delivery_area'], deliveryInstructions: $data['delivery_instructions'], pricedAt: $at);
                abort_unless($order->total === $quote['total']['total'], 409, __('promotions.quote_changed'));
                $order->forceFill(['buyer_user_id' => Auth::guard('buyer')->id(), 'checkout_key_hash' => $key,
                    'checkout_request_hash' => $fingerprint, 'checkout_actor_hash' => $intent['actor'],
                    'checkout_expires_at' => Carbon::createFromTimestamp($intent['expires'])])->save();

                return $order;
            });
        } catch (UniqueConstraintViolationException $error) {
            $order = Order::where('checkout_key_hash', $key)->first();
            if (! $order) {
                throw $error;
            }

            return $this->replay($order, $fingerprint, $intent['actor']);
        }
        // SQL success precedes session save. A retry of an old session/cart finds
        // the durable order first and never reserves a second time.
        $this->cart->clear();

        return $order;
    }

    private function replay(Order $order, string $fingerprint, string $actor): Order
    {
        abort_unless(hash_equals($order->checkout_actor_hash, $actor), 403);
        abort_if($order->buyer_user_id === null && (! $order->checkout_expires_at || $order->checkout_expires_at->isPast()), 419, __('checkout.expired'));
        abort_unless(hash_equals($order->checkout_request_hash, $fingerprint), 409, __('checkout.changed'));
        $currentKey = hash('sha256', app(TenantContext::class)->storeId().'|'.$actor.'|'.$this->cartHash());
        if ($this->cart->items() && hash_equals($order->checkout_key_hash, $currentKey)) {
            $this->cart->clear();
        }

        // A newer cart has a different revision and is never cleared by recovery.
        return $order->load('items');
    }

    public function receipt(int $id): Order
    {
        $this->ready();
        $order = Order::whereKey($id)->where('checkout_actor_hash', $this->actor())->firstOrFail();
        if (Auth::guard('buyer')->check()) {
            abort_unless((int) $order->buyer_user_id === Auth::guard('buyer')->id(), 404);
        } else {
            abort_unless($order->buyer_user_id === null && $order->checkout_expires_at
                && $order->checkout_expires_at->isFuture(), 404);
        }

        return $order->load('items');
    }
}
