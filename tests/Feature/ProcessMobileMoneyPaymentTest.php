<?php

namespace Tests\Feature;

use App\Models\StorePaymentAccount;
use App\Actions\Payments\ProcessMobileMoneyPayment;
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
use Tests\TestCase;

class ProcessMobileMoneyPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_mobile_money_result_keeps_payment_pending(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Process MoMo',
            'slug' => 'boutique-process-momo',
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
            'name' => 'Client Process MoMo',
            'phone' => '+237690000030',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-PROCESS-MOMO-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Process MoMo',
            'customer_phone' => '+237690000030',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer un paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-PROCESS-MOMO-001',
        ]);

        // 6. Simuler un paiement toujours en attente.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'PENDING',
            reference: 'ZM-PROCESS-MOMO-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: null
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. Préparer le processus avec le simulateur.
        $processor = new ProcessMobileMoneyPayment(
            app(VerifyMobileMoneyPayment::class),
            $verifier
        );

        // 8. Traiter la réponse simulée.
        $result = $processor->execute($payment->id);

        // 9. Vérifier le résultat retourné.
        $this->assertSame('PENDING', $result->status);

        $this->assertSame(
            'ZM-PROCESS-MOMO-001',
            $result->reference
        );

        // 10. Vérifier qu'aucune confirmation n'a eu lieu.
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

        // Toujours un seul paiement enregistré.
        $this->assertSame(
            1,
            Payment::query()
                ->where('order_id', $order->id)
                ->count()
        );
    }

    public function test_failed_mobile_money_result_marks_payment_failed(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Process MoMo',
            'slug' => 'boutique-process-momo',
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
            'name' => 'Client Process MoMo',
            'phone' => '+237690000030',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-PROCESS-MOMO-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Process MoMo',
            'customer_phone' => '+237690000030',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer un paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-PROCESS-MOMO-001',
        ]);

        // 6. Simuler un paiement toujours en attente.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'FAILED',
            reference: 'ZM-PROCESS-MOMO-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: null
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. Préparer le processus avec le simulateur.
        $processor = new ProcessMobileMoneyPayment(
            app(VerifyMobileMoneyPayment::class),
            $verifier
        );

        // 8. Traiter la réponse simulée.
        $result = $processor->execute($payment->id);

        // 9. Vérifier le résultat retourné.
        $this->assertSame('FAILED', $result->status);

        $this->assertSame(
            'ZM-PROCESS-MOMO-001',
            $result->reference
        );

        // 10. Vérifier qu'aucune confirmation n'a eu lieu.

        $this->assertSame(
            'FAILED',
            $payment->fresh()->status
        );

        $this->assertNull(
            $payment->fresh()->confirmed_at
        );

        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        // Toujours un seul paiement enregistré.
        $this->assertSame(
            1,
            Payment::query()
                ->where('order_id', $order->id)
                ->count()
        );


        // Un paiement déjà FAILED ne doit pas
        // pouvoir être traité une deuxième fois.
        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $processor->execute($payment->id);
    }

    public function test_success_mobile_money_confirmation_remains_disabled(): void
    {

        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Process MoMo',
            'slug' => 'boutique-process-momo',
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
            'name' => 'Client Process MoMo',
            'phone' => '+237690000030',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-PROCESS-MOMO-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Process MoMo',
            'customer_phone' => '+237690000030',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer un paiement Mobile Money en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-PROCESS-MOMO-001',
        ]);


        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-PROCESS-SUCCESS-001',
        ]);

        // Activation fictive réservée au test.
        $account->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        // Associer le compte bénéficiaire au paiement.
        $payment->store_payment_account_id = $account->id;
        $payment->save();


        // 6. Simuler un paiement toujours en attente.
        $fakeResult = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-PROCESS-MOMO-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-MTN-SUCCESS-001',
            recipientMerchantAccountId: 'MERCHANT-PROCESS-SUCCESS-001'
        );

        $verifier = new FakeMobileMoneyVerifier($fakeResult);

        // 7. Préparer le processus avec le simulateur.
        $processor = new ProcessMobileMoneyPayment(
            app(VerifyMobileMoneyPayment::class),
            $verifier
        );

        // 8. Traiter la réponse simulée.

        try {
            $processor->execute($payment->id);

            $this->fail(
                'Une confirmation SUCCESS aurait dû être bloquée.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Confirmation Mobile Money non encore activée.',
                $exception->getMessage()
            );
        }
        // 10. Vérifier qu'aucune confirmation n'a eu lieu.
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

        // Toujours un seul paiement enregistré.
        $this->assertSame(
            1,
            Payment::query()
                ->where('order_id', $order->id)
                ->count()
        );
    }
}
