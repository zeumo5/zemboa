<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantTestModel extends Model
{
    use BelongsToStore;

    protected $table = 'tenant_test_models';

    protected $guarded = [];


    public static function createForTest(array $attributes): self
    {
        return static::withoutEvents(function () use ($attributes) {
            return static::withoutGlobalScopes()->create($attributes);
        });
    }
}

class BelongsToStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_test_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_tenant_model_belongs_to_a_store(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = \App\Models\User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $model = TenantTestModel::create([
        'name' => 'Donnée Alpha',
    ]);

    $this->assertSame($store->id, $model->store_id);
    $this->assertTrue($model->store()->is($store));
}

public function test_tenant_query_only_returns_current_store_data(): void
{
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

   TenantTestModel::createForTest([
        'store_id' => $storeA->id,
        'name' => 'Donnée Alpha',
    ]);

   TenantTestModel::createForTest([
        'store_id' => $storeB->id,
        'name' => 'Donnée Beta',
    ]);

    app(\App\Support\TenantContext::class)
        ->setFromUser(
            \App\Models\User::factory()->create([
                'store_id' => $storeA->id,
            ])
        );

    $models = TenantTestModel::all();

    $this->assertCount(1, $models);
    $this->assertSame('Donnée Alpha', $models->first()->name);
}

public function test_tenant_query_fails_when_no_tenant_is_initialized(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

   TenantTestModel::createForTest([
        'store_id' => $store->id,
        'name' => 'Donnée Alpha',
    ]);

    $this->expectException(\RuntimeException::class);

    TenantTestModel::all();
}

public function test_tenant_model_automatically_uses_current_store_when_created(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = \App\Models\User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $model = TenantTestModel::create([
        'name' => 'Donnée Alpha',
    ]);

    $this->assertSame($store->id, $model->store_id);
}

public function test_tenant_cannot_force_another_store_id_when_creating(): void
{
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $user = \App\Models\User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $model = TenantTestModel::create([
        'store_id' => $storeB->id, // tentative de forcer Boutique B
        'name' => 'Tentative',
    ]);

    $this->assertSame($storeA->id, $model->store_id);
    $this->assertNotSame($storeB->id, $model->store_id);
}
}