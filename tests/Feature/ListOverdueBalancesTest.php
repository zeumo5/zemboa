<?php

namespace Tests\Feature;


use App\Actions\Orders\ListOverdueBalances;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListOverdueBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_overdue_partial_orders_are_listed(): void
    {
        // 1. Créer la boutique et son utilisateur.
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        // 2. Créer un client.
        $customer = Customer::create([
            'name' => 'Jean Client',
            'phone' => '+237690000001',
        ]);

        // 3. Commande partiellement payée et en retard.
        $overdueOrder = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-OVERDUE-001',
            'status' => 'PENDING',
            'payment_status' => 'PARTIALLY_PAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '10000.00',
            'total' => '10000.00',
            'balance_due_at' => now()->subDay(),
        ]);

        // 4. Commande dont le délai n'est pas dépassé.
        $futureOrder = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-OVERDUE-002',
            'status' => 'PENDING',
            'payment_status' => 'PARTIALLY_PAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '15000.00',
            'total' => '15000.00',
            'balance_due_at' => now()->addDays(3),
        ]);

        // 5. Commande déjà entièrement payée.
        $paidOrder = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-OVERDUE-003',
            'status' => 'PENDING',
            'payment_status' => 'PAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '20000.00',
            'total' => '20000.00',
            'balance_due_at' => now()->subDays(2),
        ]);

        // 6. Exécuter notre Action.
        $orders = app(ListOverdueBalances::class)->execute();

        // 7. Vérifier les résultats.
        $this->assertCount(1, $orders);

        $this->assertTrue(
            $orders->contains('id', $overdueOrder->id)
        );

        $this->assertFalse(
            $orders->contains('id', $futureOrder->id)
        );

        $this->assertFalse(
            $orders->contains('id', $paidOrder->id)
        );

        // 8. Vérifier qu'aucun statut n'a changé.
        $this->assertSame(
            'PARTIALLY_PAID',
            $overdueOrder->fresh()->payment_status
        );

        $this->assertSame(
            'PENDING',
            $overdueOrder->fresh()->status
        );
    }

    public function test_overdue_orders_are_isolated_by_store(): void
    {
        // 1. Créer deux boutiques.
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

        // 2. Créer une commande en retard dans B.
        app(TenantContext::class)->setFromUser($userB);

        $customerB = Customer::create([
            'name' => 'Client Beta',
            'phone' => '+237690000002',
        ]);

        $orderB = Order::create([
            'customer_id' => $customerB->id,
            'order_number' => 'ZM-OVERDUE-B',
            'status' => 'PENDING',
            'payment_status' => 'PARTIALLY_PAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Beta',
            'customer_phone' => '+237690000002',
            'subtotal' => '10000.00',
            'total' => '10000.00',
            'balance_due_at' => now()->subDay(),
        ]);

        // 3. Passer dans la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        // 4. Consulter les commandes en retard de A.
        $orders = app(ListOverdueBalances::class)->execute();

        // La commande de B doit être invisible.
        $this->assertCount(0, $orders);

        $this->assertFalse(
            $orders->contains('id', $orderB->id)
        );

        // 5. Revenir dans B pour vérifier sa commande.
        app(TenantContext::class)->setFromUser($userB);

        $ordersB = app(ListOverdueBalances::class)->execute();

        $this->assertCount(1, $ordersB);

        $this->assertTrue(
            $ordersB->contains('id', $orderB->id)
        );
    }
}
