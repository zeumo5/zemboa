<?php

namespace App\Actions\Orders;

use App\Actions\Stock\ReleaseStockReservation;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    public function execute(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande en attente peut être annulée.',
                ]);
            }

            if ($order->payment_status !== 'UNPAID') {
                throw ValidationException::withMessages([
                    'payment_status' =>
                        'Une commande ayant déjà reçu un paiement ne peut pas être annulée directement.',
                ]);
            }

            $reservations = StockReservation::query()
                ->where('reference_type', 'ORDER')
                ->where('reference_id', $order->id)
                ->where('status', 'ACTIVE')
                ->get();

            foreach ($reservations as $reservation) {
                app(ReleaseStockReservation::class)
                    ->execute($reservation->id);
            }

            $order->status = 'CANCELLED';
            $order->save();

            return $order->refresh();
        });
    }
}