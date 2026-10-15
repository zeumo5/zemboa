<?php
namespace App\Actions\Deliveries;


use App\Models\Delivery;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateDeliveryStatus
{
    public function execute(
        int $deliveryId,
        string $newStatus,
        ?string $failureReason = null
    ): Delivery {
        if (!in_array($newStatus, [
            'PICKED_UP',
            'DELIVERED',
            'FAILED',
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Statut de livraison invalide.',
            ]);
        }

        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== $storeId ||
            ! $user->hasRole('DELIVERY_AGENT') ||
            ! $user->hasPermission('deliveries.update')
        ) {
            throw new AuthorizationException(
                'Vous ne pouvez pas modifier cette livraison.'
            );
        }

        return DB::transaction(function () use (
            $deliveryId,
            $newStatus,
            $failureReason,
            $storeId,
            $user
        ) {
            $deliveryLookup = Delivery::query()
                ->whereKey($deliveryId)
                ->firstOrFail();

            // Ordre de verrouillage : commande puis livraison.
            $order = Order::query()
                ->whereKey($deliveryLookup->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $delivery = Delivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $delivery->store_id !== $storeId ||
                (int) $delivery->order_id !== (int) $order->id ||
                (int) $delivery->assigned_to !== (int) $user->id
            ) {
                throw new AuthorizationException(
                    'Cette livraison ne vous est pas attribuée.'
                );
            }

            if (
                $order->status !== 'PENDING' ||
                $order->fulfillment_type !== 'DELIVERY'
            ) {
                throw ValidationException::withMessages([
                    'order' => 'Cette commande ne peut plus être livrée.',
                ]);
            }

            $allowedTransitions = [
                'ASSIGNED' => ['PICKED_UP', 'FAILED'],
                'PICKED_UP' => ['DELIVERED', 'FAILED'],
            ];

            if (!in_array(
                $newStatus,
                $allowedTransitions[$delivery->status] ?? [],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' => 'Transition de livraison non autorisée.',
                ]);
            }

            if ($newStatus === 'PICKED_UP') {
                // Une commande impayée doit encore posséder
                // une réservation valide.
                if (
                    $order->payment_status === 'UNPAID' &&
                    (
                        $order->reservation_expires_at === null ||
                        $order->reservation_expires_at->isPast()
                    )
                ) {
                    throw ValidationException::withMessages([
                        'order' => 'La réservation de stock a expiré.',
                    ]);
                }

                if (
                    $order->payment_status !== 'PAID' &&
                    ! StockReservation::query()
                        ->where('reference_type', 'ORDER')
                        ->where('reference_id', $order->id)
                        ->where('status', 'ACTIVE')
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'order' => 'Aucune réservation de stock active.',
                    ]);
                }

                $delivery->picked_up_at = now();
            }

            if ($newStatus === 'DELIVERED') {
                if ($order->payment_status !== 'PAID') {
                    throw ValidationException::withMessages([
                        'payment_status' =>
                            'Le paiement doit être confirmé avant de terminer la livraison.',
                    ]);
                }

                $delivery->delivered_at = now();
            }

            if ($newStatus === 'FAILED') {
                $reason = trim($failureReason ?? '');

                if ($reason === '' || mb_strlen($reason) > 1000) {
                    throw ValidationException::withMessages([
                        'failure_reason' =>
                            'Un motif de 1 à 1000 caractères est obligatoire.',
                    ]);
                }

                $delivery->failed_at = now();
                $delivery->failure_reason = $reason;
            }

            $delivery->status = $newStatus;
            $delivery->save();

            return $delivery->refresh();
        }, 3);
    }
}
