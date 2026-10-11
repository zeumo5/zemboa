<?php

namespace Tests\Feature;



use App\Models\Store;
use App\Models\User;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Actions\Payments\InitiateMobileMoneyPayment;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InitiateMobileMoneyPaymentTest extends TestCase
{

    use RefreshDatabase;

    public function test_invalid_mobile_money_method_is_rejected(): void
    {
        // CASH ne doit pas être accepté
        // par notre action Mobile Money.
        try {
            app(InitiateMobileMoneyPayment::class)->execute(
                orderId: 1,
                method: 'CASH'
            );

            $this->fail(
                'Le moyen de paiement CASH aurait dû être refusé.'
            );
        } catch (ValidationException $exception) {

            // Vérifier que Laravel signale
            // une erreur sur le champ method.
            $this->assertArrayHasKey(
                'method',
                $exception->errors()
            );
        }
    }


    public function test_mtn_momo_payment_is_created_as_pending(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Mobile Money Test',
            'slug' => 'boutique-momo-test',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer un utilisateur de cette boutique.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // 3. Créer un client.
        $customer = Customer::create([
            'name' => 'Client Mobile Money',
            'phone' => '+237690000001',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-MOMO-TEST-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Mobile Money',
            'customer_phone' => '+237690000001',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Initier le paiement MTN MoMo.
        $payment = app(
            InitiateMobileMoneyPayment::class
        )->execute(
            orderId: $order->id,
            method: 'MTN_MOMO'
        );

        // 6. Vérifier le paiement créé.
        $this->assertSame('MTN_MOMO', $payment->method);
        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('10000.00', $payment->amount);
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame($store->id, $payment->store_id);

        // La référence interne doit être générée.
        $this->assertNotEmpty($payment->reference);

        // Le paiement n'est pas encore confirmé.
        $this->assertNull($payment->confirmed_at);

        // La commande reste impayée.
        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        // Vérifier qu'un seul paiement a été créé.
        $this->assertSame(
            1,
            Payment::query()
                ->where('order_id', $order->id)
                ->count()
        );
    }

    
public function test_duplicate_pending_mobile_money_payment_is_rejected(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Test Double MoMo',
        'slug' => 'boutique-double-momo',
        'status' => 'ACTIVE',
    ]);

    // 2. Authentifier un utilisateur de cette boutique.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 3. Créer un client.
    $customer = Customer::create([
        'name' => 'Client Double MoMo',
        'phone' => '+237690000002',
    ]);

    // 4. Créer une commande de 10 000 FCFA.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-DOUBLE-MOMO-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Double MoMo',
        'customer_phone' => '+237690000002',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 5. Première demande : autorisée.
    $firstPayment = app(
        InitiateMobileMoneyPayment::class
    )->execute(
        orderId: $order->id,
        method: 'MTN_MOMO'
    );

    $this->assertSame('PENDING', $firstPayment->status);

    // 6. Deuxième demande : elle doit être refusée.
    try {
        app(InitiateMobileMoneyPayment::class)->execute(
            orderId: $order->id,
            method: 'MTN_MOMO'
        );

        $this->fail(
            'Une deuxième demande Mobile Money aurait dû être refusée.'
        );

    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'payment',
            $exception->errors()
        );
    }

    // 7. Vérifier qu'un seul paiement existe.
    $this->assertSame(
        1,
        Payment::query()
            ->where('order_id', $order->id)
            ->count()
    );

    // 8. La commande reste impayée.
    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );
}


public function test_orange_money_payment_is_created_as_pending(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Test Orange Money',
        'slug' => 'boutique-orange-money-test',
        'status' => 'ACTIVE',
    ]);

    // 2. Authentifier un utilisateur.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 3. Créer le client.
    $customer = Customer::create([
        'name' => 'Client Orange Money',
        'phone' => '+237690000003',
    ]);

    // 4. Créer une commande de 15 000 FCFA.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-ORANGE-TEST-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Orange Money',
        'customer_phone' => '+237690000003',
        'subtotal' => '15000.00',
        'total' => '15000.00',
    ]);

    // 5. Initier la demande Orange Money.
    $payment = app(
        InitiateMobileMoneyPayment::class
    )->execute(
        orderId: $order->id,
        method: 'ORANGE_MONEY'
    );

    // 6. Vérifier le paiement.
    $this->assertSame('ORANGE_MONEY', $payment->method);
    $this->assertSame('PENDING', $payment->status);
    $this->assertSame('15000.00', $payment->amount);

    $this->assertSame($order->id, $payment->order_id);
    $this->assertSame($store->id, $payment->store_id);

    $this->assertNotEmpty($payment->reference);
    $this->assertNull($payment->confirmed_at);

    // 7. La commande doit rester impayée.
    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );

    // 8. Une seule demande enregistrée.
    $this->assertSame(
        1,
        Payment::query()
            ->where('order_id', $order->id)
            ->count()
    );
}


public function test_failed_mobile_money_payment_allows_retry(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Test Retry MoMo',
        'slug' => 'boutique-retry-momo',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 2. Créer un client.
    $customer = Customer::create([
        'name' => 'Client Retry MoMo',
        'phone' => '+237690000004',
    ]);

    // 3. Créer une commande.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-RETRY-MOMO-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Retry MoMo',
        'customer_phone' => '+237690000004',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 4. Première tentative Mobile Money.
    $firstPayment = app(
        InitiateMobileMoneyPayment::class
    )->execute(
        orderId: $order->id,
        method: 'MTN_MOMO'
    );

    $this->assertSame('PENDING', $firstPayment->status);

    // 5. Simuler un échec confirmé par le prestataire.
    // Ceci est une simulation réservée au test.
    $firstPayment->status = 'FAILED';
    $firstPayment->save();

    // 6. Le client peut effectuer une nouvelle tentative.
    $secondPayment = app(
        InitiateMobileMoneyPayment::class
    )->execute(
        orderId: $order->id,
        method: 'MTN_MOMO'
    );

    // 7. Vérifier les deux paiements.
    $this->assertSame('FAILED', $firstPayment->fresh()->status);
    $this->assertSame('PENDING', $secondPayment->status);

    $this->assertNotEquals(
        $firstPayment->id,
        $secondPayment->id
    );

    $this->assertSame(
        2,
        Payment::query()
            ->where('order_id', $order->id)
            ->count()
    );

    // La commande reste impayée.
    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );
}

public function test_cancelled_mobile_money_payment_allows_retry(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Test Retry MoMo',
        'slug' => 'boutique-retry-momo',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 2. Créer un client.
    $customer = Customer::create([
        'name' => 'Client Retry MoMo',
        'phone' => '+237690000004',
    ]);

    // 3. Créer une commande.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-RETRY-MOMO-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Retry MoMo',
        'customer_phone' => '+237690000004',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 4. Première tentative Mobile Money.
    $firstPayment = app(
        InitiateMobileMoneyPayment::class
    )->execute(
        orderId: $order->id,
        method: 'ORANGE_MONEY'
    );

    $this->assertSame('PENDING', $firstPayment->status);

    // 5. Simuler un échec confirmé par le prestataire.
    // Ceci est une simulation réservée au test.
    $firstPayment->status = 'CANCELLED';
    $firstPayment->save();

    // 6. Le client peut effectuer une nouvelle tentative.
    $secondPayment = app(
        InitiateMobileMoneyPayment::class
    )->execute(
        orderId: $order->id,
        method: 'ORANGE_MONEY'
    );

    // 7. Vérifier les deux paiements.
    $this->assertSame('CANCELLED', $firstPayment->fresh()->status);
    $this->assertSame('PENDING', $secondPayment->status);

    $this->assertNotEquals(
        $firstPayment->id,
        $secondPayment->id
    );

    $this->assertSame(
        2,
        Payment::query()
            ->where('order_id', $order->id)
            ->count()
    );

    // La commande reste impayée.
    $this->assertSame(
    'CANCELLED',
    $firstPayment->fresh()->status
);
}


}


