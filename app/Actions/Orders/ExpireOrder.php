<?php

namespace App\Actions\Orders;

use App\Actions\Stock\ExpireStockReservation;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpireOrder
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
                    'status' =>
                    'Seule une commande en attente peut expirer.',
                ]);
            }

            if ($order->payment_status !== 'UNPAID') {
                throw ValidationException::withMessages([
                    'payment_status' =>
                    'Seule une commande impayée peut expirer.',
                ]);
            }


            /*
|--------------------------------------------------------------------------
| Protection des livraisons en cours
|--------------------------------------------------------------------------
| Une commande dont le colis a été récupéré ne doit
| pas expirer automatiquement, même si son délai
| de réservation est dépassé.
*/

            if (
                $order->delivery()
                ->whereNotNull('picked_up_at')
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'delivery' =>
                    'Expiration impossible : le colis a déjà été récupéré par le livreur.',
                ]);
            }


            if (
                $order->reservation_expires_at === null ||
                $order->reservation_expires_at->isFuture()
            ) {
                throw ValidationException::withMessages([
                    'reservation_expires_at' =>
                    'Cette commande n’a pas encore atteint sa date d’expiration.',
                ]);
            }

            $reservations = StockReservation::query()
                ->where('reference_type', 'ORDER')
                ->where('reference_id', $order->id)
                ->where('status', 'ACTIVE')
                ->get();

            foreach ($reservations as $reservation) {
                app(ExpireStockReservation::class)
                    ->execute($reservation->id);
            }

            $order->status = 'CANCELLED';
            $order->save();

            return $order->refresh();
        });
    }
}
