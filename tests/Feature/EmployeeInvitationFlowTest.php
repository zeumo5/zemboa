<?php

namespace Tests\Feature;


use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EmployeeInvitationFlowTest extends TestCase
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

        // Aucun e-mail réel ne sera envoyé.
        Notification::fake();

        $this->seed(DatabaseSeeder::class);
    }

    private function prepareOwner(
        string $slug = 'audit-employees'
    ): array {
        $store = Store::create([
            'name' => 'Boutique Audit',
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $owner = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $owner->roles()->attach($role->id);

        $this->actingAs($owner);

        return [$store, $owner];
    }

    private function inviteEmployee(
        string $role = 'STORE_MANAGER'
    ): array {
        $email = 'employe-' . Str::lower(Str::random(10))
            . '@example.test';

        $this->postJson(route('employees.store'), [
            'name' => 'Employé ZEMBOA',
            'email' => $email,
            'role' => $role,
        ])
            ->assertCreated()
            ->assertJsonPath('employee.status', 'INVITED');

        $employee = User::where('email', $email)
            ->firstOrFail();

        $notification = Notification::sent(
            $employee,
            EmployeeInvitationNotification::class
        )->first();

        $this->assertNotNull($notification);

        // Récupérer le jeton du lien généré
        // par la véritable notification Laravel.
        $url = $notification->toMail($employee)->actionUrl;

        $path = parse_url($url, PHP_URL_PATH);

        $this->assertIsString($path);

        $token = basename($path);

        $this->assertNotSame('', $token);

        return [$employee, $token];
    }

    public function test_owner_can_invite_employee(): void
    {
        [$store] = $this->prepareOwner();

        [$employee] = $this->inviteEmployee();

        $this->assertSame(
            $store->id,
            $employee->store_id
        );

        $this->assertSame('INVITED', $employee->status);

        $this->assertTrue(
            $employee->hasRole('STORE_MANAGER')
        );

        $this->assertNull($employee->email_verified_at);
    }

    public function test_privileged_roles_cannot_be_invited(): void
    {
        $this->prepareOwner();

        foreach (['STORE_OWNER', 'SUPER_ADMIN'] as $role) {
            $this->postJson(route('employees.store'), [
                'name' => 'Employé interdit',
                'email' => Str::random(10) . '@example.test',
                'role' => $role,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('role');
        }

        Notification::assertNothingSent();
    }

    public function test_manager_cannot_create_employees(): void
    {
        [$store] = $this->prepareOwner();

        $manager = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $manager->roles()->attach($role->id);

        $this->actingAs($manager);

        $this->postJson(route('employees.store'), [
            'name' => 'Employé interdit',
            'email' => 'interdit@example.test',
            'role' => 'CASHIER',
        ])->assertForbidden();

        $this->getJson(route('employees.index'))
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_valid_invitation_activates_account(): void
    {
        $this->prepareOwner();

        [$employee, $token] = $this->inviteEmployee();

        $this->get(route('employee.invitation.form', [
            'token' => $token,
            'email' => $employee->email,
        ]))->assertOk();

        $password = 'ZemboaStrong123!';

        $this->post(route('employee.invitation.accept', [
            'token' => $token,
        ]), [
            'email' => $employee->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $employee->refresh();

        $this->assertSame('ACTIVE', $employee->status);

        $this->assertTrue(
            Hash::check($password, $employee->password)
        );

        $this->assertNotNull($employee->email_verified_at);

        $this->assertFalse(
            Password::broker('users')
                ->tokenExists($employee, $token)
        );
    }

    public function test_invitation_cannot_be_reused(): void
    {
        $this->prepareOwner();

        [$employee, $token] = $this->inviteEmployee();

        $data = [
            'email' => $employee->email,
            'password' => 'ZemboaStrong123!',
            'password_confirmation' => 'ZemboaStrong123!',
        ];

        $url = route('employee.invitation.accept', [
            'token' => $token,
        ]);

        $this->postJson($url, $data)->assertOk();

        $this->postJson($url, $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(
            'ACTIVE',
            $employee->fresh()->status
        );
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $this->prepareOwner();

        [$employee, $token] = $this->inviteEmployee();

        // Le broker est configuré pour 60 minutes.
        $this->travel(61)->minutes();

        $this->get(route('employee.invitation.form', [
            'token' => $token,
            'email' => $employee->email,
        ]))->assertNotFound();

        $this->postJson(
            route('employee.invitation.accept', [
                'token' => $token,
            ]),
            [
                'email' => $employee->email,
                'password' => 'ZemboaStrong123!',
                'password_confirmation' => 'ZemboaStrong123!',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(
            'INVITED',
            $employee->fresh()->status
        );

        $this->travelBack();
    }

    public function test_inactive_store_blocks_invitation(): void
    {
        [$store] = $this->prepareOwner();

        [$employee, $token] = $this->inviteEmployee();

        $store->status = 'INACTIVE';
        $store->save();

        $this->get(route('employee.invitation.form', [
            'token' => $token,
            'email' => $employee->email,
        ]))->assertNotFound();

        $this->postJson(
            route('employee.invitation.accept', [
                'token' => $token,
            ]),
            [
                'email' => $employee->email,
                'password' => 'ZemboaStrong123!',
                'password_confirmation' => 'ZemboaStrong123!',
            ]
        )->assertUnprocessable();

        $this->assertSame(
            'INVITED',
            $employee->fresh()->status
        );
    }

    public function test_owner_cannot_activate_pending_invitation(): void
    {
        $this->prepareOwner();

        [$employee] = $this->inviteEmployee();

        $this->patchJson(route('employees.update-status', [
            'employeeId' => $employee->id,
        ]), [
            'status' => 'ACTIVE',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(
            'INVITED',
            $employee->fresh()->status
        );
    }

    public function test_owner_cannot_modify_foreign_employee(): void
    {
        $this->prepareOwner('boutique-alpha');

        $otherStore = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $foreignEmployee = User::factory()->create([
            'store_id' => $otherStore->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $foreignEmployee->roles()->attach($role->id);

        $this->patchJson(route('employees.update-role', [
            'employeeId' => $foreignEmployee->id,
        ]), [
            'role' => 'STORE_MANAGER',
        ])->assertNotFound();

        $this->patchJson(route('employees.update-status', [
            'employeeId' => $foreignEmployee->id,
        ]), [
            'status' => 'INACTIVE',
        ])->assertNotFound();

        $this->assertSame(
            'ACTIVE',
            $foreignEmployee->fresh()->status
        );

        $this->assertTrue(
            $foreignEmployee->hasRole('CASHIER')
        );
    }

    public function test_owner_can_change_employee_role_safely(): void
    {
        $this->prepareOwner();

        [$employee] = $this->inviteEmployee('CASHIER');

        $this->patchJson(route('employees.update-role', [
            'employeeId' => $employee->id,
        ]), [
            'role' => 'STORE_MANAGER',
        ])->assertOk();

        $this->assertTrue(
            $employee->hasRole('STORE_MANAGER')
        );

        $this->assertFalse(
            $employee->hasRole('CASHIER')
        );

        // Impossible d'attribuer un rôle privilégié.
        $this->patchJson(route('employees.update-role', [
            'employeeId' => $employee->id,
        ]), [
            'role' => 'SUPER_ADMIN',
        ])->assertUnprocessable();

        $this->assertFalse(
            $employee->hasRole('SUPER_ADMIN')
        );
    }

    public function test_disabled_employee_loses_access_and_can_be_reactivated(): void
    {
        [, $owner] = $this->prepareOwner();

        [$employee, $token] = $this->inviteEmployee();

        $password = 'ZemboaStrong123!';

        $this->post(route('employee.invitation.accept', [
            'token' => $token,
        ]), [
            'email' => $employee->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $employee->refresh();

        // Le gestionnaire actif accède à son espace.
        $this->actingAs($employee);

        $this->getJson(route('stock.movements'))
            ->assertOk();

        // Le propriétaire désactive son compte.
        $this->actingAs($owner);

        $this->patchJson(route('employees.update-status', [
            'employeeId' => $employee->id,
        ]), [
            'status' => 'INACTIVE',
        ])->assertOk();

        // Même avec une session existante,
        // l'employé désactivé est refusé.
        $employee->refresh();
        $this->actingAs($employee);

        $this->getJson(route('stock.movements'))
            ->assertForbidden();

        // Réactivation par le propriétaire.
        $this->actingAs($owner);

        $this->patchJson(route('employees.update-status', [
            'employeeId' => $employee->id,
        ]), [
            'status' => 'ACTIVE',
        ])->assertOk();

        $employee->refresh();
        $this->actingAs($employee);

        $this->getJson(route('stock.movements'))
            ->assertOk();
    }

    public function test_owner_account_remains_protected_even_with_employee_role(): void
    {
        [, $owner] = $this->prepareOwner();

        // Cas défensif : un propriétaire possède
        // accidentellement un second rôle CASHIER.
        $cashierRole = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $owner->roles()->attach($cashierRole->id);

        // L'API des employés ne doit jamais permettre
        // de rétrograder le propriétaire.
        $this->patchJson(route('employees.update-role', [
            'employeeId' => $owner->id,
        ]), [
            'role' => 'STORE_MANAGER',
        ])->assertForbidden();

        $this->patchJson(route('employees.update-status', [
            'employeeId' => $owner->id,
        ]), [
            'status' => 'INACTIVE',
        ])->assertForbidden();

        $this->assertTrue(
            $owner->hasRole('STORE_OWNER')
        );

        $this->assertSame(
            'ACTIVE',
            $owner->fresh()->status
        );
    }
}
