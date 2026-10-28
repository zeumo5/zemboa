<?php

namespace App\Actions\Pos;

use App\Actions\Payments\FinalizeOrderPayment;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Support\PosAccess;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordPosCashMovement
{
    public function __construct(
        private readonly FinalizeOrderPayment $finalizer,
        private readonly CalculatePosCashBalance $cashBalance
    ) {}

    public function execute(
        int $sessionId,
        string $type,
        string $amount,
        string $reason,
        string $reference,
        User $actor
    ): PosCashMovement {
        $storeId = app(TenantContext::class)->storeId();

        PosAccess::authorizeCashMovements($actor);

        if (! in_array($type, ['CASH_IN', 'CASH_OUT'], true)) {
            throw ValidationException::withMessages([
                'type' => 'Type de mouvement invalide.',
            ]);
        }

        // Utiliser la validation monétaire existante.
        // Aucun calcul en float.
        if (
            $this->finalizer->amountToCents($amount) <= 0
        ) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant doit être strictement positif.',
            ]);
        }

        $reason = trim($reason);
        $reference = trim($reference);

        if (
            $reason === '' ||
            mb_strlen($reason) > 255
        ) {
            throw ValidationException::withMessages([
                'reason' => 'Une justification valide est obligatoire.',
            ]);
        }

        // Référence stable à réutiliser en cas de
        // nouvelle tentative du même enregistrement.
        if (! preg_match(
            '/^[A-Za-z0-9._:-]{8,64}$/D',
            $reference
        )) {
            throw ValidationException::withMessages([
                'reference' => 'Référence invalide.',
            ]);
        }

        return DB::transaction(function () use (
            $sessionId,
            $type,
            $amount,
            $reason,
            $reference,
            $actor,
            $storeId
        ) {
            if (
                $actor->store()->value('status') !== 'ACTIVE'
            ) {
                throw new AuthorizationException(
                    'Cette boutique est suspendue.'
                );
            }

            // Identifier la session appartenant
            // à la boutique courante.
            $snapshot = PosSession::query()
                ->where('store_id', $storeId)
                ->whereKey($sessionId)
                ->firstOrFail();

            // Verrouiller la caisse en premier.
            $register = PosRegister::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->pos_register_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Puis verrouiller la session.
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
                    'session' => 'Une session ouverte sur une caisse active est obligatoire.',
                ]);
            }

            // Empêcher l'utilisation répétée
            // d'une même référence dans la boutique.
            $duplicate = PosCashMovement::query()
                ->where('store_id', $storeId)
                ->where('reference', $reference)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'reference' => 'Ce mouvement a déjà été enregistré.',
                ]);
            }

            // Calculer le solde sous les verrous
            // de la caisse et de la session.
            $balance = $this->cashBalance->execute($session);

            $availableCents = $balance['expected_cash_cents'];

            $movementCents = $this->finalizer
                ->amountToCents($amount);

            // Limite de DECIMAL(12,2).
            $maxCents = 999_999_999_999;

            if (
                $availableCents < 0 ||
                $availableCents > $maxCents
            ) {
                throw ValidationException::withMessages([
                    'session' => 'Le solde actuel de la caisse est invalide.',
                ]);
            }

            // Interdire les retraits excessifs.
            if (
                $type === 'CASH_OUT' &&
                $movementCents > $availableCents
            ) {
                throw ValidationException::withMessages([
                    'amount' => __('pos.cash_out_insufficient'),
                ]);
            }

            // Interdire les entrées qui dépasseraient
            // la capacité financière de la caisse.
            // Soustraction préalable pour éviter
            // un dépassement dans le calcul.
            if (
                $type === 'CASH_IN' &&
                $movementCents > $maxCents - $availableCents
            ) {
                throw ValidationException::withMessages([
                    'amount' => __('pos.cash_in_limit_exceeded'),
                ]);
            }

            // L'utilisateur ne choisit ni la boutique,
            // ni la session, ni l'auteur du mouvement.
            $movement = new PosCashMovement([
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            $movement->pos_session_id = $session->id;
            $movement->recorded_by_user_id = $actor->id;

            $movement->save();

            return $movement->refresh();
        }, 3);
    }
}
