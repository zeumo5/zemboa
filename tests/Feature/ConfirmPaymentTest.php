<?php

namespace Tests\Feature;


use App\Models\Role;
use App\Models\Permission;
use App\Actions\Orders\CreateOrder;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\StockReservation;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmPaymentTest extends TestCase
{
    use RefreshDatabase;

    
private function authenticatePaymentCashier(User $user): void
{
    // Créer un rôle de test.
    $role = Role::firstOrCreate(
        ['code' => 'TEST_PAYMENT_CASHIER'],
        ['name' => 'Caissier de test']
    );

    // Réutiliser la permission existante ou la créer
    // dans la base de données isolée des tests.
    $permission = Permission::firstOrCreate(
        ['code' => 'payments.confirm'],
        ['name' => 'Confirmer un paiement']
    );

    // Donner la permission au rôle.
    $role->permissions()->syncWithoutDetaching([
        $permission->id,
    ]);

    // Attribuer le rôle à l'utilisateur.
    $user->roles()->syncWithoutDetaching([
        $role->id,
    ]);

    // Authentifier le véritable caissier.
    $this->actingAs($user);

    // Activer le contexte de sa boutique.
   app(TenantContext::class)->setFromUser($user);
}


    public function test_partial_payment_keeps_stock_reserved(): void
    {
        // 1. Créer la boutique
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->authenticatePaymentCashier($user);
        // 2. Créer le produit
        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Test',
            'slug' => 'produit-test',
            'status' => 'ACTIVE',
        ]);

        // 3. Créer la variante : 5 000 FCFA
        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'TEST-PAY-001',
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 4. Ajouter 10 unités au stock
        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial pour test paiement'
        );

        // 5. Créer une commande de 2 unités
        $order = app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690 000 001',
            customerEmail: 'jean@example.com',
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        );

        // 2 x 5 000 = 10 000 FCFA
        $this->assertSame('10000.00', $order->total);

        // 6. Enregistrer un acompte de 4 000 FCFA
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '4000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '4000.00',
            'change_given' => '0.00',
        ]);

        // 7. Confirmer le paiement
        app(ConfirmPayment::class)->execute(
            $payment->id,
            $user->id
        );

        // 8. Actualiser les données
        $order->refresh();
        $payment->refresh();

        // 9. Vérifier le paiement
        $this->assertSame('CONFIRMED', $payment->status);
        $this->assertNotNull($payment->confirmed_at);

        $this->assertSame('4000.00', $payment->amount);
        $this->assertSame($user->id, $payment->received_by);

        // 10. Vérifier la commande
        $this->assertSame('PARTIALLY_PAID', $order->payment_status);

        // 11. Vérifier que le stock n'a pas diminué
        $stockLevel = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockLevel->physical_quantity);
        $this->assertSame(2, $stockLevel->reserved_quantity);
        $this->assertSame(8, $stockLevel->availableQuantity());

        // 12. Vérifier que la réservation est toujours active
        $reservation = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->firstOrFail();

        $this->assertSame('ACTIVE', $reservation->status);
        $this->assertSame(2, $reservation->quantity);
        $this->assertNull($reservation->converted_at);

        
        // 13. Le client paie le solde de 6 000 FCFA
        $secondPayment = Payment::create([
            'order_id' => $order->id,
            'amount' => '6000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '6000.00',
            'change_given' => '0.00',
        ]);

        // 14. Confirmer le second paiement
        app(ConfirmPayment::class)->execute(
            $secondPayment->id,
            $user->id
        );

        // 15. Actualiser les données
        $order->refresh();
        $secondPayment->refresh();
        $reservation->refresh();
        $stockLevel->refresh();

        // 16. Vérifier la confirmation
        $this->assertSame('CONFIRMED', $secondPayment->status);
        $this->assertSame('PAID', $order->payment_status);

        // 17. Vérifier la conversion du stock
        $this->assertSame(8, $stockLevel->physical_quantity);
        $this->assertSame(0, $stockLevel->reserved_quantity);
        $this->assertSame(8, $stockLevel->availableQuantity());

        // 18. Vérifier la réservation
        $this->assertSame('CONVERTED', $reservation->status);
        $this->assertNotNull($reservation->converted_at);

        // 19. Vérifier le mouvement de vente
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'SALE',
            'quantity' => 2,
        ]);

        
        // 20. Compter les mouvements de vente existants
        $salesBefore = \Illuminate\Support\Facades\DB::table('stock_movements')
            ->where('product_variant_id', $variant->id)
            ->where('type', 'SALE')
            ->count();

        $this->assertSame(1, $salesBefore);

        // 21. Tenter de confirmer une deuxième fois
        try {
            app(ConfirmPayment::class)->execute(
                $secondPayment->id,
                $user->id
            );

            $this->fail(
                'La double confirmation aurait dû être refusée.'
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'status',
                $exception->errors()
            );
        }

        // 22. Vérifier que rien n'a changé
        $order->refresh();
        $secondPayment->refresh();
        $stockLevel->refresh();
        $reservation->refresh();

        $this->assertSame('PAID', $order->payment_status);
        $this->assertSame('CONFIRMED', $secondPayment->status);

        $this->assertSame(8, $stockLevel->physical_quantity);
        $this->assertSame(0, $stockLevel->reserved_quantity);
        $this->assertSame('CONVERTED', $reservation->status);

        // 23. Toujours un seul mouvement de vente
        $salesAfter = \Illuminate\Support\Facades\DB::table('stock_movements')
            ->where('product_variant_id', $variant->id)
            ->where('type', 'SALE')
            ->count();

        $this->assertSame(1, $salesAfter);

    }

    
public function test_payment_exceeding_order_total_is_rejected(): void
{
    // 1. Créer une boutique
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->authenticatePaymentCashier($user);
    // 2. Créer le client
    $customer = \App\Models\Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    // 3. Créer une commande de 10 000 FCFA
    $order = \App\Models\Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-OVERPAY-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 4. Enregistrer un paiement de 12 000 FCFA
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '12000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '12000.00',
        'change_given' => '0.00',
    ]);

    // 5. Tenter de confirmer le paiement
    try {
        app(ConfirmPayment::class)->execute(
            $payment->id,
            $user->id
        );

        $this->fail(
            'Le paiement supérieur au total aurait dû être refusé.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'amount',
            $exception->errors()
        );
    }

    // 6. Vérifier que rien n'a été confirmé
    $order->refresh();
    $payment->refresh();

    $this->assertSame('UNPAID', $order->payment_status);
    $this->assertSame('PENDING', $payment->status);
    $this->assertNull($payment->confirmed_at);
}


public function test_cancelled_order_cannot_receive_payment(): void
{
    // 1. Créer une boutique
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    // 2. Créer un client
    $customer = \App\Models\Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    // 3. Créer une commande déjà annulée
    $order = \App\Models\Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-PAY-CANCELLED',
        'status' => 'CANCELLED',
        'payment_status' => 'UNPAID',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '5000.00',
        'total' => '5000.00',
    ]);

    // 4. Enregistrer un paiement en attente
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '5000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '5000.00',
        'change_given' => '0.00',
    ]);

    // 5. Tenter de confirmer le paiement
    try {
        app(ConfirmPayment::class)->execute(
            $payment->id,
            $user->id
        );

        $this->fail(
            'Une commande annulée ne doit pas recevoir de paiement.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'order',
            $exception->errors()
        );
    }

    // 6. Vérifier qu'aucune confirmation n'a eu lieu
    $order->refresh();
    $payment->refresh();

    $this->assertSame('CANCELLED', $order->status);
    $this->assertSame('UNPAID', $order->payment_status);

    $this->assertSame('PENDING', $payment->status);
    $this->assertNull($payment->confirmed_at);
}


public function test_store_cannot_confirm_payment_from_another_store(): void
{
    // 1. Créer deux boutiques
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    // 2. Créer une commande dans la boutique B
    app(TenantContext::class)->setFromUser($userB);

    $customerB = \App\Models\Customer::create([
        'name' => 'Client Beta',
        'phone' => '+237690000002',
    ]);

    $orderB = \App\Models\Order::create([
        'customer_id' => $customerB->id,
        'order_number' => 'ZM-BETA-PAY-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Beta',
        'customer_phone' => '+237690000002',
        'subtotal' => '5000.00',
        'total' => '5000.00',
    ]);

    // 3. Créer un paiement dans la boutique B
    $paymentB = Payment::create([
        'order_id' => $orderB->id,
        'amount' => '5000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '5000.00',
        'change_given' => '0.00',
    ]);

    // 4. Passer dans la boutique A
    app(TenantContext::class)->setFromUser($userA);

    // 5. Essayer de confirmer le paiement de B
    try {
        app(ConfirmPayment::class)->execute(
            $paymentB->id,
            $userA->id
        );

        $this->fail(
            'Une boutique ne doit pas confirmer le paiement d’une autre boutique.'
        );
    } catch (
        \Illuminate\Database\Eloquent\ModelNotFoundException $exception
    ) {
        $this->assertSame(
            Payment::class,
            $exception->getModel()
        );
    }

    // 6. Revenir dans la boutique B
    app(TenantContext::class)->setFromUser($userB);

    // 7. Vérifier que rien n'a changé
    $paymentB->refresh();
    $orderB->refresh();

    $this->assertSame('PENDING', $paymentB->status);
    $this->assertNull($paymentB->confirmed_at);

    $this->assertSame('UNPAID', $orderB->payment_status);
}


public function test_user_from_another_store_cannot_confirm_payment(): void
{
    // Créer deux boutiques.
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    // Nous travaillons dans la boutique B.
    app(TenantContext::class)->setFromUser($userB);

    $customer = \App\Models\Customer::create([
        'name' => 'Client Beta',
        'phone' => '+237690000002',
    ]);

    $order = \App\Models\Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-CASHIER-TEST',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Beta',
        'customer_phone' => '+237690000002',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '4000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '4000.00',
        'change_given' => '0.00',
    ]);

    // Tentative : utiliser l'identifiant d'un caissier
    // appartenant à la boutique A.
    try {
        app(ConfirmPayment::class)->execute(
            $payment->id,
            $userA->id
        );

        $this->fail(
            'Un utilisateur étranger à la boutique doit être refusé.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'user',
            $exception->errors()
        );
    }

    $payment->refresh();
    $order->refresh();

    $this->assertSame('PENDING', $payment->status);
    $this->assertSame('UNPAID', $order->payment_status);
    $this->assertNull($payment->confirmed_at);
}


public function test_user_without_payment_permission_cannot_confirm(): void
{
    // 1. Créer une boutique
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    // 2. Créer un utilisateur sans rôle ni permission
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    // 3. Créer une commande
    $customer = \App\Models\Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $order = \App\Models\Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-NO-PERMISSION-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 4. Créer un acompte en attente
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '4000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '4000.00',
        'change_given' => '0.00',
    ]);

    // 5. Vérifier que l'utilisateur n'a pas la permission
    $this->assertFalse(
        $user->hasPermission('payments.confirm')
    );

    // 6. Tenter la confirmation
    try {
        app(ConfirmPayment::class)->execute(
            $payment->id,
            $user->id
        );

        $this->fail(
            'Un utilisateur sans permission ne doit pas confirmer un paiement.'
        );
    } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
        // Refus attendu.
    }

    // 7. Vérifier que rien n'a changé
    $payment->refresh();
    $order->refresh();

    $this->assertSame('PENDING', $payment->status);
    $this->assertNull($payment->confirmed_at);
    $this->assertSame('UNPAID', $order->payment_status);
}


public function test_authenticated_user_cannot_confirm_as_another_cashier(): void
{
    // 1. Créer une boutique
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    // 2. Créer deux caissiers dans la même boutique
    $cashierA = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $cashierB = User::factory()->create([
        'store_id' => $store->id,
    ]);

    // Donner la permission aux deux caissiers.
    $this->authenticatePaymentCashier($cashierB);
    $this->authenticatePaymentCashier($cashierA);

    // Le caissier A est désormais connecté.
    $this->assertSame($cashierA->id, auth()->id());

    // 3. Créer un client
    $customer = \App\Models\Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    // 4. Créer une commande
    $order = \App\Models\Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-IMPERSONATION-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'total' => '10000.00',
    ]);

    // 5. Créer un paiement en attente
    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '4000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '4000.00',
        'change_given' => '0.00',
    ]);

    // 6. Le caissier A essaie d'utiliser
    // l'identifiant du caissier B.
    try {
        app(ConfirmPayment::class)->execute(
            $payment->id,
            $cashierB->id
        );

        $this->fail(
            'Un caissier ne doit pas confirmer au nom d’un autre.'
        );
    } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
        // Refus attendu.
    }

    // 7. Vérifier qu'aucun paiement n'a été confirmé
    $payment->refresh();
    $order->refresh();

    $this->assertSame('PENDING', $payment->status);
    $this->assertNull($payment->confirmed_at);
    $this->assertNull($payment->received_by);

    $this->assertSame('UNPAID', $order->payment_status);
}



}
