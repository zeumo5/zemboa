<?php
namespace Tests\Feature;


use App\Actions\Orders\CreateOrder;
use App\Actions\Pos\ClosePosSession;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\OpenPosSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CreatePosOrderTest extends TestCase
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

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function createStore(string $slug = 'alpha'): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createActor(
        Store $store,
        string $roleCode = 'CASHIER'
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

    private function createRegister(
        Store $store,
        string $code = 'CAISSE-01'
    ): PosRegister {
        app(TenantContext::class)->setFromStore($store);

        return PosRegister::create([
            'name' => 'Caisse ' . $code,
            'code' => $code,
        ])->refresh();
    }

    private function openSession(
        Store $store,
        PosRegister $register,
        User $actor
    ): PosSession {
        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($actor);

        return app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $actor
        );
    }

    private function createSale(
        Store $store,
        PosSession $session,
        User $actor
    ): Order {
        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($actor);

        return app(CreatePosOrder::class)->execute(
            sessionId: $session->id,
            customerName: 'Client comptoir',
            customerPhone: '690000001',
            customerEmail: null,
            items: [
                [
                    'product_variant_id' => 1,
                    'quantity' => 1,
                ],
            ],
            actor: $actor
        );
    }

    public function test_cashier_can_create_order_in_own_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $register,
            $cashier
        );

        // Isoler le moteur de commande pour tester
        // exclusivement l'action POS.
        $mock = Mockery::mock(CreateOrder::class);

        $mock->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function (...$arguments) {
                $this->assertContains(
                    'PICKUP',
                    array_values($arguments)
                );

                $customer = Customer::create([
                    'name' => 'Client comptoir',
                    'phone' => '690000001',
                ]);

                return Order::create([
                    'customer_id' => $customer->id,
                    'order_number' => 'POS-TEST-001',
                    'status' => 'PENDING',
                    'payment_status' => 'UNPAID',
                    'fulfillment_type' => 'PICKUP',
                    'customer_name' => 'Client comptoir',
                    'customer_phone' => '690000001',
                    'subtotal' => '12000.00',
                    'discount_amount' => '0.00',
                    'delivery_fee' => '0.00',
                    'total' => '12000.00',
                ]);
            });

        $this->app->instance(CreateOrder::class, $mock);

        $order = $this->createSale(
            $store,
            $session,
            $cashier
        );

        $this->assertSame('PENDING', $order->status);
        $this->assertSame('UNPAID', $order->payment_status);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $store->id,
            'pos_session_id' => $session->id,
            'fulfillment_type' => 'PICKUP',
        ]);

        $this->assertSame(
            $session->id,
            $order->posSession->id
        );

        // Aucune confirmation de paiement artificielle.
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cashier_cannot_use_another_cashiers_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashierA = $this->createActor($store);
        $cashierB = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $register,
            $cashierA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->createSale($store, $session, $cashierB);
    }

    public function test_closed_session_cannot_create_sale(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $register,
            $cashier
        );

        app(ClosePosSession::class)->execute(
            $session->id,
            '5000.00',
            $cashier
        );

        $this->expectException(
            ValidationException::class
        );

        $this->createSale($store, $session, $cashier);
    }

    public function test_foreign_store_session_is_inaccessible(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);
        $cashierB = $this->createActor($storeB);
        $cashierA = $this->createActor($storeA);

        $sessionB = $this->openSession(
            $storeB,
            $registerB,
            $cashierB
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->createSale(
            $storeA,
            $sessionB,
            $cashierA
        );
    }

    public function test_delivery_agent_cannot_create_sale(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store);
        $agent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        $session = $this->openSession(
            $store,
            $register,
            $cashier
        );

        // Même si la permission est accordée
        // accidentellement au livreur.
        $role = Role::where('code', 'DELIVERY_AGENT')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sales.create'
        )->firstOrFail();

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $this->expectException(
            AuthorizationException::class
        );

        $this->createSale($store, $session, $agent);
    }

    public function test_cashier_without_permission_is_denied(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $register,
            $cashier
        );

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sales.create'
        )->firstOrFail();

        $role->permissions()->detach($permission->id);

        $this->expectException(
            AuthorizationException::class
        );

        $this->createSale($store, $session, $cashier);
    }

    public function test_suspended_store_cannot_create_sale(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $register,
            $cashier
        );

        $store->status = 'INACTIVE';
        $store->save();

        $this->expectException(
            AuthorizationException::class
        );

        $this->createSale($store, $session, $cashier);
    }

    public function test_inactive_register_cannot_create_sale(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $register,
            $cashier
        );

        // Simuler une désactivation administrative.
        $register->status = 'INACTIVE';
        $register->save();

        $this->expectException(
            ValidationException::class
        );

        $this->createSale($store, $session, $cashier);
    }
}
