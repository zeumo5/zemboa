<?php

namespace Tests\Feature;

use App\Actions\Product\CreateProduct;
use App\Actions\Stock\CreateStockReservation;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\User;
use App\Services\StockOperationSubmission;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockOperationMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_25_023117_create_stock_operation_submissions_table';

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Start exclusively with uncached SQLite :memory:.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
    }

    private function migrateFreshSyntheticDatabase(): void
    {
        $this->artisan('migrate', ['--database' => 'sqlite', '--no-interaction' => true])->assertSuccessful();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    /** @return array{actor: User, stock: StockLevel} */
    private function fixture(): array
    {
        $store = Store::create(['name' => 'Synthetic migration audit', 'slug' => 'synthetic-migration-audit', 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        $category = Category::create(['name' => 'Synthetic category', 'slug' => 'synthetic', 'status' => 'ACTIVE']);
        $product = app(CreateProduct::class)->execute(['name' => 'Synthetic item', 'slug' => 'synthetic', 'category_id' => $category->id, 'status' => 'ACTIVE', 'default_variant' => ['sku' => 'SYNTHETIC-AUDIT', 'price' => '1000']]);
        $variant = $product->variants()->firstOrFail();
        app(ReceiveStock::class)->execute($variant->id, 10);
        app(CreateStockReservation::class)->execute($variant->id, 3, now()->addHour());
        $actor = User::factory()->create(['store_id' => $store->id, 'status' => 'ACTIVE', 'is_buyer' => false, 'email' => 'synthetic-owner@example.test']);
        $actor->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $this->actingAs($actor, 'web');

        return ['actor' => $actor, 'stock' => $variant->stockLevel];
    }

    private function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (['stores', 'users', 'products', 'product_variants', 'stock_levels', 'stock_movements', 'stock_reservations', 'orders', 'customers'] as $table) {
            $snapshot[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $snapshot;
    }

    public function test_migration_cycle_constraints_and_stock_operations_on_a_new_isolated_sqlite_database(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $inMemory = getenv('ZEMBOA_MIGRATION_AUDIT_IN_MEMORY') === '1';
        $temporaryFile = $inMemory ? ':memory:' : tempnam($temporaryRoot, 'zemboa-stock-audit-');
        $this->assertNotFalse($temporaryFile);
        if (! $inMemory) {
            $this->assertSame($temporaryRoot, realpath(dirname($temporaryFile)));
            $this->assertSame(0, filesize($temporaryFile));
        }
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $temporaryFile]);
        try {
            $this->assertSame($inMemory ? '' : realpath($temporaryFile), $inMemory ? DB::selectOne('PRAGMA database_list')->file : realpath(DB::selectOne('PRAGMA database_list')->file));
            $this->migrateFreshSyntheticDatabase();
            $f = $this->fixture();
            $submission = app(StockOperationSubmission::class);
            $token = $submission->issue($f['actor'], 'receive');
            if (! $inMemory) {
                DB::purge('sqlite');
            }
            $this->assertTrue($submission->execute($f['actor'], $f['stock'], 'receive', 2, null, $token));
            if (! $inMemory) {
                DB::purge('sqlite');
            }
            $this->assertFalse($submission->execute($f['actor'], $f['stock'], 'receive', 2, null, $token));
            $this->assertTrue($submission->execute($f['actor'], $f['stock'], 'receive', 2, null, $submission->issue($f['actor'], 'receive')));
            $out = $submission->issue($f['actor'], 'adjust-out');
            $this->assertTrue($submission->execute($f['actor'], $f['stock'], 'adjust-out', 2, null, $out));
            $this->assertFalse($submission->execute($f['actor'], $f['stock'], 'adjust-out', 2, null, $out));
            $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
            $this->assertSame(3, $f['stock']->fresh()->reserved_quantity);
            $this->assertDatabaseCount('stock_movements', 4);
            $this->assertDatabaseCount('stock_operation_submissions', 3);
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
            $columns = collect(DB::select("PRAGMA table_info('stock_operation_submissions')"));
            $this->assertSame(['id', 'store_id', 'user_id', 'stock_level_id', 'key_hash', 'request_hash', 'created_at'], $columns->pluck('name')->all());
            $this->assertSame(1, (int) $columns->firstWhere('name', 'key_hash')->notnull);
            $indexes = collect(DB::select("PRAGMA index_list('stock_operation_submissions')"));
            $this->assertSame(1, (int) $indexes->firstWhere('name', 'stock_submission_store_key_unique')->unique);
            $this->assertSame(['store_id', 'key_hash'], collect(DB::select("PRAGMA index_info('stock_submission_store_key_unique')"))->pluck('name')->all());
            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('stock_operation_submissions')"));
            $this->assertSame(['stock_levels', 'stores', 'users'], $foreignKeys->pluck('table')->sort()->values()->all());
            $this->assertSame(['RESTRICT'], $foreignKeys->pluck('on_delete')->unique()->all());
            $row = (array) DB::table('stock_operation_submissions')->first();
            unset($row['id']);
            foreach ([$row, array_replace($row, ['store_id' => 999999, 'key_hash' => str_repeat('b', 64)]), array_replace($row, ['stock_level_id' => 999999, 'key_hash' => str_repeat('c', 64)])] as $invalid) {
                try {
                    DB::table('stock_operation_submissions')->insert($invalid);
                    $this->fail('A unique or foreign key constraint must reject this row.');
                } catch (QueryException) {
                    $this->assertDatabaseCount('stock_operation_submissions', 3);
                }
            }
            $snapshot = $this->businessSnapshot();
            $batch = DB::table('migrations')->where('migration', self::MIGRATION)->value('batch');
            $this->assertNotNull($batch);
            $this->artisan('migrate:rollback', ['--database' => 'sqlite', '--path' => 'database/migrations/'.self::MIGRATION.'.php', '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
            $this->assertFalse(Schema::hasTable('stock_operation_submissions'));
            try {
                $submission->execute($f['actor'], $f['stock'], 'receive', 2, null, $token);
                $this->fail('Missing ledger must stop the operation.');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
            }
            $this->assertSame($snapshot, $this->businessSnapshot());
            $this->artisan('migrate', ['--database' => 'sqlite', '--path' => 'database/migrations/'.self::MIGRATION.'.php', '--no-interaction' => true])->assertSuccessful();
            $this->assertTrue(Schema::hasTable('stock_operation_submissions'));
            $this->assertDatabaseCount('stock_operation_submissions', 0);
            $this->assertSame($snapshot, $this->businessSnapshot());
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => ':memory:']);
            if (is_file($temporaryFile) && realpath(dirname($temporaryFile)) === $temporaryRoot && str_starts_with(basename($temporaryFile), 'zemboa-stock-audit-')) {
                unlink($temporaryFile);
            }
        }
    }

    public function test_sqlite_interrupted_transaction_rolls_back_schema_and_preserves_business_data(): void
    {
        $this->migrateFreshSyntheticDatabase();
        $this->fixture();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $snapshot = $this->businessSnapshot();
        DB::beginTransaction();
        $migration->up();
        $this->assertTrue(Schema::hasTable('stock_operation_submissions'));
        DB::rollBack();
        $this->assertFalse(Schema::hasTable('stock_operation_submissions'));
        $this->assertSame($snapshot, $this->businessSnapshot());
        $migration->up();
        $this->assertTrue(Schema::hasTable('stock_operation_submissions'));
    }

    public function test_interrupted_index_creation_leaves_a_partial_table_that_blocks_operations(): void
    {
        $this->migrateFreshSyntheticDatabase();
        $this->fixture();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $snapshot = $this->businessSnapshot();
        $interrupt = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$interrupt): void {
            if ($interrupt && str_contains($query, 'create unique index "stock_submission_store_key_unique"')) {
                throw new \RuntimeException('Synthetic index creation interruption');
            }
        });
        try {
            $migration->up();
            $this->fail('The synthetic interruption must stop migration execution.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic index creation interruption', $error->getMessage());
        } finally {
            $interrupt = false;
        }
        $this->assertTrue(Schema::hasTable('stock_operation_submissions'));
        $this->assertFalse(Schema::hasIndex('stock_operation_submissions', ['store_id', 'key_hash'], 'unique'));
        try {
            app(StockOperationSubmission::class)->ready();
            $this->fail('Incomplete schema must be refused.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertSame($snapshot, $this->businessSnapshot());
    }

    public function test_ledger_rollback_and_recreation_reject_old_tokens_and_allow_new_forms(): void
    {
        $this->migrateFreshSyntheticDatabase();
        $f = $this->fixture();
        $submission = app(StockOperationSubmission::class);
        $token = $submission->issue($f['actor'], 'receive');
        $unusedToken = $submission->issue($f['actor'], 'receive');
        $this->assertTrue($submission->execute($f['actor'], $f['stock'], 'receive', 2, null, $token));
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $migration->up();
        foreach ([$token, $unusedToken] as $oldToken) {
            try {
                app(StockOperationSubmission::class)->execute($f['actor'], $f['stock'], 'receive', 2, null, $oldToken);
                $this->fail('A token issued by the previous ledger must be refused.');
            } catch (HttpException $error) {
                $this->assertSame(419, $error->getStatusCode());
            }
        }
        $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertTrue(app(StockOperationSubmission::class)->execute($f['actor'], $f['stock'], 'receive', 2, null, $submission->issue($f['actor'], 'receive')));
        $this->assertSame(14, $f['stock']->fresh()->physical_quantity);
        $this->assertSame(3, $f['stock']->fresh()->reserved_quantity);
    }

    public function test_disabled_sqlite_foreign_key_enforcement_refuses_stock_operations(): void
    {
        $this->migrateFreshSyntheticDatabase();
        $f = $this->fixture();
        $submission = app(StockOperationSubmission::class);
        $token = $submission->issue($f['actor'], 'receive');
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $submission->execute($f['actor'], $f['stock'], 'receive', 2, null, $token);
            $this->fail('Disabled constraints must be refused.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertSame(3, $f['stock']->fresh()->reserved_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
    }
}
