<?php
namespace Tests\Feature;


use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PosSessionTenantConstraintTest extends TestCase
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

    private function hasTenantForeignKey(string $table): bool
    {
        $foreignKeys = collect(
            DB::select("PRAGMA foreign_key_list('$table')")
        )->groupBy('id');

        foreach ($foreignKeys as $group) {
            $columns = $group->sortBy('seq')->values();

            if (
                $columns->first()->table === 'pos_sessions' &&
                $columns->pluck('from')->all() === [
                    'store_id',
                    'pos_session_id',
                ] &&
                $columns->pluck('to')->all() === [
                    'store_id',
                    'id',
                ]
            ) {
                return true;
            }
        }

        return false;
    }

    public function test_session_has_composite_unique_index(): void
    {
        $indexes = collect(
            DB::select("PRAGMA index_list('pos_sessions')")
        );

        $index = $indexes->firstWhere(
            'name',
            'pos_sessions_store_id_id_unique'
        );

        $this->assertNotNull($index);
        $this->assertSame(1, (int) $index->unique);

        $columns = collect(
            DB::select(
                "PRAGMA index_info('pos_sessions_store_id_id_unique')"
            )
        )->sortBy('seqno')->pluck('name')->values()->all();

        $this->assertSame(['store_id', 'id'], $columns);

        $this->assertSame(
            1,
            (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys
        );
    }

    public function test_orders_have_tenant_scoped_foreign_key(): void
    {
        $this->assertTrue(
            $this->hasTenantForeignKey('orders'),
            'La relation orders vers pos_sessions doit inclure store_id.'
        );
    }

    public function test_payments_have_tenant_scoped_foreign_key(): void
    {
        $this->assertTrue(
            $this->hasTenantForeignKey('payments'),
            'La relation payments vers pos_sessions doit inclure store_id.'
        );
    }
}
