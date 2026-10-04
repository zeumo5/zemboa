<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CategoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_owner_can_create_category(): void
    {
        $this->seed();

        $user = User::factory()->create();

        $storeOwnerRole = \App\Models\Role::where(
            'code',
            'STORE_OWNER'
        )->firstOrFail();

        $user->roles()->attach($storeOwnerRole);

        $this->assertTrue(
            Gate::forUser($user)->allows('create', Category::class)
        );
    } 

    public function test_delivery_agent_cannot_create_category(): void
{
    $this->seed();

    $user = User::factory()->create();

    $deliveryAgentRole = \App\Models\Role::where(
        'code',
        'DELIVERY_AGENT'
    )->firstOrFail();

    $user->roles()->attach($deliveryAgentRole);

    $this->assertFalse(
        Gate::forUser($user)->allows('create', Category::class)
    );
}

public function test_store_owner_can_view_categories(): void
{
    $this->seed();

    $user = User::factory()->create();

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    $this->assertTrue(
        Gate::forUser($user)->allows('viewAny', Category::class)
    );
}

public function test_store_owner_can_view_category_from_own_store(): void
{
    $this->seed();

    $store = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $this->assertTrue(
        Gate::forUser($user)->allows('view', $category)
    );
}

public function test_store_owner_cannot_view_category_from_another_store(): void
{
    $this->seed();

    $storeA = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = \App\Models\Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    // On prépare volontairement une catégorie appartenant à Boutique B.
    $category = Category::withoutEvents(function () use ($storeB) {
        $category = new Category();

        $category->store_id = $storeB->id;
        $category->name = 'Téléphones';
        $category->slug = 'telephones';
        $category->status = 'ACTIVE';

        $category->save();

        return $category;
    });

    $this->assertFalse(
        Gate::forUser($user)->allows('view', $category)
    );
}

public function test_store_owner_can_update_category_from_own_store(): void
{
    $this->seed();

    $store = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $this->assertTrue(
        Gate::forUser($user)->allows('update', $category)
    );
}

public function test_store_owner_cannot_update_category_from_another_store(): void
{
    $this->seed();

    $storeA = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = \App\Models\Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    $category = Category::withoutEvents(function () use ($storeB) {
        $category = new Category();

        $category->store_id = $storeB->id;
        $category->name = 'Téléphones';
        $category->slug = 'telephones';
        $category->status = 'ACTIVE';

        $category->save();

        return $category;
    });

    $this->assertFalse(
        Gate::forUser($user)->allows('update', $category)
    );
}

public function test_store_owner_can_delete_category_from_own_store(): void
{
    $this->seed();

    $store = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $this->assertTrue(
        Gate::forUser($user)->allows('delete', $category)
    );
} 

public function test_store_owner_cannot_delete_category_from_another_store(): void
{
    $this->seed();

    $storeA = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = \App\Models\Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $storeOwnerRole = \App\Models\Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    $category = Category::withoutEvents(function () use ($storeB) {
        $category = new Category();

        $category->store_id = $storeB->id;
        $category->name = 'Téléphones';
        $category->slug = 'telephones';
        $category->status = 'ACTIVE';

        $category->save();

        return $category;
    });

    $this->assertFalse(
        Gate::forUser($user)->allows('delete', $category)
    );
}
}