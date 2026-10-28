<?php

namespace App\Actions\Pos;

use App\Actions\Orders\CreateOrder;
use App\Models\Order;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePosOrder
{
    public function __construct(
        private readonly CreateOrder $createOrder
    ) {}

    public function execute(
        int $sessionId,
        string $customerName,
        string $customerPhone,
        ?string $customerEmail,
        array $items,
        User $actor
    ): Order {
        $storeId = app(TenantContext::class)->storeId();

        $supervisor = $actor->roles()
            ->whereIn('code', [
                'STORE_OWNER',
                'STORE_MANAGER',
            ])
            ->exists();

        $cashier = $actor->hasRole('CASHIER');

        $forbiddenRole = $actor->roles()
            ->whereIn('code', [
                'DELIVERY_AGENT',
                'SUPER_ADMIN',
            ])
            ->exists();

        if (
            auth()->id() !== $actor->id ||
            $actor->status !== 'ACTIVE' ||
            (int) $actor->store_id !== (int) $storeId ||
            (! $supervisor && ! $cashier) ||
            $forbiddenRole ||
            ! $actor->hasPermission('pos.sales.create')
        ) {
            throw new AuthorizationException(
                'Création de vente POS non autorisée.'
            );
        }

        return DB::transaction(function () use (
            $sessionId,
            $customerName,
            $customerPhone,
            $customerEmail,
            $items,
            $actor,
            $storeId,
            $supervisor
        ) {
            // La boutique doit être active.
            if (
                $actor->store()->value('status') !== 'ACTIVE'
            ) {
                throw new AuthorizationException(
                    'Cette boutique est suspendue.'
                );
            }

            // Identifier la session dans la boutique.
            $snapshot = PosSession::query()
                ->where('store_id', $storeId)
                ->whereKey($sessionId)
                ->firstOrFail();

            // Verrouiller la caisse en premier :
            // même ordre que l'ouverture et la clôture.
            $register = PosRegister::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->pos_register_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Relire la session sous verrou.
            $session = PosSession::query()
                ->where('store_id', $storeId)
                ->where('pos_register_id', $register->id)
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $register->status !== 'ACTIVE' ||
                $session->status !== 'OPEN' ||
                (int) $session->open_slot !== 1
            ) {
                throw ValidationException::withMessages([
                    'session' => 'La session POS doit être ouverte sur une caisse active.',
                ]);
            }

            // Une caissière utilise sa propre session.
            // Propriétaire et gestionnaire peuvent superviser.
            if (
                ! $supervisor &&
                (int) $session->opened_by_user_id
                    !== (int) $actor->id
            ) {
                throw new AuthorizationException(
                    'Cette session appartient à un autre caissier.'
                );
            }

            // Réutiliser le module de commandes existant.
            // PICKUP : vente avec retrait en boutique.
            $order = $this->createOrder->execute(
                customerName: trim($customerName),
                customerPhone: trim($customerPhone),
                customerEmail: $customerEmail,
                fulfillmentType: 'PICKUP',
                items: $items,
                allowAnonymousPos: true
            );

            // Affectation côté serveur uniquement.
            // Jamais depuis un champ libre du client.
            $order->pos_session_id = $session->id;
            $order->save();

            return $order->load([
                'items',
                'posSession',
            ]);
        }, 3);
    }
}
