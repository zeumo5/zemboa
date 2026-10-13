<?php

namespace Tests\Feature;

use App\Actions\Payments\ReserveMobileMoneyTransactionClaim;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use App\Actions\Payments\OpenLateMobileMoneyReconciliationCase;
use App\Models\Customer;
use App\Models\MobileMoneyReconciliationCase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Models\User;
use App\Payments\FakeMobileMoneyVerifier;
use App\Payments\MobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpenLateMobileMoneyReconciliationCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_success_opens_case_without_confirming_payment(): void
    {
        // 1. Créer la boutique et son utilisateur.
        $store = Store::create([
            'name' => 'Boutique Reconciliation',
            'slug' => 'boutique-reconciliation-test',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Compte marchand fictif vérifié.
        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-RECON-001',
        ]);

        $account->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        // 3. Créer la commande.
        $customer = Customer::create([
            'name' => 'Client Reconciliation',
            'phone' => '+237690000100',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-RECON-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Reconciliation',
            'customer_phone' => '+237690000100',
            'subtotal' => '15000.00',
            'total' => '15000.00',
        ]);

        // 4. Paiement précédemment déclaré échoué.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '15000.00',
            'method' => 'MTN_MOMO',
            'status' => 'FAILED',
            'reference' => 'ZM-RECON-PAY-001',
        ]);

        $payment->store_payment_account_id = $account->id;
        $payment->save();

        // 5. Simuler un SUCCESS tardif.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-RECON-PAY-001',
            amount: '15000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-RECON-TX-001',
            recipientMerchantAccountId: 'MERCHANT-RECON-001'
        );

        // Simulateur réservé aux tests.
        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 6. Ouvrir le dossier.
        $action = app(
            OpenLateMobileMoneyReconciliationCase::class
        );

        $case = $action->execute($payment->id);

        // 7. Vérifier le dossier créé.
        $this->assertSame('OPEN', $case->status);
        $this->assertSame($store->id, $case->store_id);
        $this->assertSame($payment->id, $case->payment_id);

        $this->assertSame(
            'FAILED',
            $case->original_payment_status
        );

        $this->assertSame(
            'SUCCESS',
            $case->reported_provider_status
        );

        $this->assertSame(
            'FAKE-RECON-TX-001',
            $case->provider_transaction_id
        );

        // 8. Aucun encaissement automatiquement confirmé.
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

        // Aucun mouvement de vente.
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'SALE')
                ->count()
        );

        // 9. Répéter la vérification.
        // Aucun deuxième dossier ne doit être créé.
        $secondCase = $action->execute($payment->id);

        $this->assertSame($case->id, $secondCase->id);

        $this->assertSame(
            1,
            MobileMoneyReconciliationCase::query()
                ->where('payment_id', $payment->id)
                ->count()
        );
    }

    public function test_same_external_transaction_cannot_belong_to_two_reconciliation_cases(): void
    {
        // Préparer deux boutiques avec des paiements distincts.
        $createScenario = function (string $suffix): array {
            $store = Store::create([
                'name' => 'Boutique ' . $suffix,
                'slug' => 'recon-duplicate-' . strtolower($suffix),
                'status' => 'ACTIVE',
            ]);

            $user = User::factory()->create([
            ]);

            $this->actingAs($user);
            app(TenantContext::class)->setFromUser($user);

            $customer = Customer::create([
                'name' => 'Client ' . $suffix,
                'phone' => '+237690000110',
            ]);

            $order = Order::create([
                'customer_id' => $customer->id,
                'order_number' => 'ZM-RECON-DUP-' . $suffix,
                'fulfillment_type' => 'PICKUP',
                'customer_name' => 'Client ' . $suffix,
                'customer_phone' => '+237690000110',
                'subtotal' => '15000.00',
                'total' => '15000.00',
            ]);

            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => '15000.00',
                'method' => 'MTN_MOMO',
                'status' => 'FAILED',
                'reference' => 'ZM-RECON-PAY-' . $suffix,
            ]);

            return compact('store', 'order', 'payment');
        };

        $first = $createScenario('A');
        $second = $createScenario('B');

        // Insérer le premier dossier.
        // DB::table est utilisé ici pour tester
        // directement la contrainte SQL, sans filtre tenant.
        $caseData = function (array $scenario): array {
            return [
                'store_id' => $scenario['store']->id,
                'payment_id' => $scenario['payment']->id,
                'provider' => 'MTN_MOMO',
                'payment_reference' =>
                $scenario['payment']->reference,
                'provider_transaction_id' =>
                'MTN-SAME-TRANSACTION-001',
                'recipient_merchant_account_id' =>
                'MERCHANT-TEST-001',
                'amount' => '15000.00',
                'currency' => 'XAF',
                'original_payment_status' => 'FAILED',
                'reported_provider_status' => 'SUCCESS',
                'status' => 'OPEN',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        DB::table('mobile_money_reconciliation_cases')
            ->insert($caseData($first));

        // La deuxième boutique tente d'utiliser
        // exactement le même identifiant externe.
        try {
            DB::table('mobile_money_reconciliation_cases')
                ->insert($caseData($second));

            $this->fail(
                'Une transaction externe dupliquée doit être refusée.'
            );
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // Vérifier qu'un seul dossier existe.
        $this->assertSame(
            1,
            DB::table('mobile_money_reconciliation_cases')
                ->count()
        );

        // Aucun paiement ne doit être confirmé.
        $this->assertSame(
            'FAILED',
            $first['payment']->fresh()->status
        );

        $this->assertSame(
            'FAILED',
            $second['payment']->fresh()->status
        );

        // Aucune vente n'a été enregistrée.
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('type', 'SALE')
                ->count()
        );
    }


    public function test_late_success_rejects_transaction_used_by_another_payment(): void
    {
        // 1. Boutique A : transaction déjà confirmée.
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'recon-cross-alpha',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $this->actingAs($userA);
        app(TenantContext::class)->setFromUser($userA);

        $customerA = Customer::create([
            'name' => 'Client Alpha',
            'phone' => '690000201',
        ]);

        $orderA = Order::create([
            'customer_id' => $customerA->id,
            'order_number' => 'ZM-CROSS-A',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Alpha',
            'customer_phone' => '690000201',
            'subtotal' => '15000.00',
            'total' => '15000.00',
        ]);

        $paymentA = Payment::create([
            'order_id' => $orderA->id,
            'amount' => '15000.00',
            'method' => 'MTN_MOMO',
            'status' => 'CONFIRMED',
            'reference' => 'ZM-CROSS-PAY-A',
            'provider' => 'MTN_MOMO',
            'provider_transaction_id' => 'MTN-TX-500',
            'confirmed_at' => now(),
        ]);

        // 2. Boutique B : paiement déclaré échoué.
        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'recon-cross-beta',
            'status' => 'ACTIVE',
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        $this->actingAs($userB);
        app(TenantContext::class)->setFromUser($userB);

        $accountB = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-BETA-500',
        ]);

        $accountB->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        $customerB = Customer::create([
            'name' => 'Client Beta',
            'phone' => '690000202',
        ]);

        $orderB = Order::create([
            'customer_id' => $customerB->id,
            'order_number' => 'ZM-CROSS-B',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Beta',
            'customer_phone' => '690000202',
            'subtotal' => '15000.00',
            'total' => '15000.00',
        ]);

        $paymentB = Payment::create([
            'order_id' => $orderB->id,
            'amount' => '15000.00',
            'method' => 'MTN_MOMO',
            'status' => 'FAILED',
            'reference' => 'ZM-CROSS-PAY-B',
        ]);

        $paymentB->store_payment_account_id = $accountB->id;
        $paymentB->save();

        // 3. Simuler la réutilisation de la transaction A.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-CROSS-PAY-B',
            amount: '15000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'MTN-TX-500',
            recipientMerchantAccountId: 'MERCHANT-BETA-500'
        );

        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 4. La réconciliation doit être refusée.
        try {
            app(OpenLateMobileMoneyReconciliationCase::class)
                ->execute($paymentB->id);

            $this->fail(
                'Une transaction déjà utilisée doit être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'provider_transaction_id',
                $exception->errors()
            );
        }

        // 5. Vérifier qu'aucune donnée n'a été altérée.
        $this->assertSame(
            'CONFIRMED',
            $paymentA->fresh()->status
        );

        $this->assertSame(
            'FAILED',
            $paymentB->fresh()->status
        );

        $this->assertSame(
            'UNPAID',
            $orderB->fresh()->payment_status
        );

        $this->assertSame(
            0,
            DB::table('mobile_money_reconciliation_cases')
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('type', 'SALE')
                ->count()
        );
    }


    public function test_confirmation_claim_blocks_late_reconciliation_from_another_store(): void
    {
        // 1. Boutique A : préparer un paiement en attente.
        $storeA = Store::create([
            'name' => 'Boutique Claim Alpha',
            'slug' => 'claim-recon-alpha',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $this->actingAs($userA);
        app(TenantContext::class)->setFromUser($userA);

        $customerA = Customer::create([
            'name' => 'Client Alpha',
            'phone' => '690000301',
        ]);

        $orderA = Order::create([
            'customer_id' => $customerA->id,
            'order_number' => 'ZM-CLAIM-RECON-A',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Alpha',
            'customer_phone' => '690000301',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        $paymentA = Payment::create([
            'order_id' => $orderA->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-CLAIM-PAY-A',
        ]);

        // 2. Réserver la transaction pour le circuit
        // de confirmation, sans confirmer le paiement.
        DB::transaction(function () use ($paymentA) {
            app(ReserveMobileMoneyTransactionClaim::class)
                ->execute(
                    payment: $paymentA,
                    provider: 'MTN_MOMO',
                    providerTransactionId: 'MTN-CROSS-CLAIM-001',
                    claimType: 'CONFIRMATION'
                );
        });

        // 3. Boutique B : compte marchand vérifié.
        $storeB = Store::create([
            'name' => 'Boutique Claim Beta',
            'slug' => 'claim-recon-beta',
            'status' => 'ACTIVE',
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        $this->actingAs($userB);
        app(TenantContext::class)->setFromUser($userB);

        $accountB = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-CLAIM-BETA',
        ]);

        $accountB->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        $customerB = Customer::create([
            'name' => 'Client Beta',
            'phone' => '690000302',
        ]);

        $orderB = Order::create([
            'customer_id' => $customerB->id,
            'order_number' => 'ZM-CLAIM-RECON-B',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Beta',
            'customer_phone' => '690000302',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        $paymentB = Payment::create([
            'order_id' => $orderB->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'FAILED',
            'reference' => 'ZM-CLAIM-PAY-B',
        ]);

        $paymentB->store_payment_account_id = $accountB->id;
        $paymentB->save();

        // 4. Simuler un SUCCESS tardif réutilisant
        // l'identifiant déjà réservé par Alpha.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-CLAIM-PAY-B',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'MTN-CROSS-CLAIM-001',
            recipientMerchantAccountId: 'MERCHANT-CLAIM-BETA'
        );

        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 5. MySQL/SQLite doit refuser le doublon.
        try {
            app(OpenLateMobileMoneyReconciliationCase::class)
                ->execute($paymentB->id);

            $this->fail(
                'La réconciliation ne doit pas réutiliser '
                    . 'une transaction déjà réservée.'
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // 6. Vérifier qu'aucun nouveau claim n'existe.
        $this->assertSame(
            1,
            DB::table('mobile_money_transaction_claims')
                ->count()
        );

        // Aucun dossier de réconciliation créé.
        $this->assertSame(
            0,
            DB::table('mobile_money_reconciliation_cases')
                ->count()
        );

        // Aucune commande ni paiement modifié.
        $this->assertSame(
            'FAILED',
            $paymentB->fresh()->status
        );

        $this->assertSame(
            'UNPAID',
            $orderB->fresh()->payment_status
        );
    }
}
