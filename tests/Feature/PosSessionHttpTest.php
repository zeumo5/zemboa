<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use App\Models\Permission;
use App\Models\PosRegister;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PosSessionHttpTest extends TestCase
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

    private function openUrl(PosRegister $register): string
    {
        return route('pos.sessions.open', [
            'registerId' => $register->id,
        ]);
    }

    private function closeUrl(int $sessionId): string
    {
        return route('pos.sessions.close', [
            'sessionId' => $sessionId,
        ]);
    }

    private function openAs(
        User $actor,
        PosRegister $register,
        string $cash = '5000.00'
    ) {
        $this->actingAs($actor);

        return $this->postJson(
            $this->openUrl($register),
            ['opening_cash' => $cash]
        );
    }

    public function test_guest_cannot_open_or_close(): void
    {
        $this->postJson(
            route('pos.sessions.open', ['registerId' => 1]),
            ['opening_cash' => '5000.00']
        )->assertUnauthorized();

        $this->postJson(
            $this->closeUrl(1),
            ['closing_cash_counted' => '5000.00']
        )->assertUnauthorized();
    }

    public function test_cashier_can_open_and_close_own_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $response = $this->openAs($cashier, $register);

        $response
            ->assertCreated()
            ->assertJsonPath('session.status', 'OPEN')
            ->assertJsonPath(
                'session.opened_by_user_id',
                $cashier->id
            )
            ->assertJsonPath(
                'session.pos_register_id',
                $register->id
            );

        $sessionId = $response->json('session.id');

        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '7000.00',
        ])
            ->assertOk()
            ->assertJsonPath('session.status', 'CLOSED')
            ->assertJsonPath('session.open_slot', null)
            ->assertJsonPath(
                'session.closed_by_user_id',
                $cashier->id
            )
            ->assertJsonPath(
                'session.closing_cash_counted',
                '7000.00'
            );

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'store_id' => $store->id,
            'status' => 'CLOSED',
        ]);

        // Impossible de clôturer deux fois.
        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '7000.00',
        ])->assertUnprocessable();
    }

    public function test_manager_can_supervise_cashier_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store);
        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $sessionId = $this->openAs($cashier, $register)
            ->assertCreated()
            ->json('session.id');

        $this->actingAs($manager);

        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '5000.00',
        ])
            ->assertOk()
            ->assertJsonPath(
                'session.closed_by_user_id',
                $manager->id
            );
    }

    public function test_register_cannot_be_opened_twice(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $this->openAs($cashier, $register)
            ->assertCreated();

        $this->openAs($cashier, $register)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session');

        $this->assertDatabaseCount('pos_sessions', 1);
    }

    public function test_cashier_cannot_close_another_cashiers_session(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashierA = $this->createActor($store);
        $cashierB = $this->createActor($store);

        $sessionId = $this->openAs(
            $cashierA,
            $register
        )->assertCreated()->json('session.id');

        $this->actingAs($cashierB);

        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '5000.00',
        ])->assertForbidden();

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'OPEN',
        ]);
    }

    public function test_other_store_cannot_access_sessions(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);

        $cashierB = $this->createActor($storeB);
        $cashierA = $this->createActor($storeA);

        $sessionId = $this->openAs(
            $cashierB,
            $registerB
        )->assertCreated()->json('session.id');

        $this->openAs($cashierA, $registerB)
            ->assertNotFound();

        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '5000.00',
        ])->assertNotFound();

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'store_id' => $storeB->id,
            'status' => 'OPEN',
        ]);
    }

    public function test_invalid_amounts_and_inactive_register_are_rejected(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $this->actingAs($cashier);

        $this->postJson($this->openUrl($register), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opening_cash');

        $this->postJson($this->openUrl($register), [
            'opening_cash' => '-500.00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opening_cash');

        $register->status = 'INACTIVE';
        $register->save();

        $this->openAs($cashier, $register)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('register');

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_delivery_agent_cannot_use_sessions(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store);
        $agent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        // Même en cas d'attribution accidentelle
        // des permissions POS au rôle livreur.
        $role = Role::where('code', 'DELIVERY_AGENT')
            ->firstOrFail();

        $ids = Permission::whereIn('code', [
            'pos.sessions.open',
            'pos.sessions.close',
        ])->pluck('id')->all();

        $role->permissions()->syncWithoutDetaching($ids);

        $this->openAs($agent, $register)
            ->assertForbidden();

        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '5000.00',
        ])->assertForbidden();
    }

    public function test_user_without_permission_cannot_open(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sessions.open'
        )->firstOrFail();

        $role->permissions()->detach($permission->id);

        $this->openAs($cashier, $register)
            ->assertForbidden();

        $this->assertDatabaseCount('pos_sessions', 0);
    }

    public function test_suspended_store_cannot_use_sessions(): void
    {
        $store = $this->createStore();

        $registerA = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $registerB = $this->createRegister(
            $store,
            'CAISSE-02'
        );

        $cashier = $this->createActor($store);

        $sessionId = $this->openAs(
            $cashier,
            $registerA
        )->assertCreated()->json('session.id');

        $store->status = 'INACTIVE';
        $store->save();

        $this->openAs($cashier, $registerB)
            ->assertForbidden();

        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '5000.00',
        ])->assertForbidden();

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'OPEN',
        ]);
    }
    private function balanceUrl(int $sessionId): string
    {
        return route('pos.sessions.balance', [
            'sessionId' => $sessionId,
        ]);
    }

    public function test_guest_cannot_view_pos_balance(): void
    {
        $this->getJson(
            $this->balanceUrl(1)
        )->assertUnauthorized();
    }

    public function test_cashier_can_view_open_session_balance(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $sessionId = $this->openAs($cashier, $register)
            ->assertCreated()
            ->json('session.id');

        $this->getJson($this->balanceUrl($sessionId))
            ->assertOk()
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('status', 'OPEN')
            ->assertJsonPath(
                'balance.opening_cash',
                '5000.00'
            )
            ->assertJsonPath(
                'balance.confirmed_cash_payments',
                '0.00'
            )
            ->assertJsonPath(
                'balance.expected_cash',
                '5000.00'
            )
            ->assertJsonPath('closing', null);
    }

    public function test_closed_session_returns_saved_financial_snapshot(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $sessionId = $this->openAs($cashier, $register)
            ->assertCreated()
            ->json('session.id');

        // Fonds initial : 5 000 FCFA.
        // Espèces comptées : 7 000 FCFA.
        $this->postJson($this->closeUrl($sessionId), [
            'closing_cash_counted' => '7000.00',
        ])->assertOk();

        $this->getJson($this->balanceUrl($sessionId))
            ->assertOk()
            ->assertJsonPath('status', 'CLOSED')
            ->assertJsonPath('balance', null)
            ->assertJsonPath(
                'closing.expected_cash',
                '5000.00'
            )
            ->assertJsonPath(
                'closing.counted_cash',
                '7000.00'
            )
            ->assertJsonPath(
                'closing.cash_difference',
                '2000.00'
            )
            ->assertJsonPath(
                'closing.closed_by_user_id',
                $cashier->id
            );
    }

    public function test_cashier_cannot_view_another_cashiers_balance(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashierA = $this->createActor($store);
        $cashierB = $this->createActor($store);

        $sessionId = $this->openAs(
            $cashierA,
            $register
        )->assertCreated()->json('session.id');

        $this->actingAs($cashierB);

        $this->getJson(
            $this->balanceUrl($sessionId)
        )->assertForbidden();
    }

    public function test_other_store_cannot_view_session_balance(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);

        $cashierB = $this->createActor($storeB);
        $cashierA = $this->createActor($storeA);

        $sessionId = $this->openAs(
            $cashierB,
            $registerB
        )->assertCreated()->json('session.id');

        // Un utilisateur de la boutique A
        // tente de consulter la session de B.
        $this->actingAs($cashierA);

        $this->getJson(
            $this->balanceUrl($sessionId)
        )->assertNotFound();
    }

    public function test_owner_and_manager_can_supervise_balance(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        foreach ([$owner, $manager] as $supervisor) {
            $this->actingAs($supervisor);

            $this->getJson($this->balanceUrl($sessionId))
                ->assertOk()
                ->assertJsonPath(
                    'balance.expected_cash',
                    '5000.00'
                );
        }
    }

    public function test_delivery_agent_cannot_view_pos_balance(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store);

        $agent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        $this->actingAs($agent);

        $this->getJson(
            $this->balanceUrl($sessionId)
        )->assertForbidden();
    }

    public function test_suspended_store_cannot_view_balance(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        // Suspendre la boutique.
        $store->status = 'INACTIVE';
        $store->save();

        $this->getJson(
            $this->balanceUrl($sessionId)
        )->assertForbidden();
    }
    private function cashMovementUrl(int $sessionId): string
    {
        return route('pos.cash-movements.store', [
            'sessionId' => $sessionId,
        ]);
    }

    public function test_owner_can_record_cash_in_via_http(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '2000.00',
                'reason' => 'Approvisionnement en monnaie',
                'reference' => 'MOV-HTTP-0001',
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'movement.type',
                'CASH_IN'
            )
            ->assertJsonPath(
                'movement.amount',
                '2000.00'
            );

        $this->assertDatabaseHas('pos_cash_movements', [
            'store_id' => $store->id,
            'pos_session_id' => $sessionId,
            'recorded_by_user_id' => $owner->id,
            'type' => 'CASH_IN',
            'amount' => '2000.00',
            'reference' => 'MOV-HTTP-0001',
        ]);

        $this->getJson($this->balanceUrl($sessionId))
            ->assertOk()
            ->assertJsonPath(
                'balance.expected_cash',
                '7000.00'
            );
    }

    public function test_cashier_cannot_record_manual_cash_movement_via_http(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor(
            $store,
            'CASHIER'
        );

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_OUT',
                'amount' => '1000.00',
                'reason' => 'Retrait non autorisé',
                'reference' => 'MOV-HTTP-0002',
            ]
        )->assertForbidden();

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );
    }

    public function test_http_cash_out_exceeding_balance_is_rejected(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // La session démarre avec 5 000 FCFA.
        // Un retrait de 8 000 FCFA doit échouer.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_OUT',
                'amount' => '8000.00',
                'reason' => 'Retrait excessif',
                'reference' => 'MOV-HTTP-0003',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );

        $this->getJson($this->balanceUrl($sessionId))
            ->assertOk()
            ->assertJsonPath(
                'balance.expected_cash',
                '5000.00'
            );
    }

    public function test_duplicate_cash_movement_reference_is_rejected_via_http(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $payload = [
            'type' => 'CASH_IN',
            'amount' => '1000.00',
            'reason' => 'Approvisionnement',
            'reference' => 'MOV-HTTP-0004',
        ];

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            $payload
        )->assertCreated();

        // Envoyer une deuxième fois le même mouvement.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            $payload
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference']);

        // Il ne doit exister qu'un seul mouvement.
        $this->assertDatabaseCount(
            'pos_cash_movements',
            1
        );
    }

    public function test_guest_cannot_record_pos_cash_movement(): void
    {
        $this->postJson(
            $this->cashMovementUrl(1),
            [
                'type' => 'CASH_IN',
                'amount' => '1000.00',
                'reason' => 'Approvisionnement',
                'reference' => 'MOV-GUEST-0001',
            ]
        )->assertUnauthorized();

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );
    }

    public function test_closed_pos_session_rejects_cash_movement_via_http(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // Clôturer la session avant le mouvement.
        $this->postJson(
            $this->closeUrl($sessionId),
            [
                'closing_cash_counted' => '5000.00',
            ]
        )->assertOk();

        // Aucun mouvement ne doit être accepté
        // après la clôture.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '2000.00',
                'reason' => 'Opération après clôture',
                'reference' => 'MOV-CLOSED-0001',
            ]
        )->assertUnprocessable();

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'CLOSED',
            'closing_cash_expected' => '5000.00',
        ]);
    }

    public function test_other_store_cannot_record_cash_movement(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);

        $ownerB = $this->createActor(
            $storeB,
            'STORE_OWNER'
        );

        $ownerA = $this->createActor(
            $storeA,
            'STORE_OWNER'
        );

        // Session appartenant à la boutique B.
        $sessionId = $this->openAs(
            $ownerB,
            $registerB
        )->assertCreated()->json('session.id');

        // Le propriétaire A tente d'utiliser
        // la session de la boutique B.
        $this->actingAs($ownerA);

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '3000.00',
                'reason' => 'Tentative interboutiques',
                'reference' => 'MOV-TENANT-0001',
            ]
        )->assertNotFound();

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );
    }

    public function test_invalid_cash_movement_payload_is_rejected(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'INVALID',
                'amount' => '-1500.00',
                'reason' => '',
                'reference' => 'short',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'type',
                'amount',
                'reason',
                'reference',
            ]);

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );
    }
    private function cashMovementHistoryUrl(int $sessionId): string
    {
        return route('pos.cash-movements.index', [
            'sessionId' => $sessionId,
        ]);
    }

    public function test_guest_cannot_view_cash_movement_history(): void
    {
        $this->getJson(
            $this->cashMovementHistoryUrl(1)
        )->assertUnauthorized();
    }

    public function test_owner_can_view_cash_movement_history(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // Enregistrer une entrée d'espèces.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '2000.00',
                'reason' => 'Approvisionnement',
                'reference' => 'MOV-HISTORY-0001',
            ]
        )->assertCreated();

        $this->getJson(
            $this->cashMovementHistoryUrl($sessionId)
        )
            ->assertOk()
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('status', 'OPEN')
            ->assertJsonPath('movements.total', 1)
            ->assertJsonPath(
                'movements.data.0.type',
                'CASH_IN'
            )
            ->assertJsonPath(
                'movements.data.0.amount',
                '2000.00'
            )
            ->assertJsonPath(
                'movements.data.0.reason',
                'Approvisionnement'
            )
            ->assertJsonPath(
                'movements.data.0.reference',
                'MOV-HISTORY-0001'
            )
            ->assertJsonPath(
                'movements.data.0.recorded_by.id',
                $owner->id
            )
            ->assertJsonPath(
                'movements.data.0.recorded_by.name',
                $owner->name
            )
            ->assertJsonStructure([
                'movements' => [
                    'data' => [
                        ['created_at'],
                    ],
                ],
            ]);
    }

    public function test_manager_can_view_cash_movement_history(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->actingAs($manager);

        $this->getJson(
            $this->cashMovementHistoryUrl($sessionId)
        )
            ->assertOk()
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('movements.total', 0);
    }

    public function test_cashier_and_delivery_agent_cannot_view_cash_history(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $cashier = $this->createActor(
            $store,
            'CASHIER'
        );

        $deliveryAgent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        foreach ([$cashier, $deliveryAgent] as $actor) {
            $this->actingAs($actor);

            $this->getJson(
                $this->cashMovementHistoryUrl($sessionId)
            )->assertForbidden();
        }
    }

    public function test_other_store_cannot_view_cash_movement_history(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);

        $ownerB = $this->createActor(
            $storeB,
            'STORE_OWNER'
        );

        $ownerA = $this->createActor(
            $storeA,
            'STORE_OWNER'
        );

        // Ouvrir une session dans la boutique B.
        $sessionId = $this->openAs(
            $ownerB,
            $registerB
        )->assertCreated()->json('session.id');

        // Le propriétaire de A tente de consulter B.
        $this->actingAs($ownerA);

        $this->getJson(
            $this->cashMovementHistoryUrl($sessionId)
        )->assertNotFound();
    }

    public function test_closed_session_preserves_cash_movement_history(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // Entrée de 1 000 FCFA avant clôture.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '1000.00',
                'reason' => 'Approvisionnement',
                'reference' => 'MOV-HISTORY-0002',
            ]
        )->assertCreated();

        // Fonds initial : 5 000.
        // Espèces attendues après entrée : 6 000.
        $this->postJson(
            $this->closeUrl($sessionId),
            [
                'closing_cash_counted' => '6000.00',
            ]
        )->assertOk();

        // L'historique doit rester accessible.
        $this->getJson(
            $this->cashMovementHistoryUrl($sessionId)
        )
            ->assertOk()
            ->assertJsonPath('status', 'CLOSED')
            ->assertJsonPath('movements.total', 1)
            ->assertJsonPath(
                'movements.data.0.reference',
                'MOV-HISTORY-0002'
            );

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'CLOSED',
            'closing_cash_expected' => '6000.00',
            'cash_difference' => '0.00',
        ]);
    }

    public function test_cash_movement_history_supports_pagination(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // Enregistrer deux mouvements.
        foreach (
            [
                'MOV-PAGE-0001',
                'MOV-PAGE-0002',
            ] as $reference
        ) {
            $this->postJson(
                $this->cashMovementUrl($sessionId),
                [
                    'type' => 'CASH_IN',
                    'amount' => '1000.00',
                    'reason' => 'Test pagination',
                    'reference' => $reference,
                ]
            )->assertCreated();
        }

        $url = $this->cashMovementHistoryUrl($sessionId);

        // Première page : mouvement le plus récent.
        $this->getJson($url . '?per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'movements.data')
            ->assertJsonPath('movements.total', 2)
            ->assertJsonPath('movements.per_page', 1)
            ->assertJsonPath(
                'movements.data.0.reference',
                'MOV-PAGE-0002'
            );

        // Deuxième page : mouvement précédent.
        $this->getJson($url . '?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'movements.data')
            ->assertJsonPath(
                'movements.data.0.reference',
                'MOV-PAGE-0001'
            );
    }

    public function test_invalid_cash_movement_history_pagination_is_rejected(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $url = $this->cashMovementHistoryUrl($sessionId);

        foreach (['0', '101', 'invalid'] as $perPage) {
            $this->getJson(
                $url . '?per_page=' . $perPage
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['per_page']);
        }
    }
    private function closingReportUrl(int $sessionId): string
    {
        return route('pos.sessions.closing-report', [
            'sessionId' => $sessionId,
        ]);
    }

    public function test_guest_cannot_view_pos_closing_report(): void
    {
        $this->getJson(
            $this->closingReportUrl(1)
        )->assertUnauthorized();
    }

    public function test_open_session_cannot_generate_closing_report(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $owner = $this->createActor($store, 'STORE_OWNER');

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->getJson(
            $this->closingReportUrl($sessionId)
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['session']);
    }

    public function test_cashier_can_view_own_closed_session_report(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store, 'CASHIER');

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->closeUrl($sessionId),
            ['closing_cash_counted' => '7000.00']
        )->assertOk();

        $this->getJson(
            $this->closingReportUrl($sessionId)
        )
            ->assertOk()
            ->assertJsonPath('report_type', 'POS_CLOSING')
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('status', 'CLOSED')
            ->assertJsonPath('opened_by.id', $cashier->id)
            ->assertJsonPath('closed_by.id', $cashier->id)
            ->assertJsonPath(
                'closing.expected_cash',
                '5000.00'
            )
            ->assertJsonPath(
                'closing.counted_cash',
                '7000.00'
            )
            ->assertJsonPath(
                'closing.cash_difference',
                '2000.00'
            )
            ->assertJsonPath(
                'audit.breakdown_matches_closing_snapshot',
                true
            )
            ->assertJsonStructure([
                'register' => ['id', 'name', 'code'],
                'opened_at',
                'closed_at',
                'breakdown' => [
                    'opening_cash',
                    'confirmed_cash_payments',
                    'cash_in',
                    'cash_out',
                ],
            ]);
    }

    public function test_owner_and_manager_can_view_closing_report(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashier = $this->createActor($store, 'CASHIER');
        $owner = $this->createActor($store, 'STORE_OWNER');
        $manager = $this->createActor($store, 'STORE_MANAGER');

        $sessionId = $this->openAs(
            $cashier,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->closeUrl($sessionId),
            ['closing_cash_counted' => '5000.00']
        )->assertOk();

        foreach ([$owner, $manager] as $supervisor) {
            $this->actingAs($supervisor);

            $this->getJson(
                $this->closingReportUrl($sessionId)
            )
                ->assertOk()
                ->assertJsonPath(
                    'closing.expected_cash',
                    '5000.00'
                )
                ->assertJsonPath(
                    'closing.cash_difference',
                    '0.00'
                );
        }
    }

    public function test_cashier_cannot_view_another_cashiers_closing_report(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $cashierA = $this->createActor($store, 'CASHIER');
        $cashierB = $this->createActor($store, 'CASHIER');

        $sessionId = $this->openAs(
            $cashierA,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->closeUrl($sessionId),
            ['closing_cash_counted' => '5000.00']
        )->assertOk();

        $this->actingAs($cashierB);

        $this->getJson(
            $this->closingReportUrl($sessionId)
        )->assertForbidden();
    }

    public function test_other_store_cannot_view_pos_closing_report(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);

        $ownerB = $this->createActor(
            $storeB,
            'STORE_OWNER'
        );

        $ownerA = $this->createActor(
            $storeA,
            'STORE_OWNER'
        );

        $sessionId = $this->openAs(
            $ownerB,
            $registerB
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->closeUrl($sessionId),
            ['closing_cash_counted' => '5000.00']
        )->assertOk();

        $this->actingAs($ownerA);

        $this->getJson(
            $this->closingReportUrl($sessionId)
        )->assertNotFound();
    }

    public function test_delivery_agent_cannot_view_pos_closing_report(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor($store, 'STORE_OWNER');

        $deliveryAgent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->closeUrl($sessionId),
            ['closing_cash_counted' => '5000.00']
        )->assertOk();

        $this->actingAs($deliveryAgent);

        $this->getJson(
            $this->closingReportUrl($sessionId)
        )->assertForbidden();
    }

    public function test_closing_report_detects_financial_divergence(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $owner = $this->createActor($store, 'STORE_OWNER');

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // Fonds initial : 5 000 FCFA.
        // Entrée : +2 000 FCFA.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '2000.00',
                'reason' => 'Approvisionnement',
                'reference' => 'MOV-REPORT-0001',
            ]
        )->assertCreated();

        // Sortie : -500 FCFA.
        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_OUT',
                'amount' => '500.00',
                'reason' => 'Transfert au coffre',
                'reference' => 'MOV-REPORT-0002',
            ]
        )->assertCreated();

        // Espèces attendues : 6 500 FCFA.
        // Espèces comptées : 6 400 FCFA.
        $this->postJson(
            $this->closeUrl($sessionId),
            ['closing_cash_counted' => '6400.00']
        )->assertOk();

        $url = $this->closingReportUrl($sessionId);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('breakdown.opening_cash', '5000.00')
            ->assertJsonPath('breakdown.cash_in', '2000.00')
            ->assertJsonPath('breakdown.cash_out', '500.00')
            ->assertJsonPath(
                'closing.expected_cash',
                '6500.00'
            )
            ->assertJsonPath(
                'closing.counted_cash',
                '6400.00'
            )
            ->assertJsonPath(
                'closing.cash_difference',
                '-100.00'
            )
            ->assertJsonPath(
                'audit.breakdown_matches_closing_snapshot',
                true
            );

        // Simuler une modification anormale directement
        // dans la base de TEST après la clôture.
        // Ce n'est pas une opération métier autorisée.
        DB::table('pos_cash_movements')
            ->where('store_id', $store->id)
            ->where('pos_session_id', $sessionId)
            ->where('reference', 'MOV-REPORT-0001')
            ->update([
                'amount' => '2100.00',
                'updated_at' => now(),
            ]);

        // Le rapport doit signaler l'incohérence.
        // Le bilan de clôture enregistré ne change pas.
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('breakdown.cash_in', '2100.00')
            ->assertJsonPath(
                'closing.expected_cash',
                '6500.00'
            )
            ->assertJsonPath(
                'closing.cash_difference',
                '-100.00'
            )
            ->assertJsonPath(
                'audit.breakdown_matches_closing_snapshot',
                false
            );
    }

    public function test_pos_cash_movement_message_is_in_french(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $owner = $this->createActor($store, 'STORE_OWNER');

        $owner->preferred_locale = 'fr';
        $owner->save();

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '1000.00',
                'reason' => 'Approvisionnement',
                'reference' => 'MOV-I18N-FR-001',
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Mouvement de caisse enregistré.'
            );
    }

    public function test_pos_cash_movement_message_is_in_english(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $owner = $this->createActor($store, 'STORE_OWNER');

        $owner->preferred_locale = 'en';
        $owner->save();

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        $this->postJson(
            $this->cashMovementUrl($sessionId),
            [
                'type' => 'CASH_IN',
                'amount' => '1000.00',
                'reason' => 'Cash replenishment',
                'reference' => 'MOV-I18N-EN-001',
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Cash movement recorded successfully.'
            );

        $this->assertDatabaseHas('pos_cash_movements', [
            'store_id' => $store->id,
            'pos_session_id' => $sessionId,
            'type' => 'CASH_IN',
            'amount' => '1000.00',
        ]);
    }

    public function test_pos_closing_report_error_is_in_english(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $owner = $this->createActor($store, 'STORE_OWNER');

        $owner->preferred_locale = 'en';
        $owner->save();

        $sessionId = $this->openAs(
            $owner,
            $register
        )->assertCreated()->json('session.id');

        // Impossible de générer un rapport de clôture
        // tant que la caisse est encore ouverte.
        $this->getJson(
            $this->closingReportUrl($sessionId)
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.session.0',
                'The POS session must be closed before generating its report.'
            );
    }
    
public function test_insufficient_cash_error_is_translated_into_english(): void
{
    $store = $this->createStore();
    $register = $this->createRegister($store);
    $owner = $this->createActor($store, 'STORE_OWNER');

    $owner->preferred_locale = 'en';
    $owner->save();

    $sessionId = $this->openAs(
        $owner,
        $register
    )->assertCreated()->json('session.id');

    // Fonds initial : 5 000 FCFA.
    // Retrait demandé : 8 000 FCFA.
    $this->postJson(
        $this->cashMovementUrl($sessionId),
        [
            'type' => 'CASH_OUT',
            'amount' => '8000.00',
            'reason' => 'Excessive withdrawal',
            'reference' => 'MOV-I18N-OUT-001',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.amount.0',
            'Withdrawal refused: insufficient cash in the register.'
        );

    $this->assertDatabaseCount(
        'pos_cash_movements',
        0
    );
}

public function test_cash_limit_error_is_translated_into_english(): void
{
    $store = $this->createStore();
    $register = $this->createRegister($store);
    $owner = $this->createActor($store, 'STORE_OWNER');

    $owner->preferred_locale = 'en';
    $owner->save();

    $sessionId = $this->openAs(
        $owner,
        $register
    )->assertCreated()->json('session.id');

    // Tentative de dépassement du plafond.
    $this->postJson(
        $this->cashMovementUrl($sessionId),
        [
            'type' => 'CASH_IN',
            'amount' => '9999999999.99',
            'reason' => 'Excessive cash deposit',
            'reference' => 'MOV-I18N-IN-001',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.amount.0',
            'Cash deposit refused: register balance limit exceeded.'
        );

    $this->assertDatabaseCount(
        'pos_cash_movements',
        0
    );
}

}
