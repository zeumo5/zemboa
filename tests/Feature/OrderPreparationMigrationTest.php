<?php

namespace Tests\Feature;

use App\Actions\Orders\AdvanceOrderPreparation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderPreparationMigrationTest extends TestCase
{
    public function test_preparation_migration_round_trip_preserves_orders_payments_and_other_schema(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Uncached SQLite :memory: only.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->artisan('migrate', ['--database' => 'sqlite', '--no-interaction' => true])->assertSuccessful();
        $store = Store::create(['name' => 'Synthetic migration store', 'slug' => 'synthetic-migration', 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        $actor = User::factory()->create(['store_id' => $store->id]);
        $order = Order::create(['order_number' => 'SYNTHETIC-MIGRATION', 'fulfillment_type' => 'PICKUP', 'status' => 'PENDING', 'payment_status' => 'UNPAID', 'subtotal' => '1000.00', 'total' => '1000.00']);
        Payment::create(['order_id' => $order->id, 'amount' => '1000.00', 'method' => 'CASH', 'status' => 'PENDING']);
        $fields = ['preparation_started_at', 'preparation_started_by', 'ready_at', 'ready_by'];
        $before = (array) DB::table('orders')->first();
        foreach ($fields as $field) {
            unset($before[$field]);
        }
        $payment = DB::table('payments')->get()->toJson();
        $this->assertTrue(AdvanceOrderPreparation::schemaReady());
        $migration = require database_path('migrations/2026_10_25_052900_add_preparation_tracking_to_orders_table.php');
        $migration->down();
        $this->assertFalse(AdvanceOrderPreparation::schemaReady());
        $after = (array) DB::table('orders')->first();
        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($payment, DB::table('payments')->get()->toJson());
        $this->assertTrue(Schema::hasTable('stock_operation_submissions'));
        $migration->up();
        $this->assertTrue(AdvanceOrderPreparation::schemaReady());
        foreach ($fields as $field) {
            $this->assertNull(DB::table('orders')->value($field));
        }
        $order->forceFill(['preparation_started_at' => now(), 'preparation_started_by' => $actor->id, 'ready_at' => now(), 'ready_by' => $actor->id])->save();
        $history = DB::table('orders')->get()->toJson();
        try {
            $migration->down();
            $this->fail('A rollback must not erase preparation history.');
        } catch (\LogicException $error) {
            $this->assertSame('Preparation history exists; rollback refused.', $error->getMessage());
        }
        $this->assertSame($history, DB::table('orders')->get()->toJson());
        $this->assertTrue(AdvanceOrderPreparation::schemaReady());
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
