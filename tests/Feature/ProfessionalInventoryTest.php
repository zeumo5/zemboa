<?php

namespace Tests\Feature;

use App\Actions\Product\CreateProduct;
use App\Actions\Stock\CreateStockMovement;
use App\Actions\Stock\CreateStockReservation;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Services\StockOperationSubmission;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProfessionalInventoryTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Uncached SQLite :memory: only.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function fixture(string $slug = 'inventory'): array
    {
        $store = Store::create(['name' => 'Fictional inventory store', 'slug' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['pickup_enabled' => true]);
        $category = Category::create(['name' => 'Fictional category', 'slug' => 'category', 'status' => 'ACTIVE']);
        $product = app(CreateProduct::class)->execute(['name' => 'Fictional product '.$slug, 'slug' => 'product', 'category_id' => $category->id, 'status' => 'ACTIVE', 'default_variant' => ['sku' => 'SKU-'.$slug, 'price' => '1000']]);
        $variant = $product->variants()->firstOrFail();
        app(ReceiveStock::class)->execute($variant->id, 10);
        $actor = User::factory()->create(['name' => 'Fictional merchant', 'email' => 'owner-'.fake()->unique()->numerify('########').'@example.test', 'store_id' => $store->id, 'status' => 'ACTIVE', 'is_buyer' => false, 'preferred_locale' => 'fr', 'email_verified_at' => now()]);
        $actor->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $stock = $variant->stockLevel;

        return compact('store', 'product', 'variant', 'actor', 'stock', 'category');
    }

    private function token(array $f, string $operation): string
    {
        $this->actingAs($f['actor'], 'web');
        app(TenantContext::class)->setFromStore($f['store']);

        return app(StockOperationSubmission::class)->issue($f['actor'], $operation);
    }

    private function submit(array $f, string $kind, string $token, array $extra = []): TestResponse
    {
        return $this->post(route('professional.inventory.submit', $kind), array_replace(['stock_level_id' => $f['stock']->id, 'quantity' => 2, 'reason' => 'Inventory operation', 'operation_token' => $token], $extra));
    }

    public function test_receipt_replay_is_applied_only_once_and_distinct_identical_operations_are_allowed(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $this->submit($f, 'receive', $token)->assertRedirect()->assertSessionHasNoErrors();
        $this->submit($f, 'receive', $token)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('stock_operation_submissions', 1);
        $this->submit($f, 'receive', $this->token($f, 'receive'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(14, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_operation_submissions', 2);
    }

    public function test_changed_replay_and_other_actor_cannot_reuse_a_successful_token(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $this->submit($f, 'receive', $token)->assertRedirect();
        $this->submit($f, 'receive', $token, ['quantity' => 3])->assertConflict();
        $other = User::factory()->create(['store_id' => $f['store']->id, 'status' => 'ACTIVE', 'email_verified_at' => now(), 'is_buyer' => false]);
        $other->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $this->actingAs($other, 'web');
        $this->submit($f, 'receive', $token)->assertStatus(419);
        $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
    }

    public function test_adjustments_use_existing_actions_and_never_change_reserved_stock(): void
    {
        $f = $this->fixture();
        app(CreateStockReservation::class)->execute($f['variant']->id, 3, now()->addHour());
        $in = $this->token($f, 'adjust-in');
        $this->submit($f, 'adjust-in', $in)->assertRedirect()->assertSessionHasNoErrors();
        $this->submit($f, 'adjust-in', $in)->assertRedirect();
        $out = $this->token($f, 'adjust-out');
        $this->submit($f, 'adjust-out', $out)->assertRedirect()->assertSessionHasNoErrors();
        $this->submit($f, 'adjust-out', $out)->assertRedirect();
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertSame(3, $f['stock']->fresh()->reserved_quantity);
        $this->assertSame(7, $f['stock']->fresh()->availableQuantity());
        $bad = $this->token($f, 'adjust-out');
        $this->submit($f, 'adjust-out', $bad, ['quantity' => 8])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('stock_operation_submissions', 3);
        $this->assertSame(2, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
        $this->submit($f, 'adjust-out', $bad, ['quantity' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(9, $f['stock']->fresh()->physical_quantity);
    }

    public function test_reason_limit_matches_schema_on_html_and_legacy_routes(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $this->submit($f, 'receive', $token, ['reason' => str_repeat('x', 256)])->assertSessionHasErrors('reason');
        $this->postJson(route('stock.receive', $f['stock']), ['quantity' => 1, 'reason' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->submit($f, 'receive', $token, ['reason' => str_repeat('x', 255)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_operation_submissions', 1);
    }

    public function test_tokens_cannot_be_tampered_with_or_used_for_other_operations_and_expire(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $this->submit($f, 'receive', 'tampered')->assertStatus(419);
        $this->submit($f, 'adjust-out', $token)->assertStatus(419);
        $this->travel(121)->minutes();
        $this->submit($f, 'receive', $token)->assertStatus(419);
        $this->travelBack();
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_operation_submissions', 1);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
    }

    public function test_foreign_stock_reservations_and_movements_are_not_exposed_or_mutable(): void
    {
        $a = $this->fixture('alpha');
        $b = $this->fixture('beta');
        $foreign = app(CreateStockReservation::class)->execute($b['variant']->id, 2, now()->addHour());
        $token = $this->token($a, 'receive');
        $this->get(route('professional.inventory.show', $b['stock']))->assertNotFound();
        $this->get(route('professional.inventory.reservation', $foreign))->assertNotFound();
        $this->submit($a, 'receive', $token, ['stock_level_id' => $b['stock']->id])->assertNotFound();
        $this->get(route('professional.inventory.index', ['store_id' => $b['store']->id]))->assertOk()->assertDontSee('SKU-beta');
        $this->get(route('professional.inventory.movements'))->assertOk()->assertDontSee('SKU-beta');
        $this->get(route('professional.inventory.reservations'))->assertOk()->assertDontSee('SKU-beta');
        $this->assertDatabaseCount('stock_operation_submissions', 1);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
    }

    public function test_read_only_permissions_hide_mutations_and_deny_direct_post(): void
    {
        $f = $this->fixture();
        $role = $f['actor']->roles()->firstOrFail();
        $role->permissions()->sync([Permission::where('code', 'inventory.view')->firstOrFail()->id]);
        $this->actingAs($f['actor'], 'web');
        $this->get(route('professional.inventory.index'))->assertOk()->assertDontSee('href="'.route('professional.inventory.receive').'"', false);
        $this->get(route('professional.inventory.receive'))->assertForbidden();
        $this->postJson(route('professional.inventory.submit', 'receive'), [])->assertForbidden();
        $this->get(route('professional.inventory.movements'))->assertOk();
    }

    public function test_disabled_mixed_and_global_identities_are_refused(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'], 'web');
        $f['actor']->forceFill(['status' => 'INACTIVE'])->save();
        $this->get(route('professional.inventory.index'))->assertForbidden();
        $f['actor']->forceFill(['status' => 'ACTIVE'])->save();
        $f['actor']->roles()->attach(Role::where('code', 'CASHIER')->firstOrFail());
        $this->get(route('professional.inventory.index'))->assertForbidden();
        $admin = User::factory()->create(['status' => 'ACTIVE', 'store_id' => null, 'is_buyer' => false]);
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->actingAs($admin, 'web');
        $this->get(route('professional.inventory.index'))->assertForbidden();
    }

    public function test_reservation_states_are_read_only_and_overdue_active_reservations_still_hold_stock(): void
    {
        $f = $this->fixture();
        $overdue = app(CreateStockReservation::class)->execute($f['variant']->id, 2, now()->subMinute());
        foreach (['CONVERTED', 'RELEASED', 'EXPIRED'] as $status) {
            StockReservation::create(['product_variant_id' => $f['variant']->id, 'quantity' => 1, 'status' => $status, 'expires_at' => now()->subHour(), 'released_at' => $status === 'CONVERTED' ? null : now(), 'converted_at' => $status === 'CONVERTED' ? now() : null]);
        }
        $this->actingAs($f['actor'], 'web');
        $this->get(route('professional.inventory.reservations', ['status' => 'overdue']))->assertOk()->assertSee(__('inventory.overdue', [], 'fr'));
        foreach (['CONVERTED', 'RELEASED', 'EXPIRED'] as $status) {
            $this->get(route('professional.inventory.reservations', ['status' => $status]))->assertOk()->assertSee(__('inventory.'.$status, [], 'fr'));
        }
        $this->get(route('professional.inventory.reservation', $overdue))->assertOk()->assertSee(__('inventory.read_only', [], 'fr'));
        $this->post(route('professional.inventory.reservation', $overdue), [])->assertStatus(405);
        $this->assertSame('ACTIVE', $overdue->fresh()->status);
        $this->assertSame(2, $f['stock']->fresh()->reserved_quantity);
        $this->assertSame(8, $f['stock']->fresh()->availableQuantity());
    }

    public function test_missing_submission_schema_blocks_writes_but_allows_reading(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'], 'web');
        Schema::drop('stock_operation_submissions');
        $this->get(route('professional.inventory.index'))->assertOk();
        $this->get(route('professional.inventory.receive'))->assertStatus(503);
        $this->postJson(route('professional.inventory.submit', 'receive'), ['quantity' => 2, 'stock_level_id' => $f['stock']->id, 'operation_token' => 'x'])->assertStatus(503);
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
    }

    public function test_html_filters_and_json_contract_remain_functional_and_do_not_invent_sale_references(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'], 'web');
        $this->get(route('professional.inventory.index', ['q' => 'missing']))->assertOk()->assertSee(__('inventory.empty', [], 'fr'))->assertDontSee('SKU-inventory');
        $this->get(route('professional.inventory.index', ['state' => 'ok', 'sort' => 'available']))->assertOk()->assertSee('SKU-inventory');
        $this->getJson(route('stock.show', $f['stock']))->assertOk()->assertJsonPath('physical_quantity', 10);
        StockMovement::create(['product_variant_id' => $f['variant']->id, 'quantity' => 1, 'type' => 'SALE', 'reason' => 'Recorded sale']);
        $this->get(route('professional.inventory.movements', ['type' => 'SALE']))->assertOk()->assertSee('Recorded sale')->assertDontSee('ORDER #');
    }

    public function test_manager_mutations_and_bilingual_render_fixtures(): void
    {
        $f = $this->fixture();
        $f['actor']->roles()->sync([Role::where('code', 'STORE_MANAGER')->firstOrFail()->id]);
        $this->submit($f, 'receive', $this->token($f, 'receive'))->assertRedirect()->assertSessionHasNoErrors();
        $reservation = app(CreateStockReservation::class)->execute($f['variant']->id, 2, now()->subMinute());
        foreach (['fr', 'en'] as $locale) {
            $f['actor']->forceFill(['preferred_locale' => $locale])->save();
            foreach (['index' => route('professional.inventory.index'), 'show' => route('professional.inventory.show', $f['stock']), 'receive' => route('professional.inventory.receive'), 'adjust' => route('professional.inventory.adjust', ['direction' => 'out']), 'reservations' => route('professional.inventory.reservations'), 'reservation' => route('professional.inventory.reservation', $reservation), 'movements' => route('professional.inventory.movements')] as $kind => $url) {
                $response = $this->get($url)->assertOk()->assertSee('lang="'.$locale.'"', false);
                if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                    $dir = storage_path('demo/phase20c2b-preview');
                    if (! is_dir($dir)) {
                        mkdir($dir, 0700, true);
                    }$html = str_replace(csrf_token(), 'REDACTED-CSRF', $response->getContent());
                    $html = preg_replace('/name="operation_token" value="[^"]*"/', 'name="operation_token" value="REDACTED-INTENT"', $html);
                    file_put_contents($dir.'/'.$kind.'-'.$locale.'.html', $html);
                }
            }
        }
    }

    public function test_failed_movement_rolls_back_stock_and_submission_together(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $this->mock(CreateStockMovement::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Simulated movement failure'));
        });
        try {
            app(StockOperationSubmission::class)->execute($f['actor'], $f['stock'], 'receive', 2, null, $token);
            $this->fail('A failed movement must abort the operation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated movement failure', $error->getMessage());
        }
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_operation_submissions', 1);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
    }

    public function test_numeric_boundaries_date_filter_and_cashier_read_access(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        foreach ([0, -1, '1.5', 4294967295] as $quantity) {
            $this->submit($f, 'receive', $token, ['quantity' => $quantity])->assertSessionHasErrors('quantity');
        }
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_operation_submissions', 1);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
        $this->get(route('professional.inventory.movements', ['to' => now()->format('Y-m-d')]))->assertOk()->assertSee('SKU-inventory');
        $f['actor']->roles()->sync([Role::where('code', 'CASHIER')->firstOrFail()->id]);
        $this->get(route('professional.inventory.index'))->assertForbidden();
        Role::where('code', 'CASHIER')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('code', 'inventory.view')->firstOrFail()->id]);
        $this->get(route('professional.inventory.index'))->assertOk();
        $this->get(route('professional.inventory.receive'))->assertForbidden();
        $this->submit($f, 'receive', $token)->assertForbidden();
    }

    public function test_database_unique_constraint_protects_submission_keys(): void
    {
        $f = $this->fixture();
        $this->submit($f, 'receive', $this->token($f, 'receive'))->assertRedirect();
        $row = (array) DB::table('stock_operation_submissions')->first();
        unset($row['id']);
        try {
            DB::table('stock_operation_submissions')->insert($row);
            $this->fail('Duplicate store submission keys must be refused by SQL.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('stock_operation_submissions', 1);
        }
        $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_partial_schemas_refuse_form_issuance_and_stock_writes(): void
    {
        $f = $this->fixture();
        $migration = require database_path('migrations/2026_10_25_023117_create_stock_operation_submissions_table.php');
        $mutations = [
            'missing unique index' => function (Blueprint $table): void {
                $table->dropUnique('stock_submission_store_key_unique');
            },
            'wrong unique columns' => function (Blueprint $table): void {
                $table->dropUnique('stock_submission_store_key_unique');
                $table->unique('key_hash');
            },
            'missing column' => function (Blueprint $table): void {
                $table->dropColumn('request_hash');
            },
            'nullable key' => function (Blueprint $table): void {
                $table->char('key_hash', 64)->nullable()->change();
            },
            'wrong hash type' => function (Blueprint $table): void {
                $table->integer('request_hash')->nullable()->change();
            },
            'missing foreign key' => function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
            },
            'cascading foreign key' => function (Blueprint $table): void {
                $table->dropForeign(['stock_level_id']);
                $table->foreign('stock_level_id')->references('id')->on('stock_levels')->cascadeOnDelete();
            },
            'legacy non-nullable stock' => function (Blueprint $table): void {
                $table->unsignedBigInteger('stock_level_id')->nullable(false)->change();
            },
        ];
        foreach ($mutations as $name => $mutate) {
            $migration->down();
            $migration->up();
            $token = $this->token($f, 'receive');
            $this->submit($f, 'receive', $token)->assertRedirect()->assertSessionHasNoErrors();
            $physical = $f['stock']->fresh()->physical_quantity;
            $movements = DB::table('stock_movements')->count();
            Schema::table('stock_operation_submissions', $mutate);
            $this->get(route('professional.inventory.receive'))->assertStatus(503);
            $this->submit($f, 'receive', $token)->assertStatus(503);
            $this->assertSame($physical, $f['stock']->fresh()->physical_quantity, $name);
            $this->assertSame($movements, DB::table('stock_movements')->count(), $name);
        }
    }

    public function test_schema_query_failure_is_controlled_and_never_writes_stock(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $interrupt = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$interrupt): void {
            if ($interrupt && str_contains($query, 'pragma_table_xinfo')) {
                throw new QueryException('sqlite', 'unavailable schema', [], new \RuntimeException('Synthetic metadata failure'));
            }
        });
        try {
            $this->submit($f, 'receive', $token)->assertStatus(503)->assertDontSee('Synthetic metadata failure');
        } finally {
            $interrupt = false;
        }
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
    }

    public function test_fresh_service_instances_share_issued_and_consumed_tokens(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'], 'web');
        $token = (new StockOperationSubmission)->issue($f['actor'], 'receive');
        $pending = DB::table('stock_operation_submissions')->first();
        $this->assertSame($f['actor']->id, $pending->user_id);
        $this->assertNull($pending->request_hash);
        $this->assertNull($pending->stock_level_id);
        $this->assertTrue((new StockOperationSubmission)->execute($f['actor'], $f['stock'], 'receive', 2, null, $token));
        $this->assertFalse((new StockOperationSubmission)->execute($f['actor'], $f['stock'], 'receive', 2, null, $token));
        $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_completion_failure_rolls_back_movement_and_keeps_the_token_unconsumed(): void
    {
        $f = $this->fixture();
        $token = $this->token($f, 'receive');
        $interrupt = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$interrupt): void {
            if ($interrupt && str_starts_with($query, 'update "stock_operation_submissions"')) {
                throw new QueryException('sqlite', 'unavailable ledger', [], new \RuntimeException('Synthetic completion failure'));
            }
        });
        try {
            $this->submit($f, 'receive', $token)->assertStatus(503)->assertDontSee('Synthetic completion failure');
        } finally {
            $interrupt = false;
        }
        $this->assertSame(10, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(0, DB::table('stock_operation_submissions')->whereNotNull('request_hash')->count());
        $this->submit($f, 'receive', $token)->assertRedirect()->assertSessionHasNoErrors();
        $this->submit($f, 'receive', $token)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(12, $f['stock']->fresh()->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 2);
    }
}
