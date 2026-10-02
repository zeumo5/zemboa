<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_uses_users_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $tenant = new TenantContext();

        $tenant->setFromUser($user);

        $this->assertSame($store->id, $tenant->storeId());
        $this->assertTrue($tenant->store()->is($store));
    }

    public function test_user_without_store_cannot_initialize_tenant_context(): void
{
    $user = User::factory()->create([
        'store_id' => null,
    ]);

    $tenant = new TenantContext();

    $this->expectException(\RuntimeException::class);

    $tenant->setFromUser($user);
}

public function test_uninitialized_tenant_context_cannot_return_store(): void
{
    $tenant = new TenantContext();

    $this->expectException(\RuntimeException::class);

    $tenant->store();
}
}