<?php

namespace App\Actions\Deliveries;


use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignDeliveryAgent
{
    public function execute(
        int $orderId,
        int $deliveryAgentId
    ): Delivery {
        $storeId = app(TenantContext::class)->storeId();
        $currentUser = auth()->user();

        // Seul le propriétaire autorisé peut affecter un livreur.
        if (
            ! $currentUser instanceof User ||
            (int) $currentUser->store_id !== $storeId ||

            !(
                $currentUser->hasRole('STORE_OWNER') ||
                $currentUser->hasRole('STORE_MANAGER')
            ) ||

            ! $currentUser->hasPermission('deliveries.update')
        ) {
            throw new AuthorizationException(
                'Vous ne pouvez pas affecter un livreur.'
            );
        }

        return DB::transaction(function () use (
            $orderId,
            $deliveryAgentId,
            $storeId
        ) {
            // Verrouiller la commande de la boutique courante.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->fulfillment_type !== 'DELIVERY') {
                throw ValidationException::withMessages([
                    'order' =>
                    'Cette commande est prévue pour un retrait en boutique.',
                ]);
            }

            if ($order->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'order' =>
                    'Cette commande ne peut plus être affectée.',
                ]);
            }

            // Ne pas expédier une commande impayée
            // dont le délai de réservation est expiré.
            if (
                $order->payment_status === 'UNPAID' &&
                (
                    $order->reservation_expires_at === null ||
                    $order->reservation_expires_at->isPast()
                )
            ) {
                throw ValidationException::withMessages([
                    'order' =>
                    'La réservation de cette commande a expiré.',
                ]);
            }

            // Le livreur doit appartenir à la même boutique.
            $agent = User::query()
                ->where('store_id', $storeId)
                ->whereKey($deliveryAgentId)
                ->first();


            if (
                $agent === null ||
                $agent->status !== 'ACTIVE' ||
                ! $agent->hasRole('DELIVERY_AGENT') ||
                ! $agent->hasPermission('deliveries.update')
            ) {
                throw ValidationException::withMessages([
                    'assigned_to' =>
                    'Le livreur sélectionné est invalide ou non autorisé.',
                ]);
            }

            // Une commande ne doit pas avoir deux livraisons.
            if (
                Delivery::query()
                ->where('order_id', $order->id)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'delivery' =>
                    'Cette commande possède déjà une livraison.',
                ]);
            }

            return Delivery::create([
                'order_id' => $order->id,
                'assigned_to' => $agent->id,
                'status' => 'ASSIGNED',
                'assigned_at' => now(),
            ]);
        }, 3);
    }
}
