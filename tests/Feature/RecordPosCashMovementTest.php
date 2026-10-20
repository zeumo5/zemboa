<?php

namespace Tests\Feature;

use App\Actions\Pos\CalculatePosCashBalance;
use App\Actions\Pos\ClosePosSession;
use App\Actions\Pos\OpenPosSession;
use App\Actions\Pos\RecordPosCashMovement;
use App\Models\Permission;
use App\Models\PosCashMovement;
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

class RecordPosCashMovementTest extends TestCase
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
        string $roleCode = 'STORE_OWNER'
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', $roleCode)->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function openSession(
        Store $store,
        User $actor
    ): PosSession {
        app(TenantContext::class)->setFromStore($store);
        $this->actingAs($actor);

        $register = PosRegister::create([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ])->refresh();

        return app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $actor
        );
    }

    private function record(
        Store $store,
        PosSession $session,
        User $actor,
        string $type = 'CASH_IN',
        string $amount = '2000.00',
        string $reason = 'Ajout de monnaie',
        string $reference = 'MOV-TEST-0001'
    ): PosCashMovement {
        app(TenantContext::class)->setFromStore($store);
        $this->actingAs($actor);

        return app(RecordPosCashMovement::class)->execute(
            sessionId: $session->id,
            type: $type,
            amount: $amount,
            reason: $reason,
            reference: $reference,
            actor: $actor
        );
    }

    public function test_owner_can_record_cash_in_and_out(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        $cashIn = $this->record(
            $store,
            $session,
            $owner
        );

        $cashOut = $this->record(
            $store,
            $session,
            $owner,
            'CASH_OUT',
            '500.00',
            'Transfert au coffre',
            'MOV-TEST-0002'
        );

        $this->assertSame('CASH_IN', $cashIn->type);
        $this->assertSame('2000.00', $cashIn->amount);
        $this->assertSame('CASH_OUT', $cashOut->type);
        $this->assertSame('500.00', $cashOut->amount);

        $this->assertDatabaseHas('pos_cash_movements', [
            'id' => $cashIn->id,
            'store_id' => $store->id,
            'pos_session_id' => $session->id,
            'recorded_by_user_id' => $owner->id,
            'reference' => 'MOV-TEST-0001',
        ]);

        $this->assertDatabaseCount('pos_cash_movements', 2);

        // Un mouvement manuel n'est pas un paiement.
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manager_can_record_movement(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);

        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $session = $this->openSession($store, $owner);

        $movement = $this->record(
            $store,
            $session,
            $manager
        );

        $this->assertSame(
            $manager->id,
            $movement->recorded_by_user_id
        );

        $this->assertSame(
            $session->id,
            $movement->pos_session_id
        );
    }

    public function test_cashier_cannot_record_manual_movement(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $cashier = $this->createActor($store, 'CASHIER');

        $session = $this->openSession($store, $owner);

        // Même si la permission est attribuée par erreur.
        $role = Role::where('code', 'CASHIER')->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.cash_movements.manage'
        )->firstOrFail();

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->record($store, $session, $cashier);
    }

    public function test_delivery_role_blocks_movement_even_with_owner_role(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        $mixedUser = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $deliveryRole = Role::where(
            'code',
            'DELIVERY_AGENT'
        )->firstOrFail();

        $mixedUser->roles()->attach($deliveryRole->id);

        $this->expectException(AuthorizationException::class);

        $this->record($store, $session, $mixedUser);
    }

    public function test_closed_session_rejects_movement(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        app(ClosePosSession::class)->execute(
            $session->id,
            '5000.00',
            $owner
        );

        $this->expectException(ValidationException::class);

        $this->record($store, $session, $owner);
    }

    public function test_other_store_session_is_inaccessible(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createActor($storeA);
        $ownerB = $this->createActor($storeB);

        $sessionB = $this->openSession($storeB, $ownerB);

        $this->expectException(ModelNotFoundException::class);

        $this->record($storeA, $sessionB, $ownerA);
    }

    public function test_duplicate_reference_is_rejected(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        $this->record($store, $session, $owner);

        try {
            $this->record($store, $session, $owner);

            $this->fail('La référence aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'reference',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('pos_cash_movements', 1);
    }

    public function test_invalid_movement_data_is_rejected(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        $cases = [
            ['INVALID', '100.00', 'Motif valide', 'MOV-ERR-0001', 'type'],
            ['CASH_IN', '0.00', 'Motif valide', 'MOV-ERR-0002', 'amount'],
            ['CASH_IN', '-5.00', 'Motif valide', 'MOV-ERR-0003', 'amount'],
            ['CASH_IN', '100.00', '   ', 'MOV-ERR-0004', 'reason'],
            ['CASH_IN', '100.00', 'Motif valide', 'abc', 'reference'],
        ];

        foreach (
            $cases as [
                $type,
                $amount,
                $reason,
                $reference,
                $expectedField
            ]
        ) {
            try {
                $this->record(
                    $store,
                    $session,
                    $owner,
                    $type,
                    $amount,
                    $reason,
                    $reference
                );

                $this->fail(
                    'La validation aurait dû refuser : '
                        . $expectedField
                );
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(
                    $expectedField,
                    $exception->errors()
                );
            }
        }

        $this->assertDatabaseCount('pos_cash_movements', 0);
    }

    public function test_suspended_store_cannot_record_movement(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        $store->status = 'INACTIVE';
        $store->save();

        $this->expectException(AuthorizationException::class);

        $this->record($store, $session, $owner);
    }

    public function test_owner_without_permission_is_denied(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        $role = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.cash_movements.manage'
        )->firstOrFail();

        $role->permissions()->detach($permission->id);

        $this->expectException(AuthorizationException::class);

        $this->record($store, $session, $owner);
    }

    public function test_cash_out_exceeding_available_balance_is_rejected(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        // Fonds initial : 5 000 FCFA.
        // Tentative de retrait : 8 000 FCFA.
        try {
            $this->record(
                $store,
                $session,
                $owner,
                'CASH_OUT',
                '8000.00',
                'Retrait excessif',
                'MOV-LIMIT-0001'
            );

            $this->fail(
                'Un retrait supérieur au solde doit être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'amount',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );

        $balance = app(CalculatePosCashBalance::class)
            ->execute($session);

        $this->assertSame(
            '5000.00',
            $balance['expected_cash']
        );
    }

    public function test_cash_out_equal_to_available_balance_is_allowed(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        // Retirer exactement le fonds disponible.
        $movement = $this->record(
            $store,
            $session,
            $owner,
            'CASH_OUT',
            '5000.00',
            'Transfert intégral au coffre',
            'MOV-LIMIT-0002'
        );

        $this->assertSame(
            'CASH_OUT',
            $movement->type
        );

        $balance = app(CalculatePosCashBalance::class)
            ->execute($session);

        $this->assertSame(
            '0.00',
            $balance['expected_cash']
        );

        $this->assertDatabaseCount(
            'pos_cash_movements',
            1
        );
    }

    public function test_cash_in_increases_available_balance_for_withdrawal(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        // Fonds initial : 5 000 FCFA.
        // Ajout : 2 000 FCFA.
        $this->record(
            $store,
            $session,
            $owner,
            'CASH_IN',
            '2000.00',
            'Approvisionnement en monnaie',
            'MOV-LIMIT-0003'
        );

        // Retrait de 6 500 FCFA.
        $this->record(
            $store,
            $session,
            $owner,
            'CASH_OUT',
            '6500.00',
            'Transfert au coffre',
            'MOV-LIMIT-0004'
        );

        $balance = app(CalculatePosCashBalance::class)
            ->execute($session);

        $this->assertSame(
            '5000.00',
            $balance['opening_cash']
        );

        $this->assertSame(
            '2000.00',
            $balance['cash_in']
        );

        $this->assertSame(
            '6500.00',
            $balance['cash_out']
        );

        $this->assertSame(
            '500.00',
            $balance['expected_cash']
        );

        $this->assertDatabaseCount(
            'pos_cash_movements',
            2
        );
    }

    public function test_cash_in_exceeding_maximum_balance_is_rejected(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        // Fonds initial : 5 000 FCFA.
        // Cette entrée dépasserait la limite DECIMAL(12,2).
        try {
            $this->record(
                $store,
                $session,
                $owner,
                'CASH_IN',
                '9999999999.99',
                'Test de dépassement du plafond',
                'MOV-MAX-0001'
            );

            $this->fail(
                'Un dépassement du plafond doit être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'amount',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('pos_cash_movements', 0);

        $balance = app(CalculatePosCashBalance::class)
            ->execute($session);

        $this->assertSame(
            '5000.00',
            $balance['expected_cash']
        );
    }

    public function test_maximum_balance_is_allowed_but_no_more(): void
    {
        $store = $this->createStore();
        $owner = $this->createActor($store);
        $session = $this->openSession($store, $owner);

        // Fonds initial : 5 000 FCFA.
        // Ajout jusqu'au plafond exact.
        $this->record(
            $store,
            $session,
            $owner,
            'CASH_IN',
            '9999994999.99',
            'Approvisionnement limite',
            'MOV-MAX-0002'
        );

        $balance = app(CalculatePosCashBalance::class)
            ->execute($session);

        $this->assertSame(
            '9999999999.99',
            $balance['expected_cash']
        );

        // Un centime supplémentaire est interdit.
        try {
            $this->record(
                $store,
                $session,
                $owner,
                'CASH_IN',
                '0.01',
                'Dépassement supplémentaire',
                'MOV-MAX-0003'
            );

            $this->fail(
                'Le plafond financier ne doit pas être dépassé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'amount',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('pos_cash_movements', 1);
    }
}
