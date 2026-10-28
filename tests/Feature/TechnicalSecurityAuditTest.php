<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TechnicalSecurityAuditTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Uncached SQLite :memory: only.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    public function test_permission_pivot_rollback_never_drops_user_role_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $before = DB::table('role_user')->get()->toArray();
        $migration = require database_path('migrations/2026_10_01_131424_create_permission_role_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('permission_role'));
        $this->assertTrue(Schema::hasTable('role_user'));
        $this->assertEquals($before, DB::table('role_user')->get()->toArray());
        $migration->up();
        $this->assertTrue(Schema::hasTable('permission_role'));
    }

    public function test_owner_invitation_token_is_rechecked_inside_activation_transaction(): void
    {
        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $store = Store::create(['name' => 'Fictional audit store', 'slug' => 'audit-invitation', 'status' => 'PENDING']);
        $owner = User::factory()->create(['store_id' => $store->id, 'status' => 'INVITED', 'email_verified_at' => null, 'is_buyer' => false, 'email' => 'audit-owner@example.test']);
        $owner->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $broker = Password::broker('users');
        $token = $broker->createToken($owner);
        $baseLevel = DB::transactionLevel();
        $rotated = false;
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::retrieved(function (User $retrieved) use ($owner, $broker, $baseLevel, &$rotated): void {
            if (! $rotated && $retrieved->id === $owner->id && DB::transactionLevel() > $baseLevel) {
                $rotated = true;
                $broker->createToken($retrieved);
            }
        });
        try {
            $this->postJson(route('owner.invitation.accept', $token), ['email' => $owner->email, 'password' => 'Fictional-new-password-123', 'password_confirmation' => 'Fictional-new-password-123'])->assertUnprocessable();
        } finally {
            User::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($rotated);
        $this->assertSame('INVITED', $owner->fresh()->status);
        $this->assertNull($owner->fresh()->email_verified_at);
    }
}
