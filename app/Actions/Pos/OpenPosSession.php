<?php

namespace App\Actions\Pos;


use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenPosSession
{
    public function execute(
        int $registerId,
        string $openingCash,
        User $actor
    ): PosSession {
        $storeId = app(TenantContext::class)->storeId();

        // Vérifier le responsable de la session.
        $allowedRole = $actor->roles()
            ->whereIn('code', [
                'STORE_OWNER',
                'STORE_MANAGER',
                'CASHIER',
            ])
            ->exists();

        $forbiddenRole = $actor->roles()
            ->whereIn('code', [
                'DELIVERY_AGENT',
                'SUPER_ADMIN',
            ])
            ->exists();

        if (
            auth()->id() !== $actor->id ||
            $actor->status !== 'ACTIVE' ||
            (int) $actor->store_id !== $storeId ||
            ! $allowedRole ||
            $forbiddenRole ||
            ! $actor->hasPermission('pos.sessions.open')
        ) {
            throw new AuthorizationException(
                'Ouverture de caisse non autorisée.'
            );
        }

        // Montant positif ou nul, maximum deux décimales.
        // Respecter DECIMAL(12,2), sans utiliser de float.
        if (! preg_match(
            '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',
            $openingCash
        )) {
            throw ValidationException::withMessages([
                'opening_cash' =>
                'Montant initial invalide.',
            ]);
        }

        return DB::transaction(function () use (
            $registerId,
            $openingCash,
            $actor,
            $storeId
        ) {
            // Verrouiller la caisse pour éviter
            // deux ouvertures simultanées.
            $register = PosRegister::query()
                ->where('store_id', $storeId)
                ->whereKey($registerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Vérifier l'état actuel de la boutique en base.
            if ($register->store()->value('status') !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'store' => 'Cette boutique est suspendue.',
                ]);
            }

            if ($register->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'register' =>
                    'Cette caisse est désactivée.',
                ]);
            }

            // La contrainte SQL unique sur
            // (pos_register_id, open_slot) constitue
            // une deuxième protection.
            $alreadyOpen = PosSession::query()
                ->where('pos_register_id', $register->id)
                ->where('open_slot', 1)
                ->exists();

            if ($alreadyOpen) {
                throw ValidationException::withMessages([
                    'session' =>
                    'Une session est déjà ouverte sur cette caisse.',
                ]);
            }

            $session = new PosSession([
                'pos_register_id' => $register->id,
                'opening_cash' => $openingCash,
            ]);

            // Ces informations sont définies
            // exclusivement par le serveur.
            $session->opened_by_user_id = $actor->id;

            $session->save();

            return $session->refresh();
        }, 3);
    }
}
