<?php

namespace App\Actions\Orders;

use App\Actions\Payments\FinalizeOrderPayment;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Support\SalesAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AdvanceOrderPreparation
{
    public static function schemaReady(): bool
    {
        try {
            if (! Schema::hasColumns('orders', ['preparation_started_at', 'preparation_started_by', 'ready_at', 'ready_by'])
                || ! Schema::hasIndex('orders', ['store_id', 'preparation_started_at', 'ready_at'])) {
                return false;
            }
            $keys = collect(Schema::getForeignKeys('orders'));

            return collect(['preparation_started_by', 'ready_by'])->every(fn (string $field): bool => $keys->contains(
                fn (array $key): bool => $key['columns'] === [$field] && $key['foreign_table'] === 'users' && $key['foreign_columns'] === ['id'] && $key['on_delete'] === 'restrict'));
        } catch (QueryException) {
            return false;
        }
    }

    public function execute(User $actor, int $orderId, string $step, string $version): Order
    {
        abort_unless(auth('web')->id() === $actor->id && SalesAccess::allowed($actor, 'orders.update'), 403);
        abort_unless(self::schemaReady(), 503, __('sales.schema'));
        abort_unless(in_array($step, ['start', 'ready'], true), 404);

        return DB::transaction(function () use ($actor, $orderId, $step, $version): Order {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('prepare', $order);
            abort_unless(hash_equals(SalesAccess::preparationVersion($order), $version), 409, __('sales.stale'));
            if ($order->status !== 'PENDING' || $order->payment_status !== 'PAID' || $order->pos_session_id !== null
                || ! in_array($order->fulfillment_type, ['PICKUP', 'DELIVERY'], true)
                || $order->delivery()->where(fn ($q) => $q->whereNotNull('picked_up_at')->orWhereIn('status', ['PICKED_UP', 'DELIVERED', 'FAILED', 'RETURNED']))->exists()) {
                throw ValidationException::withMessages(['order' => __('sales.ineligible')]);
            }
            $money = app(FinalizeOrderPayment::class);
            $payments = $order->payments()->where('status', 'CONFIRMED')->lockForUpdate()->get();
            $total = $money->amountToCents((string) $order->total);
            if ($total <= 0 || $payments->isEmpty() || $payments->contains(fn ($p) => (int) $p->store_id !== (int) $actor->store_id || $p->confirmed_at === null)
                || $payments->sum(fn ($p) => $money->amountToCents((string) $p->amount)) !== $total) {
                throw ValidationException::withMessages(['order' => __('sales.payment_inconsistent')]);
            }
            $items = $order->items()->get()->groupBy('product_variant_id')->map(fn ($items) => (int) $items->sum('quantity'));
            $reservations = StockReservation::where('reference_type', 'ORDER')->where('reference_id', $order->id)->get();
            $converted = $reservations->groupBy('product_variant_id')->map(fn ($rows) => (int) $rows->sum('quantity'));
            if ($items->isEmpty() || $reservations->contains(fn ($r) => $r->status !== 'CONVERTED') || $items->sortKeys()->all() !== $converted->sortKeys()->all()) {
                throw ValidationException::withMessages(['order' => __('sales.stock_inconsistent')]);
            }
            if ($step === 'start') {
                abort_if($order->preparation_started_at || $order->ready_at || $order->preparation_started_by || $order->ready_by, 409, __('sales.stale'));
                $order->preparation_started_at = now();
                $order->preparation_started_by = $actor->id;
            } else {
                abort_unless($order->preparation_started_at && $order->preparation_started_by && ! $order->ready_at && ! $order->ready_by, 409, __('sales.stale'));
                $order->ready_at = now();
                $order->ready_by = $actor->id;
            }
            $order->save();

            return $order;
        });
    }
}
