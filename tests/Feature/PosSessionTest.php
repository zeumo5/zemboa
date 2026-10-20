<?php
namespace Tests\Feature;


use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PosSessionTest extends TestCase
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

    private function createRegister(
        Store $store,
        string $code
    ): PosRegister {
        $this->useStore($store);

        return PosRegister::create([
            'name' => 'Caisse ' . $code,
            'code' => $code,
        ])->refresh();
    }

    private function createUser(Store $store): User
    {
        return User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);
    }

    private function openSession(
        Store $store,
        PosRegister $register,
        User $user
    ): PosSession {
        $this->useStore($store);

        $session = new PosSession([
            'pos_register_id' => $register->id,
            'opening_cash' => '5000.00',
        ]);

        // Le responsable est défini côté serveur.
        $session->opened_by_user_id = $user->id;

        $session->save();

        return $session->refresh();
    }

    public function test_session_belongs_to_current_store(): void
    {
        $store = $this->createStore('alpha');
        $register = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $user = $this->createUser($store);

        $session = $this->openSession(
            $store,
            $register,
            $user
        );

        $this->assertSame(
            $store->id,
            $session->store_id
        );

        $this->assertSame(
            $register->id,
            $session->pos_register_id
        );

        $this->assertSame(
            $user->id,
            $session->opened_by_user_id
        );

        $this->assertSame('OPEN', $session->status);
        $this->assertSame(1, $session->open_slot);
        $this->assertSame(
            '5000.00',
            $session->opening_cash
        );
        $this->assertNotNull($session->opened_at);
    }

    public function test_same_register_cannot_have_two_open_sessions(): void
    {
        $store = $this->createStore('alpha');
        $register = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $user = $this->createUser($store);

        $this->openSession(
            $store,
            $register,
            $user
        );

        // La contrainte SQL doit empêcher
        // une deuxième session ouverte.
        $this->expectException(QueryException::class);

        $this->openSession(
            $store,
            $register,
            $user
        );
    }

    public function test_closed_session_allows_new_session(): void
    {
        $store = $this->createStore('alpha');
        $register = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $user = $this->createUser($store);

        $first = $this->openSession(
            $store,
            $register,
            $user
        );

        // Simuler la clôture pour tester
        // uniquement la structure de la table.
        $first->status = 'CLOSED';
        $first->open_slot = null;
        $first->closed_by_user_id = $user->id;
        $first->closed_at = now();
        $first->save();

        $second = $this->openSession(
            $store,
            $register,
            $user
        );

        $this->assertNotSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            'OPEN',
            $second->status
        );

        $this->assertDatabaseCount(
            'pos_sessions',
            2
        );
    }

    public function test_different_registers_can_open_simultaneously(): void
    {
        $store = $this->createStore('alpha');

        $registerA = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $registerB = $this->createRegister(
            $store,
            'CAISSE-02'
        );

        $user = $this->createUser($store);

        $sessionA = $this->openSession(
            $store,
            $registerA,
            $user
        );

        $sessionB = $this->openSession(
            $store,
            $registerB,
            $user
        );

        $this->assertSame(
            'OPEN',
            $sessionA->status
        );

        $this->assertSame(
            'OPEN',
            $sessionB->status
        );

        $this->assertDatabaseCount(
            'pos_sessions',
            2
        );
    }

    public function test_sessions_are_isolated_between_stores(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerA = $this->createRegister(
            $storeA,
            'CAISSE-01'
        );

        $registerB = $this->createRegister(
            $storeB,
            'CAISSE-01'
        );

        $userA = $this->createUser($storeA);
        $userB = $this->createUser($storeB);

        $sessionA = $this->openSession(
            $storeA,
            $registerA,
            $userA
        );

        $sessionB = $this->openSession(
            $storeB,
            $registerB,
            $userB
        );

        $this->useStore($storeA);

        $this->assertSame(1, PosSession::count());

        $this->assertNotNull(
            PosSession::find($sessionA->id)
        );

        $this->assertNull(
            PosSession::find($sessionB->id)
        );

        $this->useStore($storeB);

        $this->assertSame(1, PosSession::count());

        $this->assertNull(
            PosSession::find($sessionA->id)
        );
    }

    public function test_session_requires_tenant_context(): void
    {
        $this->expectException(RuntimeException::class);

        $session = new PosSession([
            'pos_register_id' => 1,
            'opening_cash' => '0.00',
        ]);

        $session->opened_by_user_id = 1;
        $session->save();
    }
}
