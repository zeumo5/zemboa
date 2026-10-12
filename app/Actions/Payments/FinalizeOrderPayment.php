<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Actions\Stock\ConvertStockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Validation\ValidationException;
use LogicException;

final class FinalizeOrderPayment
{
    /**
     * Convertir un montant décimal en centimes.
     *
     * Exemples :
     * 10000.00 devient 1000000
     * 5000.50 devient 500050
     */

    public function amountToCents(string $amount): int
    {
        // 1. Vérifier le format du montant.
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => 'Montant invalide.',
            ]);
        }

        // 2. Séparer la partie entière et les décimales.
        [$whole, $decimal] = array_pad(
            explode('.', $amount, 2),
            2,
            '0'
        );

        // 3. Retirer les zéros inutiles au début.
        $normalizedWhole = ltrim($whole, '0');

        // 4. Respecter la limite DECIMAL(12,2) de MySQL.
        // Maximum : 9 999 999 999,99
        if (strlen($normalizedWhole) > 10) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant dépasse la limite autorisée.',
            ]);
        }

        // 5. Convertir en centimes sans utiliser de float.
        return ((int) $whole * 100)
            + (int) str_pad($decimal, 2, '0');
    }


    /**
     * Vérifier qu'un nouveau paiement
     * ne dépasse pas le total de la commande.
     */
    public function validatePaymentTotal(
        string $orderTotal,
        string $alreadyPaid,
        string $newPayment
    ): int {
        $total = $this->amountToCents($orderTotal);
        $paid = $this->amountToCents($alreadyPaid);
        $payment = $this->amountToCents($newPayment);

        if (
            $total <= 0 ||
            $payment <= 0 ||
            $paid > $total ||
            $payment > $total - $paid
        ) {
            throw ValidationException::withMessages([
                'amount' =>
                'Le paiement est invalide ou dépasse le solde de la commande.',
            ]);
        }

        return $paid + $payment;
    }

    /**
     * La confirmation restera désactivée
     * jusqu'à l'intégration des verrous
     * et des contrôles transactionnels.
     */

    /**
     * Vérifier que les réservations actives correspondent
     * exactement aux articles de la commande.
     *
     * Cette méthode doit être appelée dans une transaction
     * après verrouillage de la commande.
     */
    public function validateStockReservations(Order $order): Collection
    {
        // 1. Récupérer les réservations actives.

        $reservations = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->get();


        if ($reservations->isEmpty()) {
            throw ValidationException::withMessages([
                'stock' => 'Aucune réservation de stock active pour cette commande.',
            ]);
        }

        // 2. Récupérer les articles commandés.
        $orderItems = $order->items()->get([
            'product_variant_id',
            'quantity',
        ]);

        if ($orderItems->isEmpty()) {
            throw ValidationException::withMessages([
                'stock' => 'Impossible de confirmer une commande sans articles.',
            ]);
        }

        // 3. Calculer les quantités commandées.
        $orderedQuantities = $orderItems
            ->groupBy('product_variant_id')
            ->map(
                fn($items) => (int) $items->sum('quantity')
            );

        // 4. Calculer les quantités réservées.
        $reservedQuantities = $reservations
            ->groupBy('product_variant_id')
            ->map(
                fn($items) => (int) $items->sum('quantity')
            );

        // 5. Comparer les variantes et leurs quantités.
        $invalidReservations =
            $orderedQuantities->count() !== $reservedQuantities->count()
            ||
            $orderedQuantities->contains(
                fn($quantity, $variantId) =>
                $quantity !== (int) $reservedQuantities->get(
                    $variantId,
                    0
                )
            );

        if ($invalidReservations) {
            throw ValidationException::withMessages([
                'stock' =>
                'Les réservations ne correspondent pas aux produits et quantités de la commande.',
            ]);
        }
        // Retourner les réservations actives validées.
        return $reservations;
    }


    /**
     * Convertir les réservations validées en ventes.
     *
     * À appeler uniquement depuis une transaction
     * dans laquelle la commande est déjà verrouillée.
     */
    public function convertReservedStock(
        Order $order,
        ConvertStockReservation $converter,
        ?int $userId = null
    ): void {
        // Empêcher la conversion hors transaction.
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'La conversion du stock nécessite une transaction.'
            );
        }

        // Vérifier les quantités et verrouiller
        // les réservations actives.
        $reservations = $this->validateStockReservations($order);

        // Convertir chaque réservation en vente.
        foreach ($reservations as $reservation) {
            $converter->execute(
                $reservation->id,
                $userId
            );
        }
    }


    /**
     * Finaliser un paiement CASH déjà contrôlé.
     *
     * Cette méthode doit être appelée dans une transaction,
     * après verrouillage de la commande et du paiement,
     * et après vérification du caissier et du solde.
     *
     * Mobile Money reste interdit ici.
     */
    public function completeLockedCashPayment(
        Order $order,
        Payment $payment,
        int $newTotalPaid,
        ConvertStockReservation $converter,
        int $userId
    ): Payment {
        // 1. Une transaction active est obligatoire.
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'La confirmation nécessite une transaction.'
            );
        }

        // 2. Vérifier la cohérence du paiement.
        if (
            (int) $payment->order_id !== (int) $order->id ||
            (int) $payment->store_id !== (int) $order->store_id
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Paiement et commande incompatibles.',
            ]);
        }

        // 3. Ne jamais utiliser cette méthode pour Mobile Money.
        if ($payment->method !== 'CASH') {
            throw ValidationException::withMessages([
                'method' => 'Seuls les paiements CASH sont autorisés.',
            ]);
        }

        // 4. Vérifier les statuts.
        if (
            $payment->status !== 'PENDING' ||
            $order->status !== 'PENDING' ||
            !in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
            ], true)
        ) {
            throw ValidationException::withMessages([
                'status' => 'Paiement ou commande déjà traité.',
            ]);
        }

        // 5. Vérifier le montant total.
        $orderTotal = $this->amountToCents(
            (string) $order->total
        );

        if (
            $newTotalPaid <= 0 ||
            $newTotalPaid > $orderTotal
        ) {
            throw ValidationException::withMessages([
                'amount' => 'Montant total payé invalide.',
            ]);
        }

        // 6. Confirmer le paiement CASH.
        $payment->status = 'CONFIRMED';
        $payment->confirmed_at = now();
        $payment->received_by = $userId;
        $payment->save();


        // 7. Appliquer la finalisation commune.
        $this->applyLockedOrderPaymentState(
            order: $order,
            newTotalPaid: $newTotalPaid,
            converter: $converter,
            userId: $userId
        );

        return $payment->refresh();
    }


    /**
     * Calculer le nouveau total payé pour un paiement
     * Mobile Money, sans modifier la base de données.
     *
     * À appeler dans une transaction SQL après
     * verrouillage de la commande et du paiement.
     */
    public function calculateLockedMobileMoneyTotal(
        Order $order,
        Payment $payment
    ): int {
        // 1. Exiger une transaction active.
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Le calcul nécessite une transaction SQL.'
            );
        }

        // 2. Vérifier la boutique et la commande.
        if (
            (int) $payment->order_id !== (int) $order->id ||
            (int) $payment->store_id !== (int) $order->store_id
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Paiement et commande incompatibles.',
            ]);
        }

        // 3. Accepter uniquement Mobile Money en attente.
        if (
            !in_array($payment->method, [
                'MTN_MOMO',
                'ORANGE_MONEY',
            ], true) ||
            $payment->status !== 'PENDING' ||
            $order->status !== 'PENDING' ||
            !in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
            ], true)
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Paiement Mobile Money non confirmable.',
            ]);
        }

        // 4. Récupérer les paiements déjà confirmés.
        $confirmedPayments = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'CONFIRMED')
            ->lockForUpdate()
            ->get(['amount']);

        // 5. Additionner sans utiliser de float.
        $alreadyPaidCents = 0;

        foreach ($confirmedPayments as $confirmedPayment) {
            $alreadyPaidCents += $this->amountToCents(
                (string) $confirmedPayment->amount
            );
        }

        // 6. Vérifier le total et le nouveau paiement.
        $orderTotalCents = $this->amountToCents(
            (string) $order->total
        );

        $newPaymentCents = $this->amountToCents(
            (string) $payment->amount
        );

        if (
            $orderTotalCents <= 0 ||
            $newPaymentCents <= 0 ||
            $alreadyPaidCents > $orderTotalCents ||
            $newPaymentCents >
            $orderTotalCents - $alreadyPaidCents
        ) {
            throw ValidationException::withMessages([
                'amount' =>
                'Le paiement dépasse le solde disponible.',
            ]);
        }

        // 7. Retourner le nouveau montant total en centimes.
        return $alreadyPaidCents + $newPaymentCents;
    }


    /**
     * Finaliser l'état d'une commande après un paiement
     * déjà contrôlé et enregistré.
     *
     * Méthode privée : seuls les traitements de paiement
     * autorisés de cette classe peuvent l'utiliser.
     */
    private function applyLockedOrderPaymentState(
        Order $order,
        int $newTotalPaid,
        ConvertStockReservation $converter,
        ?int $userId = null
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Une transaction SQL est obligatoire.'
            );
        }

        $orderTotal = $this->amountToCents(
            (string) $order->total
        );

        if (
            $orderTotal <= 0 ||
            $newTotalPaid <= 0 ||
            $newTotalPaid > $orderTotal
        ) {
            throw ValidationException::withMessages([
                'amount' => 'Total payé invalide.',
            ]);
        }

        // Paiement partiel : conserver les réservations.
        if ($newTotalPaid < $orderTotal) {
            $order->payment_status = 'PARTIALLY_PAID';
        } else {
            // Paiement intégral : convertir les réservations.
            $this->convertReservedStock(
                order: $order,
                converter: $converter,
                userId: $userId
            );

            $order->payment_status = 'PAID';
        }

        $order->save();
    }


    public function execute(): never
    {
        throw new LogicException(
            'Finalisation commune non encore activée.'
        );
    }
}
