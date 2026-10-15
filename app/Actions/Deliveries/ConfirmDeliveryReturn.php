<?php
namespace App\Actions\Deliveries;


use App\Models\Delivery;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmDeliveryReturn
{
    public function execute(
        int $deliveryId,
        ?string $notes = null
    ): Delivery {
        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('deliveries.update')
        ) {
            throw new AuthorizationException(
                'Confirmation du retour non autorisée.'
            );
        }

        $notes = trim($notes ?? '');

        if (mb_strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'return_notes' =>
                    'Les observations ne doivent pas dépasser 1000 caractères.',
            ]);
        }

        return DB::transaction(function () use (
            $deliveryId,
            $storeId,
            $user,
            $notes
        ) {
            // Identifier la commande avant les verrouillages.
            $snapshot = Delivery::query()
                ->where('store_id', $storeId)
                ->whereKey($deliveryId)
                ->firstOrFail();

            // Ordre de verrouillage : commande puis livraison.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $delivery = Delivery::query()
                ->where('store_id', $storeId)
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $delivery->order_id !== (int) $order->id ||
                (int) $delivery->store_id !== (int) $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'delivery' => 'Livraison et commande incompatibles.',
                ]);
            }

            if (
                $order->status !== 'PENDING' ||
                $order->fulfillment_type !== 'DELIVERY'
            ) {
                throw ValidationException::withMessages([
                    'order' => 'Cette commande ne peut pas être retournée.',
                ]);
            }

            if (
                $delivery->status !== 'FAILED' ||
                $delivery->picked_up_at === null ||
                $delivery->delivered_at !== null
            ) {
                throw ValidationException::withMessages([
                    'delivery' =>
                        'Le colis doit avoir été récupéré puis déclaré en échec.',
                ]);
            }

            if ($delivery->returned_at !== null) {
                throw ValidationException::withMessages([
                    'delivery' => 'Ce retour a déjà été confirmé.',
                ]);
            }

            if (! in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
                'PAID',
            ], true)) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Statut de paiement non pris en charge.',
                ]);
            }

            // Aucun mouvement automatique sur une commande
            // ayant reçu un paiement.
            if ($order->payment_status === 'UNPAID') {

                // Protection supplémentaire contre un état
                // de paiement financièrement incohérent.
                if (
                    $order->payments()
                        ->where('status', 'CONFIRMED')
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'payment_status' =>
                            'Un paiement confirmé nécessite un traitement financier.',
                    ]);
                }

                $reservations = StockReservation::query()
                    ->where('reference_type', 'ORDER')
                    ->where('reference_id', $order->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (
                    $reservations->isEmpty() ||
                    $reservations->contains(
                        fn ($reservation) =>
                            $reservation->status !== 'ACTIVE' ||
                            (int) $reservation->store_id !== (int) $storeId
                    )
                ) {
                    throw ValidationException::withMessages([
                        'stock' =>
                            'Réservations de stock absentes ou incohérentes.',
                    ]);
                }

                // Vérifier que les réservations correspondent
                // réellement aux quantités commandées.
                $orderedQuantities = $order->items()
                    ->get()
                    ->groupBy('product_variant_id')
                    ->map(fn ($items) => $items->sum('quantity'))
                    ->sortKeys()
                    ->all();

                $reservedQuantities = $reservations
                    ->groupBy('product_variant_id')
                    ->map(fn ($items) => $items->sum('quantity'))
                    ->sortKeys()
                    ->all();

                if (
                    $orderedQuantities === [] ||
                    $orderedQuantities !== $reservedQuantities
                ) {
                    throw ValidationException::withMessages([
                        'stock' =>
                            'Les réservations ne correspondent pas à la commande.',
                    ]);
                }

                foreach ($reservations as $reservation) {
                    $stock = StockLevel::query()
                        ->where(
                            'product_variant_id',
                            $reservation->product_variant_id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        (int) $stock->store_id !== (int) $storeId ||
                        $stock->reserved_quantity < $reservation->quantity
                    ) {
                        throw ValidationException::withMessages([
                            'stock' => 'Quantité réservée incohérente.',
                        ]);
                    }

                    // Le stock physique ne change pas :
                    // les articles étaient seulement réservés.
                    $stock->reserved_quantity -= $reservation->quantity;
                    $stock->save();

                    $reservation->status = 'RELEASED';
                    $reservation->released_at = now();
                    $reservation->save();
                }

                $order->status = 'CANCELLED';
                $order->save();
            }

            // Enregistrer la réception physique du colis.
            $delivery->returned_at = now();
            $delivery->return_confirmed_by = $user->id;
            $delivery->return_notes =
                $notes !== '' ? $notes : null;

            $delivery->save();

            return $delivery->refresh();
        }, 3);
    }
}
