<?php
namespace Tests\Feature;


use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OrderManagementHttpTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests interdits hors SQLite :memory:.'
            );
        }

        $this->refreshSafeDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createEmployee(
        Store $store,
        string $roleCode
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createOrder(
        Store $store,
        string $reference
    ): Order {
        app(TenantContext::class)->setFromStore($store);

        $customer = Customer::create([
            'name' => 'Client ' . $reference,
            'phone' => '+237690000001',
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-AUDIT-' . $reference,
            'status' => 'PENDING',
            'payment_status' => 'UNPAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);
    }

    // 1. Le propriétaire consulte ses commandes.
    public function test_owner_can_list_own_orders(): void
    {
        $store = $this->createStore('owner-orders');

        $owner = $this->createEmployee(
            $store,
            'STORE_OWNER'
        );

        $order = $this->createOrder($store, 'OWNER');

        $this->actingAs($owner);

        $this->getJson(route('orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id);
    }

    // 2. Le gestionnaire consulte les détails.
    public function test_manager_can_view_own_order(): void
    {
        $store = $this->createStore('manager-orders');

        $manager = $this->createEmployee(
            $store,
            'STORE_MANAGER'
        );

        $order = $this->createOrder($store, 'MANAGER');

        $this->actingAs($manager);

        $this->getJson(route('orders.show', [
            'orderId' => $order->id,
        ]))
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath(
                'order.order_number',
                $order->order_number
            )
            ->assertJsonPath(
                'order.payment_status',
                'UNPAID'
            )
            ->assertJsonStructure([
                'order' => ['items'],
            ])
            ->assertJsonMissingPath('order.payments');
    }

    // 3. Caissière et livreur refusés.
    public function test_cashier_and_agent_cannot_view_orders(): void
    {
        $store = $this->createStore('restricted-orders');

        $order = $this->createOrder($store, 'RESTRICTED');

        foreach (['CASHIER', 'DELIVERY_AGENT'] as $role) {
            $user = $this->createEmployee($store, $role);

            $this->actingAs($user);

            $this->getJson(route('orders.index'))
                ->assertForbidden();

            $this->getJson(route('orders.show', [
                'orderId' => $order->id,
            ]))->assertForbidden();
        }
    }

    // 4. Isolation entre boutiques.
    public function test_store_cannot_view_foreign_orders(): void
    {
        $storeA = $this->createStore('orders-alpha');
        $storeB = $this->createStore('orders-beta');

        $ownerA = $this->createEmployee(
            $storeA,
            'STORE_OWNER'
        );

        $orderA = $this->createOrder($storeA, 'ALPHA');
        $orderB = $this->createOrder($storeB, 'BETA');

        $this->actingAs($ownerA);

        $this->getJson(route('orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderA->id);

        $this->getJson(route('orders.show', [
            'orderId' => $orderB->id,
        ]))->assertNotFound();
    }

    // 5. Un visiteur non connecté est refusé.
    public function test_guest_cannot_access_orders(): void
    {
        $this->getJson(route('orders.index'))
            ->assertUnauthorized();

        $this->getJson(route('orders.show', [
            'orderId' => 999,
        ]))->assertUnauthorized();
    }
}
