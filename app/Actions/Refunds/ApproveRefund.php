<?php
namespace App\Actions\Refunds;


use App\Actions\Orders\OrderPriceCalculator;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveRefund
{
    public function __construct(
        private readonly OrderPriceCalculator $calculator
    ) {
    }

    public function execute(int $refundId): Refund
    {
        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('refunds.manage')
        ) {
            throw new AuthorizationException(
                'Approbation du remboursement non autorisée.'
            );
        }

        return DB::transaction(function () use (
            $refundId,
            $storeId,
            $user
        ) {
            $snapshot = Refund::query()
                ->where('store_id', $storeId)
                ->whereKey($refundId)
                ->firstOrFail();

            // Ordre de verrouillage :
            // commande, demande de retour, remboursement.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $returnRequest = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->return_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $refund = Refund::query()
                ->where('store_id', $storeId)
                ->whereKey($refundId)
                ->lockForUpdate()
                ->firstOrFail();

            // Cohérence multiboutiques.
            if (
                (int) $refund->order_id !== (int) $order->id ||
                (int) $refund->return_request_id !==
                    (int) $returnRequest->id ||
                (int) $returnRequest->order_id !== (int) $order->id
            ) {
                throw ValidationException::withMessages([
                    'refund' =>
                        'Remboursement, retour et commande incompatibles.',
                ]);
            }

            if (
                $refund->status !== 'PENDING' ||
                $refund->approved_at !== null ||
                $refund->simulated_at !== null
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Ce remboursement ne peut plus être approuvé.',
                ]);
            }

            if (
                $returnRequest->status !== 'INSPECTED' ||
                $returnRequest->received_at === null ||
                $returnRequest->inspected_at === null
            ) {
                throw ValidationException::withMessages([
                    'return' =>
                        'Le retour doit être réceptionné et inspecté.',
                ]);
            }

            if ($order->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'payment_status' =>
                        'La commande doit être intégralement payée.',
                ]);
            }

            $amount = $this->calculator->toFcfa(
                (string) $refund->amount
            );

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Le montant du remboursement est invalide.',
                ]);
            }

            // Recontrôler les sommes réellement confirmées.
            $confirmedTotal = 0;

            $payments = $order->payments()
                ->where('status', 'CONFIRMED')
                ->orderBy('id')
                ->get();

            foreach ($payments as $payment) {
                if ((int) $payment->store_id !== (int) $storeId) {
                    throw ValidationException::withMessages([
                        'payment' =>
                            'Paiement appartenant à une autre boutique.',
                    ]);
                }

                $confirmedTotal += $this->calculator->toFcfa(
                    (string) $payment->amount
                );
            }

            // Inclure tous les remboursements engagés,
            // y compris celui en cours d'approbation.
            $committedTotal = 0;

            $refunds = Refund::query()
                ->where('store_id', $storeId)
                ->where('order_id', $order->id)
                ->get();

            foreach ($refunds as $existingRefund) {
                $committedTotal += $this->calculator->toFcfa(
                    (string) $existingRefund->amount
                );
            }

            if (
                $confirmedTotal <= 0 ||
                $committedTotal > $confirmedTotal
            ) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Les remboursements dépassent les paiements confirmés.',
                ]);
            }

            // Approbation administrative uniquement.
            // Aucun transfert Mobile Money ni CASH.
            $refund->status = 'APPROVED';
            $refund->approved_by = $user->id;
            $refund->approved_at = now();

            $refund->save();

            return $refund->refresh();
        }, 3);
    }
}
