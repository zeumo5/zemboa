<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BuyerIdentityMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Only uncached SQLite :memory: is allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        // A fresh application connection is an empty SQLite :memory: database.
        // No surrounding test transaction: exercise the migration's own DDL transaction.
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_22_010000_allow_phone_buyer_identity_on_users_table.php');
    }

    public function test_historical_duplicate_buyers_stop_before_any_ddl_without_rewriting_users(): void
    {
        $migration = $this->migration(); $migration->down();
        User::factory()->create(['phone' => '690000001', 'is_buyer' => true]);
        User::factory()->create(['phone' => '+237690000001', 'is_buyer' => true]);
        $before = User::orderBy('id')->get()->map->getRawOriginal()->all();
        try { $migration->up(); $this->fail('Expected conflict preflight failure'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Conflicting historical', $error->getMessage()); }
        $this->assertFalse(Schema::hasColumn('users', 'buyer_phone_identity'));
        $this->assertSame($before, User::orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_migration_preserves_non_buyer_duplicate_contacts_and_null_legacy_phones(): void
    {
        $migration = $this->migration(); $migration->down();
        User::factory()->create(['phone' => '690000001', 'is_buyer' => false]);
        User::factory()->create(['phone' => '+237690000001', 'is_buyer' => false]);
        User::factory()->create(['phone' => null, 'is_buyer' => true]);
        $before = User::orderBy('id')->get()->map->getRawOriginal()->all();
        $migration->up();
        $after = User::orderBy('id')->get()->map(function ($user) { $row = $user->getRawOriginal(); unset($row['buyer_phone_identity']); return $row; })->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_down_refuses_to_invent_email_or_destroy_email_less_accounts(): void
    {
        $user = User::factory()->create(['email' => null, 'phone' => '690000001', 'is_buyer' => true]);
        try { $this->migration()->down(); $this->fail('Expected rollback guard'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('email-less accounts', $error->getMessage()); }
        $this->assertTrue(Schema::hasColumn('users', 'buyer_phone_identity'));
        $this->assertNull($user->fresh()->email);
    }

    public function test_missing_is_buyer_migration_blocks_registration_without_writes(): void
    {
        $this->migration()->down();
        (require base_path('database/migrations/2026_10_22_000000_add_is_buyer_to_users_table.php'))->down();
        $this->postJson(route('buyer.register.store'), ['name' => 'Acheteur', 'phone' => '690000001',
            'password' => 'MemoryBuyerPassword123!', 'password_confirmation' => 'MemoryBuyerPassword123!'])->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_original_email_unique_index_is_preserved(): void
    {
        User::factory()->create(['email' => 'unique@example.test', 'is_buyer' => true, 'phone' => '690000001']);
        try {
            User::factory()->create(['email' => 'unique@example.test', 'is_buyer' => true, 'phone' => '690000002']);
            $this->fail('Original email index must remain unique.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) { $this->assertDatabaseCount('users', 1); }
    }

    public function test_sqlite_rebuild_preserves_existing_role_links_and_foreign_keys(): void
    {
        $migration = $this->migration(); $migration->down();
        $user = User::factory()->create(['is_buyer' => true, 'phone' => '690000001']);
        $role = \App\Models\Role::create(['code' => 'MEMORY_ROLE', 'name' => 'Memory role']);
        $user->roles()->attach($role);
        $before = $user->fresh()->getRawOriginal();
        $migration->up();
        $after = $user->fresh()->getRawOriginal(); unset($after['buyer_phone_identity']);
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $role->id]);
        $this->assertSame([], \Illuminate\Support\Facades\DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) \Illuminate\Support\Facades\DB::scalar('PRAGMA foreign_keys'));
    }

    public function test_sqlite_nested_transaction_refuses_ddl_before_schema_change(): void
    {
        $migration = $this->migration(); $migration->down();
        \Illuminate\Support\Facades\DB::beginTransaction();
        try { $migration->up(); $this->fail('Expected top-level transaction guard.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('top-level', $error->getMessage()); }
        finally { \Illuminate\Support\Facades\DB::rollBack(); }
        $this->assertFalse(Schema::hasColumn('users', 'buyer_phone_identity'));
        $this->assertSame(1, (int) \Illuminate\Support\Facades\DB::scalar('PRAGMA foreign_keys'));
    }
}
