<?php
namespace Tests\Feature;

use App\Actions\Payments\AssignMerchantAccountToPayment;
use Illuminate\Validation\ValidationException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorePaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_account_belongs_to_current_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha-payment',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $account = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-ALPHA-001',
            'wallet_number' => '690000001',
        ]);

        $this->assertSame($store->id, $account->store_id);

        $this->assertSame(
            'PENDING_VERIFICATION',
            $account->status
        );

        $this->assertNull($account->verified_at);

        $this->assertDatabaseHas('store_payment_accounts', [
            'id' => $account->id,
            'store_id' => $store->id,
            'method' => 'MTN_MOMO',
            'status' => 'PENDING_VERIFICATION',
        ]);
    }

    public function test_payment_accounts_are_isolated_by_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'alpha-payment-isolation',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'beta-payment-isolation',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Créer le compte de la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        $accountA = StorePaymentAccount::create([
            'method' => 'MTN_MOMO',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-A-001',
        ]);

        // Créer le compte de la boutique B.
        app(TenantContext::class)->setFromUser($userB);

        $accountB = StorePaymentAccount::create([
            'method' => 'ORANGE_MONEY',
            'gateway' => 'TEST_GATEWAY',
            'merchant_account_id' => 'MERCHANT-B-001',
        ]);

        // Revenir dans la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        $this->assertNotNull(
            StorePaymentAccount::find($accountA->id)
        );

        $this->assertNull(
            StorePaymentAccount::find($accountB->id)
        );

        $this->assertSame(
            1,
            StorePaymentAccount::count()
        );
    }

    
public function test_store_cannot_register_same_payment_method_twice(): void
{
    $store = Store::create([
        'name' => 'Boutique Doublon',
        'slug' => 'boutique-doublon',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-001',
    ]);

    $this->expectException(QueryException::class);

    StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-002',
    ]);
}

public function test_merchant_account_cannot_belong_to_two_stores(): void
{
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'alpha-merchant-unique',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'beta-merchant-unique',
        'status' => 'ACTIVE',
    ]);

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    app(TenantContext::class)->setFromUser($userA);

    StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'SHARED-MERCHANT-001',
    ]);

    app(TenantContext::class)->setFromUser($userB);

    $this->expectException(QueryException::class);

    StorePaymentAccount::create([
        'method' => 'ORANGE_MONEY',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'SHARED-MERCHANT-001',
    ]);
}


public function test_payment_can_reference_store_merchant_account(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Paiement Test',
        'slug' => 'boutique-paiement-test',
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
        'merchant_account_id' => 'MERCHANT-TEST-001',
        'wallet_number' => '690000001',
    ]);

    // 3. Créer un client et une commande.
    $customer = Customer::create([
        'name' => 'Client Test',
        'phone' => '+237690000001',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-ACCOUNT-TEST-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Test',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 4. Créer le paiement.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '10000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-ACCOUNT-TEST-001',
    ]);

    // 5. Associer le compte marchand côté serveur.
    $payment->store_payment_account_id = $account->id;
    $payment->save();

    // 6. Vérifier la relation.
    $payment->refresh();

    $this->assertSame(
        $account->id,
        $payment->store_payment_account_id
    );

    $this->assertTrue(
        $payment->storePaymentAccount->is($account)
    );

    $this->assertSame(
        $store->id,
        $payment->storePaymentAccount->store_id
    );
}


public function test_only_verified_merchant_account_can_be_assigned(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Paiement Sécurisé',
        'slug' => 'boutique-paiement-securise',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 2. Créer un compte marchand non vérifié.
    $account = StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'SECURE-MERCHANT-001',
        'wallet_number' => '690000001',
    ]);

    // 3. Créer une commande.
    $customer = Customer::create([
        'name' => 'Client Sécurité',
        'phone' => '+237690000001',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-SECURE-ACCOUNT-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Sécurité',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 4. Créer un paiement Mobile Money en attente.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '10000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-SECURE-ACCOUNT-001',
    ]);

    // 5. Vérifier que le compte non vérifié est refusé.
    try {
        app(AssignMerchantAccountToPayment::class)
            ->execute($payment->id);

        $this->fail(
            'Un compte marchand non vérifié aurait dû être refusé.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'merchant_account',
            $exception->errors()
        );
    }

    $this->assertNull(
        $payment->fresh()->store_payment_account_id
    );

    // 6. Simuler une activation autorisée POUR LE TEST.
    // Ceci n'est pas une véritable vérification du prestataire.
    $account->forceFill([
        'status' => 'ACTIVE',
        'verified_at' => now(),
    ])->save();

    // 7. Associer le compte désormais actif.
    $updatedPayment = app(
        AssignMerchantAccountToPayment::class
    )->execute($payment->id);

    // 8. Vérifier le bénéficiaire.
    $this->assertSame(
        $account->id,
        $updatedPayment->store_payment_account_id
    );

    $this->assertSame(
        $store->id,
        $updatedPayment->storePaymentAccount->store_id
    );

    // Le paiement reste en attente.
    $this->assertSame(
        'PENDING',
        $updatedPayment->status
    );
}


public function test_payment_cannot_be_assigned_to_another_stores_merchant_account(): void
{
    // 1. Créer deux boutiques.
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'alpha-cross-merchant',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'beta-cross-merchant',
        'status' => 'ACTIVE',
    ]);

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    // 2. Compte marchand actif de la boutique Alpha.
    app(TenantContext::class)->setFromUser($userA);

    $accountA = StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-ALPHA-CROSS',
    ]);

    $accountA->forceFill([
        'status' => 'ACTIVE',
        'verified_at' => now(),
    ])->save();

    // 3. Compte marchand actif de la boutique Beta.
    app(TenantContext::class)->setFromUser($userB);

    $accountB = StorePaymentAccount::create([
        'method' => 'MTN_MOMO',
        'gateway' => 'TEST_GATEWAY',
        'merchant_account_id' => 'MERCHANT-BETA-CROSS',
    ]);

    $accountB->forceFill([
        'status' => 'ACTIVE',
        'verified_at' => now(),
    ])->save();

    // 4. Créer une commande dans la boutique Alpha.
    app(TenantContext::class)->setFromUser($userA);
    $this->actingAs($userA);

    $customer = Customer::create([
        'name' => 'Client Alpha',
        'phone' => '+237690000001',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-CROSS-MERCHANT-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Alpha',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '10000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-CROSS-MERCHANT-001',
    ]);

    // 5. Simuler une mauvaise association préexistante.
    $payment->store_payment_account_id = $accountB->id;
    $payment->save();

    // 6. Notre action doit refuser ce bénéficiaire.
    try {
        app(AssignMerchantAccountToPayment::class)
            ->execute($payment->id);

        $this->fail(
            'Un paiement ne doit pas utiliser le compte marchand d’une autre boutique.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'merchant_account',
            $exception->errors()
        );
    }

    // 7. Vérifier que le compte n'a pas été remplacé.
    $this->assertEquals(
        $accountB->id,
        $payment->fresh()->store_payment_account_id
    );

    // La commande reste impayée.
    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );

    $this->assertSame(
        'PENDING',
        $payment->fresh()->status
    );
}


}
