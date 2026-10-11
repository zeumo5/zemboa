<?php
namespace Tests\Feature;


use App\Actions\Payments\ConfirmVerifiedMobileMoneyPayment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmVerifiedMobileMoneyPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_payment_cannot_be_confirmed_as_mobile_money(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test Confirmation MoMo',
            'slug' => 'boutique-confirmation-momo',
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
            'name' => 'Client Test Confirmation',
            'phone' => '+237690000020',
        ]);

        // 4. Créer une commande de 10 000 FCFA.
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-CONFIRM-MOMO-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Test Confirmation',
            'customer_phone' => '+237690000020',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Créer un paiement CASH en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '10000.00',
            'change_given' => '0.00',
        ]);

        // 6. Tenter de le confirmer avec l'action Mobile Money.
        try {
            app(ConfirmVerifiedMobileMoneyPayment::class)
                ->execute($payment->id);

            $this->fail(
                'Un paiement CASH ne doit pas être confirmé par Mobile Money.'
            );

        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'method',
                $exception->errors()
            );
        }

        // 7. Vérifier qu'aucune confirmation n'a eu lieu.
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

    
public function test_already_confirmed_mobile_money_payment_is_rejected(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Test Double Confirmation',
        'slug' => 'boutique-double-confirmation-momo',
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
        'name' => 'Client Double Confirmation',
        'phone' => '+237690000021',
    ]);

    // 4. Créer une commande de 10 000 FCFA.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-DOUBLE-CONFIRM-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Double Confirmation',
        'customer_phone' => '+237690000021',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 5. Simuler un paiement déjà confirmé.
    // Ceci est une donnée de test uniquement.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '10000.00',
        'method' => 'MTN_MOMO',
        'status' => 'CONFIRMED',
        'reference' => 'ZM-DOUBLE-CONFIRM-001',
        'confirmed_at' => now(),
    ]);

    // 6. Tenter une nouvelle confirmation.
    try {
        app(ConfirmVerifiedMobileMoneyPayment::class)
            ->execute($payment->id);

        $this->fail(
            'Un paiement déjà confirmé doit être refusé.'
        );

    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'status',
            $exception->errors()
        );
    }

    // 7. Vérifier que le paiement reste inchangé.
    $this->assertSame(
        'CONFIRMED',
        $payment->fresh()->status
    );

    $this->assertNotNull(
        $payment->fresh()->confirmed_at
    );

    // Notre action ne doit pas modifier la commande.
    $this->assertSame(
        'UNPAID',
        $order->fresh()->payment_status
    );
}


public function test_pending_mobile_money_confirmation_remains_disabled(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Test Protection Mobile Money',
        'slug' => 'boutique-protection-momo',
        'status' => 'ACTIVE',
    ]);

    // 2. Authentifier un utilisateur.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    // 3. Créer un client.
    $customer = Customer::create([
        'name' => 'Client Protection Mobile Money',
        'phone' => '+237690000022',
    ]);

    // 4. Créer une commande de 10 000 FCFA.
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-PROTECTION-MOMO-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Protection Mobile Money',
        'customer_phone' => '+237690000022',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 5. Créer un paiement Mobile Money en attente.
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '10000.00',
        'method' => 'MTN_MOMO',
        'status' => 'PENDING',
        'reference' => 'ZM-PROTECTION-MOMO-001',
    ]);

    // 6. La confirmation doit rester désactivée.
    try {
        app(ConfirmVerifiedMobileMoneyPayment::class)
            ->execute($payment->id);

        $this->fail(
            'La confirmation Mobile Money aurait dû être bloquée.'
        );

    } catch (\LogicException $exception) {
        $this->assertSame(
            'Confirmation Mobile Money non encore activée.',
            $exception->getMessage()
        );
    }

    // 7. Vérifier qu'aucune donnée n'a changé.
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
