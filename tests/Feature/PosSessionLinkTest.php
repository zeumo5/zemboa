<?php
namespace Tests\Feature;


use App\Models\Order;
use App\Models\Payment;
use App\Models\PosSession;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PosSessionLinkTest extends TestCase
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

    public function test_orders_and_payments_have_pos_session_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('orders', 'pos_session_id')
        );

        $this->assertTrue(
            Schema::hasColumn('payments', 'pos_session_id')
        );
    }

    public function test_pos_session_columns_are_nullable(): void
    {
        foreach (['orders', 'payments'] as $table) {
            $columns = DB::select(
                "PRAGMA table_info('$table')"
            );

            $column = collect($columns)->firstWhere(
                'name',
                'pos_session_id'
            );

            $this->assertNotNull($column);

            // SQLite : 0 signifie que NULL est autorisé.
            $this->assertSame(
                0,
                (int) $column->notnull
            );
        }
    }

    public function test_order_and_payment_belong_to_pos_session(): void
    {
        $orderRelation = (new Order())->posSession();

        $this->assertInstanceOf(
            BelongsTo::class,
            $orderRelation
        );

        $this->assertSame(
            'pos_session_id',
            $orderRelation->getForeignKeyName()
        );

        $this->assertInstanceOf(
            PosSession::class,
            $orderRelation->getRelated()
        );

        $paymentRelation = (new Payment())->posSession();

        $this->assertInstanceOf(
            BelongsTo::class,
            $paymentRelation
        );

        $this->assertSame(
            'pos_session_id',
            $paymentRelation->getForeignKeyName()
        );

        $this->assertInstanceOf(
            PosSession::class,
            $paymentRelation->getRelated()
        );
    }

    public function test_session_has_orders_and_payments_relations(): void
    {
        $session = new PosSession();

        $ordersRelation = $session->orders();

        $this->assertInstanceOf(
            HasMany::class,
            $ordersRelation
        );

        $this->assertSame(
            'pos_session_id',
            $ordersRelation->getForeignKeyName()
        );

        $paymentsRelation = $session->payments();

        $this->assertInstanceOf(
            HasMany::class,
            $paymentsRelation
        );

        $this->assertSame(
            'pos_session_id',
            $paymentsRelation->getForeignKeyName()
        );
    }

    public function test_session_id_cannot_be_mass_assigned(): void
    {
        $this->assertFalse(
            (new Order())->isFillable('pos_session_id')
        );

        $this->assertFalse(
            (new Payment())->isFillable('pos_session_id')
        );
    }
}
