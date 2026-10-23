<?php

namespace App\Services;

use App\Actions\Orders\CreateOrder;
use App\Models\Order;
use App\Support\BuyerIdentity;
use App\Support\TenantContext;
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
        BuyerIdentity::assertSchemaReady();
        foreach (['buyer_user_id', 'checkout_key_hash', 'checkout_request_hash', 'checkout_actor_hash', 'checkout_expires_at'] as $column) {
            abort_unless(Schema::hasColumn('orders', $column), 503, __('checkout.setup'));
        }
        abort_unless(Schema::hasIndex('orders', 'orders_checkout_key_unique', 'unique'), 503, __('checkout.setup'));
    }

    private function actor(): string
    {
        $buyer = Auth::guard('buyer')->user();
        abort_if($buyer && !$buyer->canUseBuyerAccount(), 403);
        $identity = $buyer ? 'buyer:'.$buyer->id : 'guest:'.session()->getId();
        abort_unless(is_string(config('app.key')) && strlen(config('app.key')) >= 32, 503);
        return hash_hmac('sha256', $identity, config('app.key'));
    }

    private function cartHash(): string
    {
        return hash('sha256', json_encode(session('zemboa.cart', []), JSON_THROW_ON_ERROR));
    }

    public function token(): ?string
    {
        $this->ready();
        if (!$this->cart->items()) return null;
        $payload = base64_encode(json_encode(['store' => app(TenantContext::class)->storeId(),
            'actor' => $this->actor(), 'cart' => $this->cartHash(), 'expires' => now()->addHours(2)->timestamp], JSON_THROW_ON_ERROR));
        $token = $payload.'.'.hash_hmac('sha256', $payload, config('app.key'));
        session()->put('zemboa.checkout.'.app(TenantContext::class)->storeId(), $token);
        return $token;
    }

    public function submit(array $data, ?string $token): Order
    {
        $this->ready();
        $storeId = app(TenantContext::class)->storeId();
        // Compatibility for existing JSON clients: derive the same durable key from
        // their already persisted cart revision, even if the success response is lost.
        $token ??= $this->cart->items() ? $this->token() : session('zemboa.checkout.'.$storeId);
        if (!$token) throw ValidationException::withMessages(['cart' => __('orders.cart_empty')]);
        abort_unless(strlen($token) <= 2048 && substr_count($token, '.') === 1, 419, __('checkout.expired'));
        [$encoded, $signature] = explode('.', $token, 2);
        abort_unless(hash_equals(hash_hmac('sha256', $encoded, config('app.key')), $signature), 419, __('checkout.expired'));
        $intent = json_decode(base64_decode($encoded, true) ?: '', true);
        abort_unless(is_array($intent) && ($intent['store'] ?? null) === $storeId
            && ($intent['actor'] ?? null) === $this->actor() && is_int($intent['expires'] ?? null)
            && $intent['expires'] > now()->timestamp && is_string($intent['cart'] ?? null), 419, __('checkout.expired'));
        $key = hash('sha256', $storeId.'|'.$intent['actor'].'|'.$intent['cart']);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = Order::where('checkout_key_hash', $key)->first();
        if ($existing) return $this->replay($existing, $fingerprint, $intent['actor']);
        abort_unless(hash_equals($intent['cart'], $this->cartHash()), 409, __('checkout.changed'));
        $summary = $this->cart->summary();
        if (!$summary['can_checkout']) throw ValidationException::withMessages(['cart' => __('orders.items_unavailable')]);
        $items = [];
        foreach ($this->cart->items() as $id => $quantity) $items[] = ['product_variant_id' => (int) $id, 'quantity' => (int) $quantity];
        try {
            $order = DB::transaction(function () use ($data, $items, $key, $fingerprint, $intent) {
                $order = $this->createOrder->execute(
                    customerName: $data['customer_name'], customerPhone: $data['customer_phone'],
                    customerEmail: $data['customer_email'], fulfillmentType: $data['fulfillment_type'], items: $items,
                    deliveryZoneId: $data['delivery_zone_id'], deliveryAddress: $data['delivery_address'],
                    deliveryArea: $data['delivery_area'], deliveryInstructions: $data['delivery_instructions']);
                $order->forceFill(['buyer_user_id' => Auth::guard('buyer')->id(), 'checkout_key_hash' => $key,
                    'checkout_request_hash' => $fingerprint, 'checkout_actor_hash' => $intent['actor'],
                    'checkout_expires_at' => \Carbon\Carbon::createFromTimestamp($intent['expires'])])->save();
                return $order;
            });
        } catch (UniqueConstraintViolationException $error) {
            $order = Order::where('checkout_key_hash', $key)->first();
            if (!$order) throw $error;
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
        abort_if($order->buyer_user_id === null && (!$order->checkout_expires_at || $order->checkout_expires_at->isPast()), 419, __('checkout.expired'));
        abort_unless(hash_equals($order->checkout_request_hash, $fingerprint), 409, __('checkout.changed'));
        $currentKey = hash('sha256', app(TenantContext::class)->storeId().'|'.$actor.'|'.$this->cartHash());
        if ($this->cart->items() && hash_equals($order->checkout_key_hash, $currentKey)) $this->cart->clear();
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
