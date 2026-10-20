<?php

namespace App\Actions\Pos;


use App\Actions\Payments\CreateCashPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePosCashPayment
{
    public function __construct(
        private readonly CreateCashPayment $createCashPayment
    ) {}

    public function execute(
        int $sessionId,
        int $orderId,
        string $amount,
        string $cashReceived,
        User $actor
    ): Payment {
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
            $storeId === null ||
            auth()->id() !== $actor->id ||
            $actor->status !== 'ACTIVE' ||
            (int) $actor->store_id !== (int) $storeId ||
            (! $supervisor && ! $cashier) ||
            $forbiddenRole ||
            ! $actor->hasPermission('pos.sales.create') ||
            ! $actor->hasPermission('payments.confirm')
        ) {
            throw new AuthorizationException(
                'Encaissement POS non autorisé.'
            );
        }

        return DB::transaction(function () use (
            $sessionId,
            $orderId,
            $amount,
            $cashReceived,
            $actor,
            $storeId,
            $supervisor
        ) {
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

            // Verrouiller la caisse en premier,
            // comme dans les autres actions POS.
            $register = PosRegister::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->pos_register_id)
                ->lockForUpdate()
                ->firstOrFail();

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
                    'session' =>
                    'Une session ouverte sur une caisse active est obligatoire.',
                ]);
            }

            // La caissière utilise sa propre session.
            // Le propriétaire et le gestionnaire
            // peuvent intervenir en supervision.
            if (
                ! $supervisor &&
                (int) $session->opened_by_user_id
                !== (int) $actor->id
            ) {
                throw new AuthorizationException(
                    'Cette session appartient à une autre caissière.'
                );
            }

            // Vérifier que la commande appartient
            // à la même boutique.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            // Réutiliser le moteur de paiement existant.
            // Il vérifie le solde, les paiements en attente
            // et la monnaie à rendre.
            $payment = $this->createCashPayment->execute(
                orderId: $order->id,
                amount: $amount,
                cashReceived: $cashReceived,
                posSessionId: $session->id
            );

            return $payment->refresh();
        }, 3);
    }
}
