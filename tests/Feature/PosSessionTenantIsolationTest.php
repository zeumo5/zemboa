<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PosSessionTenantIsolationTest extends TestCase
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

    private function createRecords(string $slug): array
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $registerId = DB::table('pos_registers')
            ->insertGetId([
                'store_id' => $store->id,
                'name' => 'Caisse principale',
                'code' => 'CAISSE-01',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $sessionId = DB::table('pos_sessions')
            ->insertGetId([
                'store_id' => $store->id,
                'pos_register_id' => $registerId,
                'opened_by_user_id' => $user->id,
                'status' => 'OPEN',
                'open_slot' => 1,
                'opening_cash' => '5000.00',
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $customerId = DB::table('customers')
            ->insertGetId([
                'store_id' => $store->id,
                'name' => 'Client ' . $slug,
                'phone' => '600' . str_pad(
                    (string) $store->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $orderId = DB::table('orders')
            ->insertGetId([
                'store_id' => $store->id,
                'customer_id' => $customerId,
                'order_number' => 'POS-TEST-' . $store->id,
                'status' => 'PENDING',
                'payment_status' => 'UNPAID',
                'fulfillment_type' => 'PICKUP',
                'customer_name' => 'Client ' . $slug,
                'customer_phone' => '600000000',
                'subtotal' => '12000.00',
                'discount_amount' => '0.00',
                'delivery_fee' => '0.00',
                'total' => '12000.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $paymentId = DB::table('payments')
            ->insertGetId([
                'store_id' => $store->id,
                'order_id' => $orderId,
                'amount' => '12000.00',
                'method' => 'CASH',
                'status' => 'PENDING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'store_id' => $store->id,
            'session_id' => $sessionId,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
        ];
    }

    private function assertForeignKeyRejected(
        callable $operation
    ): void {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'FOREIGN KEY',
                strtoupper($exception->getMessage())
            );

            return;
        }

        $this->fail(
            'La base aurait dû refuser la liaison entre boutiques.'
        );
    }

    public function test_order_cannot_use_session_from_another_store(): void
    {
        $storeA = $this->createRecords('alpha');
        $storeB = $this->createRecords('beta');

        $this->assertForeignKeyRejected(function () use (
            $storeA,
            $storeB
        ) {
            DB::table('orders')
                ->where('id', $storeA['order_id'])
                ->update([
                    'pos_session_id' => $storeB['session_id'],
                ]);
        });

        $this->assertNull(
            DB::table('orders')
                ->where('id', $storeA['order_id'])
                ->value('pos_session_id')
        );
    }

    public function test_payment_cannot_use_session_from_another_store(): void
    {
        $storeA = $this->createRecords('alpha');
        $storeB = $this->createRecords('beta');

        $this->assertForeignKeyRejected(function () use (
            $storeA,
            $storeB
        ) {
            DB::table('payments')
                ->where('id', $storeA['payment_id'])
                ->update([
                    'pos_session_id' => $storeB['session_id'],
                ]);
        });

        $this->assertNull(
            DB::table('payments')
                ->where('id', $storeA['payment_id'])
                ->value('pos_session_id')
        );
    }

    public function test_same_store_links_are_accepted(): void
    {
        $records = $this->createRecords('alpha');

        DB::table('orders')
            ->where('id', $records['order_id'])
            ->update([
                'pos_session_id' => $records['session_id'],
            ]);

        DB::table('payments')
            ->where('id', $records['payment_id'])
            ->update([
                'pos_session_id' => $records['session_id'],
            ]);

        $this->assertDatabaseHas('orders', [
            'id' => $records['order_id'],
            'store_id' => $records['store_id'],
            'pos_session_id' => $records['session_id'],
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $records['payment_id'],
            'store_id' => $records['store_id'],
            'pos_session_id' => $records['session_id'],
        ]);
    }

    public function test_normal_orders_can_have_no_pos_session(): void
    {
        $records = $this->createRecords('alpha');

        $this->assertNull(
            DB::table('orders')
                ->where('id', $records['order_id'])
                ->value('pos_session_id')
        );

        $this->assertNull(
            DB::table('payments')
                ->where('id', $records['payment_id'])
                ->value('pos_session_id')
        );
    }
}
