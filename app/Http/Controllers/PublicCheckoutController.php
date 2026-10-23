<?php

namespace App\Http\Controllers;

use App\Models\DeliveryZone;
use App\Models\StoreSetting;
use App\Services\CartService;
use App\Services\PublicCheckout;
use App\Support\BuyerIdentity;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PublicCheckoutController extends Controller
{
    public function __construct(private CartService $cart, private PublicCheckout $checkout) {}

    public function show(Request $request, string $shopSlug)
    {
        $this->checkout->ready();
        $store = app(TenantContext::class)->store();
        $settings = StoreSetting::first();
        $summary = $this->cart->summary();
        $zones = DeliveryZone::where('status', 'ACTIVE')->orderBy('name')->get();
        $choice = $request->validate(['fulfillment_type' => ['nullable', Rule::in(['PICKUP', 'DELIVERY'])], 'delivery_zone_id' => ['nullable', 'integer', 'min:1']]);
        $fulfillment = $choice['fulfillment_type'] ?? ($settings?->pickup_enabled ? 'PICKUP' : 'DELIVERY');
        $zone = isset($choice['delivery_zone_id']) ? $zones->firstWhere('id', (int) $choice['delivery_zone_id']) : $zones->first();
        abort_if(isset($choice['delivery_zone_id']) && !$zone, 422, __('checkout.no_service'));
        $fee = $fulfillment === 'DELIVERY' && $settings?->delivery_enabled ? ($zone?->fee ?? '0.00') : '0.00';
        $total = app(\App\Actions\Orders\OrderPriceCalculator::class)->calculate(array_map(fn ($line) => [
            'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'] ?? '0.00',
            'final_unit_price' => $line['final_unit_price'] ?? '0.00'], $summary['details']), $fee);
        $buyer = Auth::guard('buyer')->user();
        $token = $this->checkout->token();
        if ($request->expectsJson()) return response()->json(['data' => compact('summary', 'token', 'zones', 'fulfillment', 'zone', 'total')])->header('Cache-Control', 'private, no-store');
        return response()->view('marketplace.merchant.checkout', compact('store', 'settings', 'summary', 'zones', 'buyer', 'token', 'fulfillment', 'zone', 'total'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, string $shopSlug)
    {
        $this->checkout->ready();
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['bail', 'required', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (!BuyerIdentity::phoneOrNull($value)) $fail(__('buyer.phone_invalid'));
            }],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'fulfillment_type' => ['required', Rule::in(['PICKUP', 'DELIVERY'])],
            'delivery_zone_id' => ['nullable', 'integer', 'min:1'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_area' => ['nullable', 'string', 'max:150'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
            'checkout_token' => ['nullable', 'string', 'max:2048'],
        ]);
        if (!$request->expectsJson()) {
            $settings = StoreSetting::first();
            abort_unless($settings && ($data['fulfillment_type'] === 'PICKUP' ? $settings->pickup_enabled : $settings->delivery_enabled), 422, __('checkout.no_service'));
            $request->validate(['checkout_token' => ['required', 'string', 'max:2048']]);
        }
        $token = $data['checkout_token'] ?? null;
        $normalized = ['customer_name' => trim($data['customer_name']),
            'customer_phone' => BuyerIdentity::phone($data['customer_phone']),
            'customer_email' => isset($data['customer_email']) ? mb_strtolower(trim($data['customer_email'])) : null,
            'fulfillment_type' => $data['fulfillment_type']];
        foreach (['delivery_zone_id', 'delivery_address', 'delivery_area', 'delivery_instructions'] as $field) {
            $normalized[$field] = $data['fulfillment_type'] === 'DELIVERY' ? ($data[$field] ?? null) : null;
        }
        if ($normalized['delivery_zone_id'] !== null) $normalized['delivery_zone_id'] = (int) $normalized['delivery_zone_id'];
        $order = $this->checkout->submit($normalized, $token);
        $url = route('marketplace.shops.checkout.receipt', ['shopSlug' => $shopSlug, 'orderId' => $order->id]);
        if (!$request->expectsJson()) return redirect($url, 303);
        return response()->json(['message' => __('orders.created_successfully'), 'data' => [
            'order_id' => $order->id, 'order_number' => $order->order_number,
            'status' => $order->status, 'payment_status' => $order->payment_status,
            'subtotal' => $order->subtotal, 'discount_amount' => $order->discount_amount,
            'delivery_fee' => $order->delivery_fee, 'total' => $order->total, 'confirmation_url' => $url,
        ]], 201);
    }

    public function receipt(Request $request, string $shopSlug, int $orderId)
    {
        $order = $this->checkout->receipt($orderId);
        $store = app(TenantContext::class)->store();
        $settings = StoreSetting::first();
        return response()->view('marketplace.merchant.checkout-receipt', compact('order', 'store', 'settings'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
