<?php
namespace Tests\Feature;


use App\Actions\Payments\ValidateLockedMobileMoneySuccess;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Models\User;
use App\Payments\MobileMoneyVerificationResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ValidateLockedMobileMoneySuccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_merchant_is_rejected_under_lock(): void
    {
        // 1. Créer la boutique.
        $store = Store::create([
            'name' => 'Boutique Atomic',
            'slug' => 'boutique-atomic-locked',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Créer son compte marchand.
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
            'name' => 'Client Atomic',
            'phone' => '+237690000060',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-LOCKED-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Atomic',
            'customer_phone' => '+237690000060',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 4. Créer le paiement.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-LOCKED-PAY-001',
        ]);

        $payment->store_payment_account_id = $account->id;
        $payment->save();

        // 5. Simuler un SUCCESS vers un autre marchand.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-LOCKED-PAY-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-TX-LOCKED-001',
            recipientMerchantAccountId: 'MERCHANT-BETA-999'
        );

        // 6. Exécuter sous transaction SQL.
        try {
            DB::transaction(function () use ($payment, $result) {
                app(
                    ValidateLockedMobileMoneySuccess::class
                )->execute(
                    paymentId: $payment->id,
                    result: $result
                );
            });

            $this->fail(
                'Un mauvais bénéficiaire aurait dû être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'merchant_account',
                $exception->errors()
            );
        }

        // 7. Vérifier qu'aucun encaissement
        // n'a été confirmé.
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

        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'SALE')
                ->count()
        );
    }

    
public function test_valid_merchant_is_accepted_without_confirming_payment(): void
{
    // 1. Créer la boutique et son utilisateur.
    $store = Store::create([
        'name' => 'Boutique Validation Positive',
        'slug' => 'boutique-validation-positive',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 2. Créer un compte marchand vérifié.
    $account = StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-VALID-001',
    ]);

    $account->forceFill([
        'status' => 'ACTIVE',
        'verified_at' => now(),
    ])->save();

    // 3. Créer la commande.
    $customer = Customer::create([
        'name' => 'Client Validation',
        'phone' => '+237690000061',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-VALID-LOCKED-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Validation',
        'customer_phone' => '+237690000061',
        'subtotal' => '15000.00',
        'total' => '15000.00',
    ]);

    // 4. Créer un paiement en attente.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '15000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-VALID-PAY-001',
    ]);

    $payment->store_payment_account_id = $account->id;
    $payment->save();

    // 5. Simuler un résultat cohérent.
    $result = new MobileMoneyVerificationResult(
        status: 'SUCCESS',
        reference: 'ZM-VALID-PAY-001',
        amount: '15000.00',
        currency: 'XAF',
        provider: 'MTN_MOMO',
        providerTransactionId: 'FAKE-TX-VALID-001',
        recipientMerchantAccountId: 'MERCHANT-VALID-001'
    );

    // 6. Valider sous transaction SQL.
    $validatedPayment = DB::transaction(
        fn () => app(
            ValidateLockedMobileMoneySuccess::class
        )->execute(
            paymentId: $payment->id,
            result: $result
        )
    );

    // 7. Le bon paiement est reconnu.
    $this->assertSame(
        $payment->id,
        $validatedPayment->id
    );

    // 8. Mais aucune confirmation n'est effectuée.
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

    // 9. Aucun mouvement de vente.
    $this->assertSame(
        0,
        DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->where('type', 'SALE')
            ->count()
    );

    
    // 10. Simuler la désactivation du compte marchand.
    $account->forceFill([
        'status' => 'PENDING_VERIFICATION',
    ])->save();

    // 11. Le même SUCCESS doit maintenant être refusé.
    try {
        DB::transaction(
            fn () => app(
                ValidateLockedMobileMoneySuccess::class
            )->execute(
                paymentId: $payment->id,
                result: $result
            )
        );

        $this->fail(
            'Un compte marchand désactivé doit être refusé.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'merchant_account',
            $exception->errors()
        );
    }

    // 12. Le paiement reste intact.
    $this->assertSame(
        'PENDING',
        $payment->fresh()->status
    );

    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );

    
    // 13. Réactiver le compte marchand.
    $account->forceFill([
        'status' => 'ACTIVE',
    ])->save();

    // 14. Simuler un paiement déjà traité.
    $payment->status = 'CONFIRMED';
    $payment->save();

    // 15. Une nouvelle validation doit être refusée.
    try {
        DB::transaction(
            fn () => app(
                ValidateLockedMobileMoneySuccess::class
            )->execute(
                paymentId: $payment->id,
                result: $result
            )
        );

        $this->fail(
            'Un paiement déjà confirmé doit être refusé.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'payment',
            $exception->errors()
        );
    }

    // 16. Aucune modification supplémentaire.
    $this->assertSame(
        'CONFIRMED',
        $payment->fresh()->status
    );

    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );


}


}
