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

final class SimulateRefund
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
                'Simulation du remboursement non autorisée.'
            );
        }

        return DB::transaction(function () use (
            $refundId,
            $storeId
        ) {
            $snapshot = Refund::query()
                ->where('store_id', $storeId)
                ->whereKey($refundId)
                ->firstOrFail();

            // Ordre de verrouillage cohérent
            // avec PrepareRefund et ApproveRefund.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $return = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->return_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $refund = Refund::query()
                ->where('store_id', $storeId)
                ->whereKey($refundId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $refund->order_id !== (int) $order->id ||
                (int) $refund->return_request_id !== (int) $return->id ||
                (int) $return->order_id !== (int) $order->id
            ) {
                throw ValidationException::withMessages([
                    'refund' => 'Données du remboursement incompatibles.',
                ]);
            }

            // Seul un remboursement approuvé
            // peut passer au statut SIMULATED.
            if (
                $refund->status !== 'APPROVED' ||
                $refund->approved_by === null ||
                $refund->approved_at === null ||
                $refund->simulated_at !== null
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Ce remboursement ne peut pas être simulé.',
                ]);
            }

            if (
                $return->status !== 'INSPECTED' ||
                $return->received_at === null ||
                $return->inspected_at === null
            ) {
                throw ValidationException::withMessages([
                    'return' => 'Le retour doit être inspecté.',
                ]);
            }

            if ($order->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'payment_status' => 'Commande non intégralement payée.',
                ]);
            }

            // Recalculer le montant à partir
            // des articles effectivement retournés.
            $items = $return->items()
                ->with('orderItem')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Aucun article retourné.',
                ]);
            }

            $expectedAmount = 0;

            foreach ($items as $item) {
                $orderItem = $item->orderItem;

                if (
                    $orderItem === null ||
                    (int) $orderItem->order_id !== (int) $order->id ||
                    (int) $item->quantity <= 0 ||
                    (int) $item->quantity > (int) $orderItem->quantity
                ) {
                    throw ValidationException::withMessages([
                        'items' => 'Article ou quantité invalide.',
                    ]);
                }

                $unitPrice = $this->calculator->toFcfa(
                    (string) $orderItem->final_unit_price
                );

                $expectedAmount +=
                    $unitPrice * (int) $item->quantity;
            }

            $amount = $this->calculator->toFcfa(
                (string) $refund->amount
            );

            if (
                $amount <= 0 ||
                $amount !== $expectedAmount
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'Le montant du remboursement est incohérent.',
                ]);
            }

            // Vérifier les paiements confirmés.
            $confirmedTotal = 0;

            $payments = $order->payments()
                ->where('status', 'CONFIRMED')
                ->get();

            foreach ($payments as $payment) {
                if ((int) $payment->store_id !== (int) $storeId) {
                    throw ValidationException::withMessages([
                        'payment' => 'Paiement d’une autre boutique.',
                    ]);
                }

                $confirmedTotal += $this->calculator->toFcfa(
                    (string) $payment->amount
                );
            }

            // Tous les remboursements déjà engagés
            // comptent dans le plafond financier.
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
                        'Le plafond des paiements confirmés est dépassé.',
                ]);
            }

            // SIMULATION UNIQUEMENT.
            // Aucun appel MTN MoMo, Orange Money
            // ou autre prestataire financier.
            $refund->status = 'SIMULATED';
            $refund->simulated_at = now();

            $refund->save();

            return $refund->refresh();
        }, 3);
    }
}
