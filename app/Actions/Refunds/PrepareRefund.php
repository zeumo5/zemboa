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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PrepareRefund
{
    public function __construct(
        private readonly OrderPriceCalculator $calculator
    ) {
    }

    public function execute(int $returnRequestId): Refund
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
                'Préparation du remboursement non autorisée.'
            );
        }

        return DB::transaction(function () use (
            $returnRequestId,
            $storeId
        ) {
            $snapshot = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->firstOrFail();

            // Verrouiller la commande avant le retour.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $returnRequest = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $returnRequest->order_id !== (int) $order->id ||
                (int) $returnRequest->store_id !== (int) $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'return' => 'Retour et commande incompatibles.',
                ]);
            }

            // La réception et l'inspection sont obligatoires.
            if (
                $returnRequest->status !== 'INSPECTED' ||
                $returnRequest->received_at === null ||
                $returnRequest->inspected_at === null
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Le retour doit être réceptionné et inspecté.',
                ]);
            }

            if ($order->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'payment_status' =>
                        'La commande doit être intégralement payée.',
                ]);
            }

            // Une seule préparation par demande.
            if (
                Refund::query()
                    ->where('return_request_id', $returnRequest->id)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'refund' =>
                        'Un remboursement existe déjà pour ce retour.',
                ]);
            }

            $items = $returnRequest->items()
                ->with('orderItem')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Aucun article retourné.',
                ]);
            }

            $refundAmount = 0;

            foreach ($items as $item) {
                $orderItem = $item->orderItem;

                if (
                    $orderItem === null ||
                    (int) $orderItem->order_id !== (int) $order->id ||
                    (int) $item->quantity <= 0 ||
                    (int) $item->quantity > (int) $orderItem->quantity
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Article retourné ou quantité invalide.',
                    ]);
                }

                // Prix après réduction, conservé
                // lors de la création de la commande.
                $unitPrice = $this->calculator->toFcfa(
                    (string) $orderItem->final_unit_price
                );

                $refundAmount +=
                    $unitPrice * (int) $item->quantity;
            }

            if ($refundAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Le montant remboursable doit être positif.',
                ]);
            }

            // Calculer uniquement les paiements confirmés.
            $payments = $order->payments()
                ->where('status', 'CONFIRMED')
                ->orderBy('id')
                ->get();

            $confirmedTotal = 0;
            $methods = [];

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

                $methods[] = $payment->method;
            }

            if ($confirmedTotal <= 0) {
                throw ValidationException::withMessages([
                    'payment' =>
                        'Aucun paiement confirmé pour cette commande.',
                ]);
            }

            // Inclure les remboursements déjà préparés,
            // approuvés ou simulés pour la même commande.
            // Aucun remboursement n'est exclu tant qu'une
            // procédure d'annulation sécurisée n'existe pas.
            $previousRefunds = Refund::query()
                ->where('store_id', $storeId)
                ->where('order_id', $order->id)
                ->get();

            $alreadyCommitted = 0;

            foreach ($previousRefunds as $previousRefund) {
                $alreadyCommitted += $this->calculator->toFcfa(
                    (string) $previousRefund->amount
                );
            }

            if (
                $alreadyCommitted + $refundAmount >
                $confirmedTotal
            ) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Le cumul des remboursements dépasse les paiements confirmés.',
                ]);
            }

            // Déterminer la méthode d'origine.
            // MIXED signifie plusieurs méthodes de paiement.
            $methods = array_values(array_unique($methods));

            $method = count($methods) === 1
                ? $methods[0]
                : 'MIXED';

            // Création uniquement administrative.
            // Aucun transfert de fonds.
            $refund = Refund::create([
                'order_id' => $order->id,
                'return_request_id' => $returnRequest->id,
                'amount' => $refundAmount . '.00',
                'method' => $method,
                'reference' => 'RF-' . Str::ulid(),
                'notes' => 'Remboursement simulé préparé',
            ]);

            return $refund->refresh();
        }, 3);
    }
}
