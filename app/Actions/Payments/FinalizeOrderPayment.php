<?php
namespace App\Actions\Payments;


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
    public function execute(): never
    {
        throw new LogicException(
            'Finalisation commune non encore activée.'
        );
    }
}
