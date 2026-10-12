<?php

namespace Tests\Feature;

use App\Models\StorePaymentAccount;
use App\Actions\Payments\VerifyMobileMoneyPayment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Payments\FakeMobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VerifyMobileMoneyPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_incorrect_transaction_reference_is_rejected(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Vérification',
            'slug' => 'boutique-verification-momo',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer et authentifier un utilisateur.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // 3. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Vérification',
            'phone' => '+237690000010',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-VERIFY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Vérification',
            'customer_phone' => '+237690000010',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer le paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-MOMO-001',
        ]);

        // 6. Simuler une réponse avec une mauvaise référence.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-FAUSSE-999',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-MTN-001'
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. La vérification doit être refusée.
        try {
            app(VerifyMobileMoneyPayment::class)->execute(
                paymentId: $payment->id,
                verifier: $verifier
            );

            $this->fail(
                'Une référence incorrecte aurait dû être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'reference',
                $exception->errors()
            );
        }

        // 8. Le paiement doit rester en attente.
        $payment->refresh();
        $order->refresh();

        $this->assertSame('PENDING', $payment->status);

        $this->assertNull($payment->confirmed_at);

        $this->assertSame(
            'UNPAID',
            $order->payment_status
        );
    }

    public function test_incorrect_transaction_amount_is_rejected(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Vérification',
            'slug' => 'boutique-verification-momo',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer et authentifier un utilisateur.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // 3. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Vérification',
            'phone' => '+237690000010',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-VERIFY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Vérification',
            'customer_phone' => '+237690000010',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer le paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-MOMO-001',
        ]);

        // 6. Simuler une réponse avec une mauvaise référence.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-MOMO-001',
            amount: '8000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-MTN-001'
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. La vérification doit être refusée.
        try {
            app(VerifyMobileMoneyPayment::class)->execute(
                paymentId: $payment->id,
                verifier: $verifier
            );

            $this->fail(
                'Une référence incorrecte aurait dû être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'amount',
                $exception->errors()
            );
        }

        // 8. Le paiement doit rester en attente.
        $payment->refresh();
        $order->refresh();

        $this->assertSame('PENDING', $payment->status);

        $this->assertNull($payment->confirmed_at);

        $this->assertSame(
            'UNPAID',
            $order->payment_status
        );
    }

    public function test_incorrect_payment_provider_is_rejected(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Vérification',
            'slug' => 'boutique-verification-momo',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer et authentifier un utilisateur.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // 3. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Vérification',
            'phone' => '+237690000010',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-VERIFY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Vérification',
            'customer_phone' => '+237690000010',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer le paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-MOMO-001',
        ]);

        // 6. Simuler une réponse avec une mauvaise référence.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-MOMO-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'ORANGE_MONEY',
            providerTransactionId: 'FAKE-ORANGE-003'

        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. La vérification doit être refusée.
        try {
            app(VerifyMobileMoneyPayment::class)->execute(
                paymentId: $payment->id,
                verifier: $verifier
            );

            $this->fail(
                'Une référence incorrecte aurait dû être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'provider',
                $exception->errors()
            );
        }

        // 8. Le paiement doit rester en attente.
        $payment->refresh();
        $order->refresh();

        $this->assertSame('PENDING', $payment->status);

        $this->assertNull($payment->confirmed_at);

        $this->assertSame(
            'UNPAID',
            $order->payment_status
        );
    }

    public function test_incorrect_payment_currency_is_rejected(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Vérification',
            'slug' => 'boutique-verification-momo',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer et authentifier un utilisateur.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // 3. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Vérification',
            'phone' => '+237690000010',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-VERIFY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Vérification',
            'customer_phone' => '+237690000010',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer le paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-MOMO-001',
        ]);

        // 6. Simuler une réponse avec une mauvaise référence.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-MOMO-001',
            amount: '10000.00',
            currency: 'EUR',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-MTN-004'
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. La vérification doit être refusée.
        try {
            app(VerifyMobileMoneyPayment::class)->execute(
                paymentId: $payment->id,
                verifier: $verifier
            );

            $this->fail(
                'Une référence incorrecte aurait dû être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'currency',
                $exception->errors()
            );
        }

        // 8. Le paiement doit rester en attente.
        $payment->refresh();
        $order->refresh();

        $this->assertSame('PENDING', $payment->status);

        $this->assertNull($payment->confirmed_at);

        $this->assertSame(
            'UNPAID',
            $order->payment_status
        );
    }

    public function test_success_without_provider_transaction_id_is_rejected(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Vérification',
            'slug' => 'boutique-verification-momo',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer et authentifier un utilisateur.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // 3. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Vérification',
            'phone' => '+237690000010',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-VERIFY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Vérification',
            'customer_phone' => '+237690000010',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer le paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-MOMO-001',
        ]);

        // 6. Simuler une réponse avec une mauvaise référence.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-MOMO-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: null
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. La vérification doit être refusée.
        try {
            app(VerifyMobileMoneyPayment::class)->execute(
                paymentId: $payment->id,
                verifier: $verifier
            );

            $this->fail(
                'Une référence incorrecte aurait dû être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'provider_transaction_id',
                $exception->errors()
            );
        }

        // 8. Le paiement doit rester en attente.
        $payment->refresh();
        $order->refresh();

        $this->assertSame('PENDING', $payment->status);

        $this->assertNull($payment->confirmed_at);

        $this->assertSame(
            'UNPAID',
            $order->payment_status
        );
    }

    
public function test_valid_mobile_money_verification_is_accepted(): void
{
    // 1. Créer la boutique.
    $store = Store::create([
        'name' => 'Boutique Test Vérification',
        'slug' => 'boutique-verification-momo',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 2. Créer un compte marchand fictif.
    $account = StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-TEST-001',
        'wallet_number' => '690000001',
    ]);

    // Activation simulée, uniquement pour ce test.
    $account->forceFill([
        'status' => 'ACTIVE',
        'verified_at' => now(),
    ])->save();

    // 3. Créer le client.
    $customer = Customer::create([
        'name' => 'Client Vérification',
        'phone' => '+237690000010',
    ]);

    // 4. Créer une commande.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-VERIFY-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Vérification',
        'customer_phone' => '+237690000010',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 5. Créer un paiement en attente.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '10000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-MOMO-001',
    ]);

    // Associer le bon bénéficiaire.
    $payment->store_payment_account_id = $account->id;
    $payment->save();

    // 6. Simuler une réponse SUCCESS valide.
    $fakeResult = new MobileMoneyVerificationResult(
        status: 'SUCCESS',
        reference: 'ZM-MOMO-001',
        amount: '10000.00',
        currency: 'XAF',
        provider: 'MTN_MOMO',
        providerTransactionId: 'FAKE-MTN-005',
        recipientMerchantAccountId: 'MERCHANT-TEST-001'
    );

    $verifier = new FakeMobileMoneyVerifier($fakeResult);

    // 7. Effectuer une seule vérification.
    $verification = app(
        VerifyMobileMoneyPayment::class
    )->execute(
        paymentId: $payment->id,
        verifier: $verifier
    );

    // 8. Vérifier les informations retournées.
    $this->assertSame('SUCCESS', $verification->status);

    $this->assertSame(
        'ZM-MOMO-001',
        $verification->reference
    );

    $this->assertSame('10000.00', $verification->amount);
    $this->assertSame('XAF', $verification->currency);
    $this->assertSame('MTN_MOMO', $verification->provider);

    $this->assertSame(
        'FAKE-MTN-005',
        $verification->providerTransactionId
    );

    $this->assertSame(
        $account->merchant_account_id,
        $verification->recipientMerchantAccountId
    );

    // 9. Vérifier qu'aucune confirmation n'a eu lieu.
    $this->assertSame(
        'PENDING',
        $payment->fresh()->status
    );

    $this->assertNull(
        $payment->fresh()->confirmed_at
    );

    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );
}


public function test_success_with_wrong_merchant_recipient_is_rejected(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'alpha-wrong-recipient',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 2. Créer le véritable compte marchand.
    $account = StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-ALPHA-001',
    ]);

    $account->forceFill([
        'status' => 'ACTIVE',
        'verified_at' => now(),
    ])->save();

    // 3. Créer la commande.
    $customer = Customer::create([
        'name' => 'Client Alpha',
        'phone' => '+237690000010',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-WRONG-RECIPIENT-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Alpha',
        'customer_phone' => '+237690000010',
        'subtotal' => '25000.00',
        'total' => '25000.00',
    ]);

    // 4. Créer le paiement associé à Alpha.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '25000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-WRONG-RECIPIENT-001',
    ]);

    $payment->store_payment_account_id = $account->id;
    $payment->save();

    // 5. Simuler un paiement annoncé vers Beta.
    $fakeResult = new MobileMoneyVerificationResult(
        status: 'SUCCESS',
        reference: 'ZM-WRONG-RECIPIENT-001',
        amount: '25000.00',
        currency: 'XAF',
        provider: 'MTN_MOMO',
        providerTransactionId: 'FAKE-MTN-WRONG-001',
        recipientMerchantAccountId: 'MERCHANT-BETA-999'
    );

    $verifier = new FakeMobileMoneyVerifier($fakeResult);

    // 6. Vérifier que le mauvais bénéficiaire est refusé.
    try {
        app(VerifyMobileMoneyPayment::class)->execute(
            paymentId: $payment->id,
            verifier: $verifier
        );

        $this->fail(
            'Un paiement vers un mauvais bénéficiaire doit être refusé.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'merchant_account',
            $exception->errors()
        );
    }

    // 7. Aucune confirmation ne doit avoir lieu.
    $this->assertSame(
        'PENDING',
        $payment->fresh()->status
    );

    $this->assertNull(
        $payment->fresh()->confirmed_at
    );

    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );
}


}
