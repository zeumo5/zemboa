<?php
namespace Tests\Unit;


use App\Actions\Payments\FinalizeOrderPayment;
use Tests\TestCase;

class FinalizeOrderPaymentTest extends TestCase
{
    public function test_full_payment_is_accepted(): void
    {
        // 1. Préparer notre calculateur.
        $finalizer = new FinalizeOrderPayment();

        // 2. Vérifier le paiement intégral.
        $result = $finalizer->validatePaymentTotal(
            orderTotal: '10000.00',
            alreadyPaid: '0.00',
            newPayment: '10000.00'
        );

        // 3. Vérifier le montant obtenu en centimes.
        $this->assertSame(1000000, $result);
    }

    
public function test_partial_payment_completes_order_total(): void
{
    // 1. Préparer notre calculateur.
    $finalizer = new FinalizeOrderPayment();

    // 2. Simuler une commande partiellement payée.
    $result = $finalizer->validatePaymentTotal(
        orderTotal: '10000.00',
        alreadyPaid: '4000.00',
        newPayment: '6000.00'
    );

    // 3. Vérifier le total après paiement.
    $this->assertSame(1000000, $result);
}


public function test_payment_exceeding_remaining_balance_is_rejected(): void
{
    // 1. Préparer notre calculateur.
    $finalizer = new FinalizeOrderPayment();

    // 2. Nous attendons une erreur de validation Laravel.
    $this->expectException(
        \Illuminate\Validation\ValidationException::class
    );

    // 3. Tenter de payer 5 000 FCFA
    // alors qu'il reste seulement 3 000 FCFA.
    $finalizer->validatePaymentTotal(
        orderTotal: '10000.00',
        alreadyPaid: '7000.00',
        newPayment: '5000.00'
    );
}


public function test_zero_payment_is_rejected(): void
{
    // 1. Préparer notre calculateur.
    $finalizer = new FinalizeOrderPayment();

    // 2. ZEMBOA doit refuser un paiement de 0 FCFA.
    $this->expectException(
        \Illuminate\Validation\ValidationException::class
    );

    // 3. Simuler un paiement de montant zéro.
    $finalizer->validatePaymentTotal(
        orderTotal: '10000.00',
        alreadyPaid: '0.00',
        newPayment: '0.00'
    );
}


public function test_invalid_payment_amount_format_is_rejected(): void
{
    // 1. Préparer notre calculateur.
    $finalizer = new FinalizeOrderPayment();

    // 2. Attendre une erreur de validation.
    $this->expectException(
        \Illuminate\Validation\ValidationException::class
    );

    // 3. Essayer un montant contenant des lettres.
    $finalizer->validatePaymentTotal(
        orderTotal: '10000.00',
        alreadyPaid: '0.00',
        newPayment: '5000abc'
    );
}


public function test_amount_exceeding_database_limit_is_rejected(): void
{
    $finalizer = new FinalizeOrderPayment();

    // La colonne DECIMAL(12,2) ne peut pas
    // enregistrer 10 000 000 000,00.
    $this->expectException(
        \Illuminate\Validation\ValidationException::class
    );

    $finalizer->amountToCents('10000000000.00');
}


public function test_maximum_database_amount_is_accepted(): void
{
    // 1. Préparer notre calculateur.
    $finalizer = new FinalizeOrderPayment();

    // 2. Tester exactement la limite DECIMAL(12,2).
    $result = $finalizer->amountToCents(
        '9999999999.99'
    );

    // 3. Vérifier la conversion en centimes.
    $this->assertSame(
        999999999999,
        $result
    );
}


}
