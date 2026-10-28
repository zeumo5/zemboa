<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\DemoSuperAdminProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SQLite3;
use Tests\TestCase;

class DemoSuperAdminProvisionerTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
        DB::connection()->rollBack();
    }

    private function provision(?\Closure $backup = null, string $password = 'Fictional-local-password-123'): User
    {
        return (new DemoSuperAdminProvisioner)->create(DB::connection(), $password, $backup ?? fn () => null);
    }

    public function test_creation_is_exclusive_and_preserves_existing_users_and_permissions(): void
    {
        $role = Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Administrator']);
        $existing = User::factory()->create();
        $before = hash('sha256', serialize($existing->fresh()->getAttributes()));
        $called = false;
        $admin = $this->provision(function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
        $this->assertTrue($admin->canUseSuperAdminAccount());
        $this->assertSame([$role->id], $admin->roles()->pluck('roles.id')->all());
        $this->assertFalse($admin->is_buyer);
        $this->assertNull($admin->store_id);
        $this->assertSame($before, hash('sha256', serialize($existing->fresh()->getAttributes())));
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_existing_admin_even_unqualified_blocks_creation_before_backup(): void
    {
        $role = Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Administrator']);
        $existing = User::factory()->create(['status' => 'INACTIVE']);
        $existing->roles()->attach($role);
        $this->expectException(RuntimeException::class);
        $this->provision(fn () => $this->fail('Backup must not run on collision'));
    }

    public function test_existing_identity_is_never_promoted(): void
    {
        Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Administrator']);
        User::factory()->create(['email' => strtoupper(DemoSuperAdminProvisioner::EMAIL)]);
        $this->expectException(RuntimeException::class);
        $this->provision();
    }

    public function test_failed_backup_creates_no_user(): void
    {
        Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Administrator']);
        try {
            $this->provision(function (): void {
                throw new RuntimeException('Fictional backup failure');
            });
            $this->fail('Failure expected');
        } catch (RuntimeException) {
            $this->assertSame(0, User::count());
            $this->assertSame(0, DB::table('role_user')->count());
        }
    }

    public function test_non_demo_environment_is_rejected(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->expectException(RuntimeException::class);
        $this->provision();
    }

    public function test_failed_final_invariants_roll_back_new_user_and_role_link(): void
    {
        Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Administrator']);
        User::created(function (User $user): void {
            $user->forceFill(['is_buyer' => true])->saveQuietly();
        });
        try {
            $this->provision();
            $this->fail('Invariant failure expected');
        } catch (RuntimeException) {
            $this->assertSame(0, User::count());
            $this->assertSame(0, DB::table('role_user')->count());
        }
    }

    public function test_short_password_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->provision(password: 'short');
    }

    public function test_backup_api_copies_and_checks_only_in_memory_databases(): void
    {
        $source = new SQLite3(':memory:');
        $target = new SQLite3(':memory:');
        $source->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY)');
        $source->exec('INSERT INTO sample VALUES (7)');
        DemoSuperAdminProvisioner::backup($source, $target);
        $this->assertSame(7, $target->querySingle('SELECT id FROM sample'));
        $this->assertSame('ok', $target->querySingle('PRAGMA integrity_check'));
        $source->close();
        $target->close();
    }
}
