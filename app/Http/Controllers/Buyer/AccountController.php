<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buyer;

use App\Actions\Payments\FinalizeOrderPayment;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Support\BuyerIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AccountController extends Controller
{
    private function ownedOrders(): Builder
    {
        $buyer = Auth::guard('buyer')->user();
        abort_unless($buyer && $buyer->canUseBuyerAccount(), 403);
        abort_unless(Schema::hasColumn('orders', 'buyer_user_id'), 503, __('customer.setup'));

        // Global buyer history deliberately crosses stores, never ownership.
        // Remove only this named scope; keep all other model protections.
        return Order::withoutGlobalScope('tenant')->where('buyer_user_id', $buyer->id);
    }

    private function cards(Builder $query): Builder
    {
        return $query->select(['id', 'store_id', 'buyer_user_id', 'order_number', 'status',
            'payment_status', 'fulfillment_type', 'total', 'created_at'])
            ->with('store:id,name,slug,status')->withSum('items as units', 'quantity')
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    private function page(string $view, array $data = []): Response
    {
        $buyer = Auth::guard('buyer')->user();

        return response()->view($view, $data + ['buyer' => $buyer, 'phone' => BuyerIdentity::phoneOrNull($buyer->phone)])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function dashboard(): Response
    {
        $query = $this->ownedOrders();
        $stats = (clone $query)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN payment_status = 'PAID' THEN 1 ELSE 0 END) as paid")->first();
        $orders = $this->cards($query)->limit(3)->get();

        return $this->page('buyer.account', compact('stats', 'orders'));
    }

    public function orders(Request $request): Response
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $orders = $this->cards($this->ownedOrders())->paginate(10);

        return $this->page('buyer.orders', compact('orders'));
    }

    public function show(int $orderId): Response
    {
        $order = $this->ownedOrders()->whereKey($orderId)->first();
        abort_unless($order, 404);
        // Only an owned order can lead to these children. No Customer/profile joins.
        $order->load(['store:id,name,slug,status', 'items' => fn ($query) => $query->select([
            'id', 'order_id', 'product_name', 'variant_description', 'sku', 'quantity',
            'final_unit_price', 'line_total',
        ])]);
        $money = app(FinalizeOrderPayment::class);
        $payments = Payment::withoutGlobalScope('tenant')->where('order_id', $order->id)
            ->where('store_id', $order->store_id)->where('status', 'CONFIRMED')->get(['amount']);
        $paymentTotals = null;
        try {
            $paid = $payments->sum(fn ($payment) => $money->amountToCents((string) $payment->amount));
            $total = $money->amountToCents((string) $order->total);
            $consistent = match ($order->payment_status) {
                'UNPAID' => $paid === 0,
                'PARTIALLY_PAID' => $paid > 0 && $paid < $total,
                'PAID' => $paid === $total,
                default => false,
            };
            if ($consistent && $paid >= 0 && $paid <= $total) {
                $format = fn (int $cents) => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
                $paymentTotals = ['paid' => $format($paid), 'balance' => $format($total - $paid)];
            }
        } catch (ValidationException) {
            // Inconsistent historical amounts must not become invented balances.
        }

        return $this->page('buyer.order', compact('order', 'paymentTotals'));
    }

    public function profile(): Response
    {
        return $this->page('buyer.profile');
    }
}
