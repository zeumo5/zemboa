<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use App\Actions\Pos\ClosePosSession;
use App\Actions\Pos\OpenPosSession;
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
use RuntimeException;
use Tests\TestCase;

class ClosePosSessionTest extends TestCase
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
            'email_verified_at' => now(),
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

    private function open(
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

    private function close(
        Store $store,
        PosSession $session,
        User $actor,
        string $cash = '7000.00'
    ): PosSession {
        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($actor);

        return app(ClosePosSession::class)->execute(
            $session->id,
            $cash,
            $actor
        );
    }

    public function test_cashier_can_close_own_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $closed = $this->close(
            $store,
            $session,
            $cashier
        );

        $this->assertSame('CLOSED', $closed->status);
        $this->assertNull($closed->open_slot);

        $this->assertSame(
            $cashier->id,
            $closed->closed_by_user_id
        );

        $this->assertSame(
            '7000.00',
            $closed->closing_cash_counted
        );

        $this->assertNotNull($closed->closed_at);

        // Pas de calcul financier inventé.
        $this->assertSame(
            '5000.00',
            $closed->closing_cash_expected
        );

        $this->assertSame(
            '2000.00',
            $closed->cash_difference
        );
    }

    public function test_owner_and_manager_can_supervise_closing(): void
    {
        $store = $this->createStore();

        $cashier = $this->createActor($store);
        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );
        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $registerA = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $registerB = $this->createRegister(
            $store,
            'CAISSE-02'
        );

        $sessionA = $this->open(
            $store,
            $registerA,
            $cashier
        );

        $sessionB = $this->open(
            $store,
            $registerB,
            $cashier
        );

        $closedA = $this->close(
            $store,
            $sessionA,
            $owner
        );

        $closedB = $this->close(
            $store,
            $sessionB,
            $manager
        );

        $this->assertSame('CLOSED', $closedA->status);
        $this->assertSame('CLOSED', $closedB->status);

        $this->assertSame(
            $owner->id,
            $closedA->closed_by_user_id
        );

        $this->assertSame(
            $manager->id,
            $closedB->closed_by_user_id
        );
    }

    public function test_cashier_cannot_close_another_cashiers_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashierA = $this->createActor($store);
        $cashierB = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashierA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->close(
            $store,
            $session,
            $cashierB
        );
    }

    public function test_session_cannot_be_closed_twice(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $this->close(
            $store,
            $session,
            $cashier
        );

        $this->expectException(
            ValidationException::class
        );

        $this->close(
            $store,
            $session,
            $cashier
        );
    }

    public function test_cross_store_session_is_inaccessible(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);
        $cashierB = $this->createActor($storeB);
        $cashierA = $this->createActor($storeA);

        $sessionB = $this->open(
            $storeB,
            $registerB,
            $cashierB
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->close(
            $storeA,
            $sessionB,
            $cashierA
        );
    }

    public function test_delivery_agent_cannot_close_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $deliveryAgent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        // Même avec une permission accordée par erreur.
        $role = Role::where('code', 'DELIVERY_AGENT')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sessions.close'
        )->firstOrFail();

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $this->expectException(
            AuthorizationException::class
        );

        $this->close(
            $store,
            $session,
            $deliveryAgent
        );
    }

    public function test_user_without_close_permission_is_denied(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sessions.close'
        )->firstOrFail();

        $role->permissions()->detach($permission->id);

        $this->expectException(
            AuthorizationException::class
        );

        $this->close(
            $store,
            $session,
            $cashier
        );
    }

    public function test_invalid_closing_cash_is_rejected(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $this->expectException(
            ValidationException::class
        );

        $this->close(
            $store,
            $session,
            $cashier,
            '-1000.00'
        );
    }

    public function test_suspended_store_cannot_close_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $store->status = 'INACTIVE';
        $store->save();

        $this->expectException(
            AuthorizationException::class
        );

        $this->close(
            $store,
            $session,
            $cashier
        );
    }

    public function test_register_can_reopen_after_closing(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $first = $this->open(
            $store,
            $register,
            $cashier
        );

        $this->close(
            $store,
            $first,
            $cashier
        );

        $second = $this->open(
            $store,
            $register,
            $cashier
        );

        $this->assertNotSame(
            $first->id,
            $second->id
        );

        $this->assertSame('OPEN', $second->status);
        $this->assertSame(1, $second->open_slot);

        $this->assertDatabaseCount('pos_sessions', 2);
    }

    public function test_cannot_close_on_behalf_of_another_user(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store);
        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        app(TenantContext::class)->setFromStore($store);

        // Le propriétaire est connecté, mais
        // tente de passer l'identité du caissier.
        $this->actingAs($owner);

        $this->expectException(
            AuthorizationException::class
        );

        app(ClosePosSession::class)->execute(
            $session->id,
            '7000.00',
            $cashier
        );
    }

    public function test_closing_calculates_confirmed_cash_and_movements(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        // Préparer les données financières de test.
        // Aucune transaction réelle n'est déclenchée.
        $customerId = DB::table('customers')->insertGetId([
            'store_id' => $store->id,
            'name' => 'Client comptoir',
            'phone' => '690000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'order_number' => 'POS-CLOSE-FINANCE-001',
            'status' => 'PENDING',
            'payment_status' => 'PARTIALLY_PAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client comptoir',
            'customer_phone' => '690000001',
            'subtotal' => '40000.00',
            'total' => '40000.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seul le paiement CASH confirmé
        // doit être ajouté aux espèces.
        foreach (
            [
                ['CASH', 'CONFIRMED', '24000.00'],
                ['MTN_MOMO', 'CONFIRMED', '7000.00'],
                ['CASH', 'FAILED', '1500.00'],
            ] as [$method, $status, $amount]
        ) {
            DB::table('payments')->insert([
                'store_id' => $store->id,
                'order_id' => $orderId,
                'pos_session_id' => $session->id,
                'method' => $method,
                'status' => $status,
                'amount' => $amount,
                'confirmed_at' =>
                $status === 'CONFIRMED' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Entrée et sortie manuelles de la caisse.
        foreach (
            [
                ['CASH_IN', '2000.00', 'MOV-CLOSE-0001'],
                ['CASH_OUT', '500.00', 'MOV-CLOSE-0002'],
            ] as [$type, $amount, $reference]
        ) {
            DB::table('pos_cash_movements')->insert([
                'store_id' => $store->id,
                'pos_session_id' => $session->id,
                'recorded_by_user_id' => $cashier->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => 'Mouvement de test',
                'reference' => $reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Clôturer avec 30 000 FCFA comptés.
        $closed = $this->close(
            $store,
            $session,
            $cashier,
            '30000.00'
        );

        $this->assertSame('CLOSED', $closed->status);

        $this->assertSame(
            '30500.00',
            $closed->closing_cash_expected
        );

        $this->assertSame(
            '30000.00',
            $closed->closing_cash_counted
        );

        $this->assertSame(
            '-500.00',
            $closed->cash_difference
        );

        $this->assertNull($closed->open_slot);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'store_id' => $store->id,
            'status' => 'CLOSED',
            'closing_cash_expected' => '30500.00',
            'closing_cash_counted' => '30000.00',
            'cash_difference' => '-500.00',
        ]);
    }

    public function test_another_registers_movements_do_not_affect_closing(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $registerA = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $registerB = $this->createRegister(
            $store,
            'CAISSE-02'
        );

        $sessionA = $this->open(
            $store,
            $registerA,
            $owner
        );

        $sessionB = $this->open(
            $store,
            $registerB,
            $owner
        );

        // Mouvement de 3 000 FCFA uniquement
        // dans la session de CAISSE-02.
        DB::table('pos_cash_movements')->insert([
            'store_id' => $store->id,
            'pos_session_id' => $sessionB->id,
            'recorded_by_user_id' => $owner->id,
            'type' => 'CASH_IN',
            'amount' => '3000.00',
            'reason' => 'Approvisionnement caisse B',
            'reference' => 'MOV-OTHER-REGISTER-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fermer uniquement CAISSE-01.
        $closed = $this->close(
            $store,
            $sessionA,
            $owner,
            '5000.00'
        );

        // La caisse A conserve son bilan de 5 000 FCFA.
        $this->assertSame(
            '5000.00',
            $closed->closing_cash_expected
        );

        $this->assertSame(
            '0.00',
            $closed->cash_difference
        );

        // CAISSE-02 doit rester ouverte.
        $this->assertSame(
            'OPEN',
            $sessionB->fresh()->status
        );
    }
}
