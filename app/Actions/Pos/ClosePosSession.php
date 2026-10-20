<?php

namespace App\Actions\Pos;

use App\Actions\Payments\FinalizeOrderPayment;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClosePosSession
{
    public function __construct(
        private readonly CalculatePosCashBalance $cashBalance,
        private readonly FinalizeOrderPayment $finalizer
    ) {}

    public function execute(
        int $sessionId,
        string $cashCounted,
        User $actor
    ): PosSession {
        $storeId = app(TenantContext::class)->storeId();

        $supervisor = $actor->hasRole('STORE_OWNER')
            || $actor->hasRole('STORE_MANAGER');

        $cashier = $actor->hasRole('CASHIER');

        if (
            auth()->id() !== $actor->id ||
            $actor->status !== 'ACTIVE' ||
            (int) $actor->store_id !== $storeId ||
            (! $supervisor && ! $cashier) ||
            $actor->hasRole('DELIVERY_AGENT') ||
            $actor->hasRole('SUPER_ADMIN') ||
            ! $actor->hasPermission('pos.sessions.close')
        ) {
            throw new AuthorizationException(
                'Clôture de caisse non autorisée.'
            );
        }

        // Vérifier le montant compté sans utiliser de float.
        if (! preg_match(
            '/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',
            $cashCounted
        )) {
            throw ValidationException::withMessages([
                'closing_cash_counted' =>
                'Montant de clôture invalide.',
            ]);
        }

        return DB::transaction(function () use (
            $sessionId,
            $cashCounted,
            $actor,
            $storeId,
            $supervisor
        ) {
            // Vérifier que la boutique est active.
            if (
                $actor->store()->value('status') !== 'ACTIVE'
            ) {
                throw new AuthorizationException(
                    'Cette boutique est suspendue.'
                );
            }

            // Identifier la session dans la boutique.
            $snapshot = PosSession::query()
                ->whereKey($sessionId)
                ->firstOrFail();

            // Verrouiller d'abord la caisse,
            // comme dans OpenPosSession.
            $register = PosRegister::query()
                ->whereKey($snapshot->pos_register_id)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->firstOrFail();

            // Relire et verrouiller la session.
            $session = PosSession::query()
                ->whereKey($sessionId)
                ->where('pos_register_id', $register->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $session->status !== 'OPEN' ||
                (int) $session->open_slot !== 1
            ) {
                throw ValidationException::withMessages([
                    'session' =>
                    'Cette session est déjà clôturée.',
                ]);
            }

            // Une caissière ne peut clôturer
            // que sa propre session.
            // Le propriétaire ou le gestionnaire
            // peut intervenir en supervision.
            if (
                ! $supervisor &&
                (int) $session->opened_by_user_id
                !== (int) $actor->id
            ) {
                throw new AuthorizationException(
                    'Vous ne pouvez pas clôturer cette session.'
                );
            }

            // Empêcher la fermeture tant qu'un paiement
            // rattaché à la session attend une décision.
            $hasPendingPayment = Payment::query()
                ->where('store_id', $storeId)
                ->where('pos_session_id', $session->id)
                ->where('status', 'PENDING')
                ->exists();

            if ($hasPendingPayment) {
                throw ValidationException::withMessages([
                    'session' =>
                    'Impossible de clôturer cette session : un paiement est encore en attente.',
                ]);
            }

            // Calculer le solde pendant que la caisse
            // et la session sont verrouillées.
            $balance = $this->cashBalance->execute($session);

            $expectedCents = $balance['expected_cash_cents'];

            // Éviter un bilan négatif ou un dépassement
            // de la capacité DECIMAL(12,2).
            if (
                $expectedCents < 0 ||
                $expectedCents > 999999999999
            ) {
                throw ValidationException::withMessages([
                    'session' =>
                    'Le solde théorique de la caisse est invalide.',
                ]);
            }

            // Compter les espèces sans utiliser de float.
            $countedCents = $this->finalizer->amountToCents(
                $cashCounted
            );

            // Positif = excédent.
            // Négatif = manque d'espèces.
            $differenceCents = $countedCents - $expectedCents;

            // Formater aussi les écarts négatifs.
            $absoluteDifference = abs($differenceCents);

            $difference = ($differenceCents < 0 ? '-' : '')
                . intdiv($absoluteDifference, 100)
                . '.'
                . str_pad(
                    (string) ($absoluteDifference % 100),
                    2,
                    '0',
                    STR_PAD_LEFT
                );

            // Clôture opérationnelle de la session.
            $session->status = 'CLOSED';
            $session->open_slot = null;
            $session->closed_by_user_id = $actor->id;
            $session->closed_at = now();

            // Somme réellement comptée.
            $session->closing_cash_counted = $cashCounted;

            // Les recettes attendues et les écarts
            // seront calculés après l'intégration
            // des ventes et paiements POS.
            $session->closing_cash_expected = $balance['expected_cash'];
            $session->cash_difference = $difference;

            $session->save();

            return $session->refresh();
        }, 3);
    }
}
