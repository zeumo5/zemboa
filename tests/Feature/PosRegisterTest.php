<?php

namespace Tests\Feature;


use App\Models\PosRegister;
use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PosRegisterTest extends TestCase
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

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function useStore(Store $store): void
    {
        app(TenantContext::class)->setFromStore($store);
    }

    public function test_register_automatically_belongs_to_current_store(): void
    {
        $store = $this->createStore('alpha');

        $this->useStore($store);


        $register = PosRegister::create([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ]);

        // Récupérer les valeurs réellement enregistrées en base.
        $register->refresh();

        $this->assertSame(
            $store->id,
            $register->store_id
        );

        $this->assertSame('ACTIVE', $register->status);

        $this->assertDatabaseHas('pos_registers', [
            'id' => $register->id,
            'store_id' => $store->id,
            'code' => 'CAISSE-01',
        ]);
    }

    public function test_register_cannot_force_another_store_id(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $this->useStore($storeA);

        $register = new PosRegister([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ]);

        // Tentative d'attribution à une autre boutique.
        $register->store_id = $storeB->id;
        $register->save();

        $this->assertSame(
            $storeA->id,
            $register->store_id
        );

        $this->assertDatabaseHas('pos_registers', [
            'id' => $register->id,
            'store_id' => $storeA->id,
        ]);
    }

    public function test_registers_are_isolated_between_stores(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $this->useStore($storeA);

        $registerA = PosRegister::create([
            'name' => 'Caisse Alpha',
            'code' => 'A-01',
        ]);

        $this->useStore($storeB);

        $registerB = PosRegister::create([
            'name' => 'Caisse Beta',
            'code' => 'B-01',
        ]);

        // Revenir dans la boutique A.
        $this->useStore($storeA);

        $this->assertSame(1, PosRegister::count());

        $this->assertNotNull(
            PosRegister::find($registerA->id)
        );

        $this->assertNull(
            PosRegister::find($registerB->id)
        );

        // La boutique B voit seulement sa caisse.
        $this->useStore($storeB);

        $this->assertSame(1, PosRegister::count());

        $this->assertNull(
            PosRegister::find($registerA->id)
        );
    }

    public function test_same_code_is_forbidden_within_one_store(): void
    {
        $store = $this->createStore('alpha');

        $this->useStore($store);

        PosRegister::create([
            'name' => 'Première caisse',
            'code' => 'CAISSE-01',
        ]);

        $this->expectException(QueryException::class);

        PosRegister::create([
            'name' => 'Deuxième caisse',
            'code' => 'CAISSE-01',
        ]);
    }

    public function test_two_stores_can_use_the_same_register_code(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $this->useStore($storeA);

        $registerA = PosRegister::create([
            'name' => 'Caisse Alpha',
            'code' => 'CAISSE-01',
        ]);

        $this->useStore($storeB);

        $registerB = PosRegister::create([
            'name' => 'Caisse Beta',
            'code' => 'CAISSE-01',
        ]);

        $this->assertNotSame(
            $registerA->id,
            $registerB->id
        );

        $this->assertDatabaseCount('pos_registers', 2);
    }

    public function test_register_creation_requires_tenant_context(): void
    {
        $this->expectException(RuntimeException::class);

        PosRegister::create([
            'name' => 'Caisse interdite',
            'code' => 'CAISSE-01',
        ]);
    }

    public function test_register_query_requires_tenant_context(): void
    {
        $this->expectException(RuntimeException::class);

        PosRegister::query()->count();
    }
}
