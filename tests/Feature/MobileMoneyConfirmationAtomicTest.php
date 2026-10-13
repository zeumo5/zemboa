<?php

namespace Tests\Feature;


use App\Actions\Payments\ReserveMobileMoneyTransactionClaim;
use App\Actions\Orders\CreateOrder;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Validation\ValidationException;
use App\Actions\Payments\ConfirmMobileMoneySuccessAtomically;
use App\Models\StorePaymentAccount;
use App\Payments\MobileMoneyVerifier;
use App\Payments\FakeMobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileMoneyConfirmationAtomicTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_provider_transaction_id_is_rejected(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Atomic Test',
            'slug' => 'boutique-atomic-test',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Atomic',
            'phone' => '+237690000050',
        ]);

        // 3. Créer une commande.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-ATOMIC-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Atomic',
            'customer_phone' => '+237690000050',
            'subtotal' => '25000.00',
            'total' => '25000.00',
        ]);

        // 4. Premier paiement Mobile Money.
        $paymentA = Payment::create([
            'order_id' => $order->id,
            'amount' => '25000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-ATOMIC-PAY-A',
        ]);

        // Enregistrer son identifiant externe.
        $paymentA->provider = 'MTN_MOMO';
        $paymentA->provider_transaction_id = 'MTN-TX-001';
        $paymentA->save();

        // 5. Deuxième paiement avec une autre
        // référence interne.
        $paymentB = Payment::create([
            'order_id' => $order->id,
            'amount' => '25000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-ATOMIC-PAY-B',
        ]);

        // 6. Essayer de réutiliser le même
        // identifiant de transaction MTN.
        $this->expectException(QueryException::class);

        $paymentB->provider = 'MTN_MOMO';
        $paymentB->provider_transaction_id = 'MTN-TX-001';
        $paymentB->save();
    }


    public function test_duplicate_transaction_rolls_back_all_changes(): void
    {
        // 1. Préparer la boutique.
        $store = Store::create([
            'name' => 'Boutique Rollback Atomic',
            'slug' => 'boutique-rollback-atomic',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $customer = Customer::create([
            'name' => 'Client Atomic',
            'phone' => '+237690000051',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-ATOMIC-ROLLBACK-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Atomic',
            'customer_phone' => '+237690000051',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 2. Première transaction externe enregistrée.
        $firstPayment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-FIRST-ROLLBACK',
        ]);

        $firstPayment->provider = 'MTN_MOMO';
        $firstPayment->provider_transaction_id = 'MTN-EXTERNAL-001';
        $firstPayment->save();

        // 3. Deuxième paiement en attente.
        $secondPayment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-SECOND-ROLLBACK',
        ]);

        // 4. Simuler une opération transactionnelle
        // qui rencontre un identifiant externe dupliqué.
        try {
            DB::transaction(function () use ($order, $secondPayment) {

                $order->payment_status = 'PAID';
                $order->save();

                $secondPayment->status = 'CONFIRMED';
                $secondPayment->confirmed_at = now();
                $secondPayment->provider = 'MTN_MOMO';
                $secondPayment->provider_transaction_id =
                    'MTN-EXTERNAL-001';

                // La contrainte UNIQUE doit refuser ceci.
                $secondPayment->save();
            });

            $this->fail(
                'La transaction dupliquée aurait dû être refusée.'
            );
        } catch (QueryException $exception) {
            // L'échec SQL est attendu.
            $this->assertNotEmpty($exception->getMessage());
        }

        // 5. La transaction doit avoir tout annulé.
        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        $this->assertSame(
            'PENDING',
            $secondPayment->fresh()->status
        );

        $this->assertNull(
            $secondPayment->fresh()->confirmed_at
        );

        $this->assertNull(
            $secondPayment->fresh()->provider_transaction_id
        );

        // Aucun mouvement de vente.
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'SALE')
                ->count()
        );
    }


    public function test_atomic_mobile_money_confirmation_rolls_back_when_stock_is_missing(): void
    {
        // 1. Préparer la boutique.
        $store = Store::create([
            'name' => 'Boutique Rollback MoMo',
            'slug' => 'boutique-rollback-momo',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Préparer un compte marchand fictif.
        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-ROLLBACK-001',
        ]);

        $account->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        // 3. Créer une commande sans réservation de stock.
        $customer = Customer::create([
            'name' => 'Client Rollback',
            'phone' => '+237690000080',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-ROLLBACK-MOMO-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Rollback',
            'customer_phone' => '+237690000080',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 4. Créer le paiement en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-ROLLBACK-PAY-001',
        ]);

        $payment->store_payment_account_id = $account->id;
        $payment->save();

        // 5. Simuler une réponse SUCCESS uniquement en test.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-ROLLBACK-PAY-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-ROLLBACK-TX-001',
            recipientMerchantAccountId: 'MERCHANT-ROLLBACK-001'
        );

        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 6. La conversion du stock doit échouer.
        try {
            app(ConfirmMobileMoneySuccessAtomically::class)
                ->execute($payment->id);

            $this->fail(
                'La confirmation aurait dû échouer sans stock réservé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'stock',
                $exception->errors()
            );
        }

        // 7. Vérifier le rollback complet.
        $payment->refresh();
        $order->refresh();

        $this->assertSame('PENDING', $payment->status);
        $this->assertNull($payment->confirmed_at);
        $this->assertNull($payment->provider);
        $this->assertNull($payment->provider_transaction_id);

        $this->assertSame('UNPAID', $order->payment_status);

        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'SALE')
                ->count()
        );

        // Vérifier que la réservation centrale est
        // également annulée après l'échec du paiement.
        $this->assertSame(
            0,
            DB::table('mobile_money_transaction_claims')
                ->where('payment_id', $payment->id)
                ->count()
        );
    }

    public function test_atomic_mobile_money_confirmation_converts_stock_once(): void
    {
        // 1. Préparer la boutique.
        $store = Store::create([
            'name' => 'Boutique Atomic Success',
            'slug' => 'boutique-atomic-success',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Préparer le compte marchand de test.
        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-ATOMIC-SUCCESS',
        ]);

        $account->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        // 3. Créer un produit de 2 000 FCFA.
        $category = Category::create([
            'name' => 'Accessoires Atomic',
            'slug' => 'accessoires-atomic-success',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Atomic',
            'slug' => 'produit-atomic-success',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'ATOMIC-SUCCESS-001',
            'price' => '2000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 4. Réceptionner 10 articles.
        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial atomic test'
        );

        // 5. Créer une commande de 5 articles.
        // CreateOrder réserve automatiquement le stock.
        $order = app(CreateOrder::class)->execute(
            customerName: 'Client Atomic Success',
            customerPhone: '690000090',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 5,
                ],
            ]
        );

        $this->assertSame('10000.00', $order->total);

        // 6. Créer un paiement Mobile Money.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-ATOMIC-SUCCESS-001',
        ]);

        $payment->store_payment_account_id = $account->id;
        $payment->save();

        // 7. Simuler SUCCESS uniquement en environnement de test.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-ATOMIC-SUCCESS-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-TX-ATOMIC-SUCCESS',
            recipientMerchantAccountId: 'MERCHANT-ATOMIC-SUCCESS'
        );

        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 8. Confirmer atomiquement.
        $confirmedPayment = app(
            ConfirmMobileMoneySuccessAtomically::class
        )->execute($payment->id);

        // 9. Vérifier le paiement.
        $this->assertSame(
            'CONFIRMED',
            $confirmedPayment->status
        );

        $this->assertNotNull(
            $confirmedPayment->confirmed_at
        );

        $this->assertSame(
            'FAKE-TX-ATOMIC-SUCCESS',
            $confirmedPayment->provider_transaction_id
        );

        // 10. Vérifier la commande.
        $this->assertSame(
            'PAID',
            $order->fresh()->payment_status
        );

        // 11. Vérifier le stock physique et réservé.
        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(
            5,
            (int) $stock->physical_quantity
        );

        $this->assertSame(
            0,
            (int) $stock->reserved_quantity
        );

        // 12. Vérifier qu'une seule vente a été enregistrée.
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('product_variant_id', $variant->id)
                ->where('type', 'SALE')
                ->count()
        );

        // 13. Une deuxième confirmation doit être refusée.
        try {
            app(ConfirmMobileMoneySuccessAtomically::class)
                ->execute($payment->id);

            $this->fail(
                'Une deuxième confirmation doit être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'status',
                $exception->errors()
            );
        }

        // 14. Aucun second mouvement SALE.
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('product_variant_id', $variant->id)
                ->where('type', 'SALE')
                ->count()
        );
    }


    public function test_duplicate_external_transaction_rolls_back_mobile_money_confirmation(): void
    {
        // 1. Préparer la boutique.
        $store = Store::create([
            'name' => 'Boutique Duplicate Atomic',
            'slug' => 'boutique-duplicate-atomic',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Compte marchand de test.
        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-DUP-001',
        ]);

        $account->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        // 3. Première transaction externe déjà enregistrée.
        $customer = Customer::create([
            'name' => 'Premier client',
            'phone' => '+237690000091',
        ]);

        $firstOrder = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-DUP-FIRST-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Premier client',
            'customer_phone' => '+237690000091',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        Payment::create([
            'order_id' => $firstOrder->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'CONFIRMED',
            'reference' => 'ZM-DUP-FIRST-PAY',
            'provider' => 'MTN_MOMO',
            'provider_transaction_id' => 'FAKE-TX-DUP-001',
            'confirmed_at' => now(),
        ]);

        // 4. Produit et stock pour une deuxième commande.
        $category = Category::create([
            'name' => 'Accessoires Duplicate',
            'slug' => 'accessoires-duplicate',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Duplicate',
            'slug' => 'produit-duplicate',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'DUP-ATOMIC-001',
            'price' => '2000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial du test doublon'
        );

        // CreateOrder réserve automatiquement 5 articles.
        $order = app(CreateOrder::class)->execute(
            customerName: 'Deuxième client',
            customerPhone: '690000092',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 5,
                ],
            ]
        );

        // 5. Deuxième paiement en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-DUP-SECOND-PAY',
        ]);

        $payment->store_payment_account_id = $account->id;
        $payment->save();

        // 6. Simuler un SUCCESS réutilisant le même ID externe.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-DUP-SECOND-PAY',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-TX-DUP-001',
            recipientMerchantAccountId: 'MERCHANT-DUP-001'
        );

        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 7. La contrainte UNIQUE doit provoquer un rollback.
        try {
            app(ConfirmMobileMoneySuccessAtomically::class)
                ->execute($payment->id);

            $this->fail(
                'Un identifiant externe déjà utilisé doit être refusé.'
            );
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // 8. Paiement et commande inchangés.
        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );

        $this->assertNull(
            $payment->fresh()->confirmed_at
        );

        $this->assertNull(
            $payment->fresh()->provider_transaction_id
        );

        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        // 9. Stock toujours réservé, aucune vente.
        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, (int) $stock->physical_quantity);
        $this->assertSame(5, (int) $stock->reserved_quantity);

        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('product_variant_id', $variant->id)
                ->where('type', 'SALE')
                ->count()
        );
    }


    public function test_reconciliation_claim_blocks_atomic_confirmation(): void
    {
        // 1. Préparer la boutique.
        $store = Store::create([
            'name' => 'Boutique Reverse Claim',
            'slug' => 'boutique-reverse-claim',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Préparer le compte marchand.
        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-REVERSE-001',
        ]);

        $account->forceFill([
            'status' => 'ACTIVE',
            'verified_at' => now(),
        ])->save();

        // 3. Créer un paiement FAILED pour la réconciliation.
        $customer = Customer::create([
            'name' => 'Client Reconciliation',
            'phone' => '690000401',
        ]);

        $oldOrder = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-REVERSE-OLD-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Reconciliation',
            'customer_phone' => '690000401',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        $failedPayment = Payment::create([
            'order_id' => $oldOrder->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'FAILED',
            'reference' => 'ZM-REVERSE-FAILED-001',
        ]);

        // 4. Réserver l'identifiant pour la réconciliation.
        DB::transaction(function () use ($failedPayment) {
            app(ReserveMobileMoneyTransactionClaim::class)
                ->execute(
                    payment: $failedPayment,
                    provider: 'MTN_MOMO',
                    providerTransactionId: 'MTN-REVERSE-TX-001',
                    claimType: 'RECONCILIATION'
                );
        });

        // 5. Créer un produit et un stock réel de test.
        $category = Category::create([
            'name' => 'Accessoires Reverse',
            'slug' => 'accessoires-reverse',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Reverse',
            'slug' => 'produit-reverse',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'REVERSE-CLAIM-001',
            'price' => '2000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial reverse claim'
        );

        // 6. Créer une commande qui réserve 5 articles.
        $order = app(CreateOrder::class)->execute(
            customerName: 'Nouveau Client',
            customerPhone: '690000402',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 5,
                ],
            ]
        );

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-REVERSE-NEW-001',
        ]);

        $payment->store_payment_account_id = $account->id;
        $payment->save();

        // 7. Simuler un SUCCESS réutilisant l'identifiant
        // déjà réservé par la réconciliation.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-REVERSE-NEW-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'MTN-REVERSE-TX-001',
            recipientMerchantAccountId: 'MERCHANT-REVERSE-001'
        );

        $this->app->instance(
            MobileMoneyVerifier::class,
            new FakeMobileMoneyVerifier($result)
        );

        // 8. La confirmation doit échouer.
        try {
            app(ConfirmMobileMoneySuccessAtomically::class)
                ->execute($payment->id);

            $this->fail(
                'Une transaction réservée pour réconciliation '
                    . 'ne doit pas confirmer un autre paiement.'
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // 9. La commande et le paiement sont inchangés.
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

        // 10. Le stock reste réservé.
        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, (int) $stock->physical_quantity);
        $this->assertSame(5, (int) $stock->reserved_quantity);

        // 11. Une seule réservation centrale existe.
        $this->assertSame(
            1,
            DB::table('mobile_money_transaction_claims')->count()
        );

        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('product_variant_id', $variant->id)
                ->where('type', 'SALE')
                ->count()
        );
    }
}
