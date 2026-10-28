<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

class StoreManagerRbacAuditTest extends TestCase
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

    public function test_manager_has_operational_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $expected = [
            'categories.view',
            'categories.create',
            'categories.update',
            'products.view',
            'products.create',
            'products.update',
            'inventory.view',
            'inventory.manage',
            'orders.view',
            'orders.update',
            'customers.view',
            'deliveries.view',
            'deliveries.update',
            'pos.registers.view',
            'pos.registers.manage',
            'pos.sessions.open',
            'pos.sessions.close',
            'pos.sales.create',
            'store.document_settings.view',
            'store.document_settings.update',
        ];

        sort($expected);

        $actual = $manager->permissions()
            ->pluck('code')
            ->all();

        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_manager_has_no_sensitive_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $forbidden = [
            'pos.cash_movements.manage',
            'payments.confirm',
            'payments.view',
            'refunds.manage',
            'refunds.view',
            'settings.update',
            'store.update',
            'payments.reconciliation.resolve',
            'inventory.nonexistent_permission',
        ];

        foreach ($forbidden as $permission) {
            $this->assertFalse(
                $manager->permissions()
                    ->where('code', $permission)
                    ->exists(),
                "Permission interdite : {$permission}"
            );
        }
    }

    public function test_manager_cannot_impersonate_owner_or_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::create([
            'name' => 'Boutique Audit Manager',
            'slug' => 'audit-manager',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $user->roles()->attach($role->id);

        $this->assertTrue(
            $user->hasRole('STORE_MANAGER')
        );

        $this->assertTrue(
            $user->hasPermission('inventory.manage')
        );

        $this->assertFalse(
            $user->hasRole('STORE_OWNER')
        );

        $this->assertFalse(
            $user->hasRole('SUPER_ADMIN')
        );

        $this->assertFalse(
            $user->hasPermission('payments.confirm')
        );

        $this->assertFalse(
            $user->hasPermission('refunds.manage')
        );
    }

    public function test_manager_can_manage_stock_in_own_store(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::create([
            'name' => 'Boutique Manager Stock',
            'slug' => 'manager-stock',
            'status' => 'ACTIVE',
        ]);

        $manager = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $manager->roles()->attach($role->id);

        // Objet utilisé uniquement pour tester la politique.
        $stockLevel = new StockLevel;
        $stockLevel->store_id = $store->id;

        $this->assertTrue(
            Gate::forUser($manager)->allows('view', $stockLevel)
        );

        $this->assertTrue(
            Gate::forUser($manager)->allows('manage', $stockLevel)
        );
    }

    public function test_cashier_and_delivery_agent_cannot_manage_stock(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::create([
            'name' => 'Boutique Audit Employes',
            'slug' => 'audit-employes-stock',
            'status' => 'ACTIVE',
        ]);

        $stockLevel = new StockLevel;
        $stockLevel->store_id = $store->id;

        foreach (['CASHIER', 'DELIVERY_AGENT'] as $roleCode) {
            $user = User::factory()->create([
                'store_id' => $store->id,
                'status' => 'ACTIVE',
            ]);

            $role = Role::where('code', $roleCode)
                ->firstOrFail();

            $user->roles()->attach($role->id);

            $this->assertFalse(
                Gate::forUser($user)->allows('manage', $stockLevel),
                "Le rôle {$roleCode} ne doit pas gérer les stocks."
            );
        }
    }

    public function test_manager_cannot_manage_another_store_stock(): void
    {
        $this->seed(DatabaseSeeder::class);

        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'audit-alpha-stock',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'audit-beta-stock',
            'status' => 'ACTIVE',
        ]);

        $manager = User::factory()->create([
            'store_id' => $storeA->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $manager->roles()->attach($role->id);

        // Le stock appartient à la boutique B.
        $foreignStock = new StockLevel;
        $foreignStock->store_id = $storeB->id;

        $this->assertFalse(
            Gate::forUser($manager)->allows('view', $foreignStock)
        );

        $this->assertFalse(
            Gate::forUser($manager)->allows('manage', $foreignStock)
        );
    }
}
