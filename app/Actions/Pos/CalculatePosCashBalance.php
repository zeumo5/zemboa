<?php
namespace App\Actions\Pos;


use App\Actions\Payments\FinalizeOrderPayment;
use App\Models\Payment;
use App\Models\PosCashMovement;
use App\Models\PosSession;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class CalculatePosCashBalance
{
    public function __construct(
        private readonly FinalizeOrderPayment $finalizer
    ) {}

    public function execute(PosSession $session): array
    {
        $storeId = app(TenantContext::class)->storeId();

        if (
            $storeId === null ||
            ! $session->exists ||
            (int) $session->store_id !== (int) $storeId
        ) {
            throw new AuthorizationException(
                'Session de caisse inaccessible.'
            );
        }

        $toCents = fn (string $amount): int =>
            $this->finalizer->amountToCents($amount);

        $openingCents = $toCents(
            (string) $session->opening_cash
        );

        // Seuls les paiements CASH confirmés
        // de cette session augmentent les recettes.
        $confirmedCashCents = 0;

        $payments = Payment::query()
            ->where('store_id', $storeId)
            ->where('pos_session_id', $session->id)
            ->where('method', 'CASH')
            ->where('status', 'CONFIRMED')
            ->get(['amount']);

        foreach ($payments as $payment) {
            $confirmedCashCents += $toCents(
                (string) $payment->amount
            );
        }

        // Mouvements manuels de la session.
        $cashInCents = 0;
        $cashOutCents = 0;

        $movements = PosCashMovement::query()
            ->where('store_id', $storeId)
            ->where('pos_session_id', $session->id)
            ->get(['type', 'amount']);

        foreach ($movements as $movement) {
            $amountCents = $toCents(
                (string) $movement->amount
            );

            if ($movement->type === 'CASH_IN') {
                $cashInCents += $amountCents;
            } elseif ($movement->type === 'CASH_OUT') {
                $cashOutCents += $amountCents;
            } else {
                throw new LogicException(
                    'Type de mouvement de caisse inconnu.'
                );
            }
        }

        $expectedCents = $openingCents
            + $confirmedCashCents
            + $cashInCents
            - $cashOutCents;

        return [
            'opening_cash' => $this->formatCents($openingCents),
            'confirmed_cash_payments' =>
                $this->formatCents($confirmedCashCents),
            'cash_in' => $this->formatCents($cashInCents),
            'cash_out' => $this->formatCents($cashOutCents),
            'expected_cash' => $this->formatCents($expectedCents),

            // Utilisé ensuite pour empêcher les
            // retraits supérieurs aux fonds disponibles.
            'expected_cash_cents' => $expectedCents,
        ];
    }

    private function formatCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '')
            . intdiv($absolute, 100)
            . '.'
            . str_pad(
                (string) ($absolute % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }
}
