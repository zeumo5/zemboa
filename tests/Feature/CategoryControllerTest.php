<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_owner_can_create_category_in_own_store(): void
    {
        $this->seed();

        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $storeOwnerRole = Role::where('code', 'STORE_OWNER')->firstOrFail();

        $user->roles()->attach($storeOwnerRole);

        $response = $this
            ->actingAs($user)
            ->postJson(route('categories.store'), [
                'name' => 'Chaussures',
                'slug' => 'chaussures',
                'description' => 'Toutes les chaussures',
                'status' => 'ACTIVE',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Catégorie créée avec succès.')
            ->assertJsonPath('data.name', 'Chaussures')
            ->assertJsonPath('data.store_id', $store->id);

        $this->assertDatabaseHas('categories', [
            'store_id' => $store->id,
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_delivery_agent_cannot_create_category(): void
{
    $this->seed();

    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $deliveryRole = Role::where('code', 'DELIVERY_AGENT')->firstOrFail();

    $user->roles()->attach($deliveryRole);

    $response = $this
        ->actingAs($user)
        ->postJson(route('categories.store'), [
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'status' => 'ACTIVE',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('categories', [
        'store_id' => $store->id,
        'slug' => 'chaussures',
    ]);
}

public function test_store_owner_only_sees_categories_from_own_store(): void
{
    $this->seed();

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

    $user = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $storeOwnerRole = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($storeOwnerRole);

    // Catégorie appartenant à la Boutique A
    $categoryA = \App\Models\Category::withoutEvents(function () use ($storeA) {
    $category = new \App\Models\Category();
    $category->store_id = $storeA->id;
    $category->name = 'Chaussures';
    $category->slug = 'chaussures';
    $category->status = 'ACTIVE';
    $category->save();

    return $category;
});

    // On désactive l'événement tenant uniquement pour préparer
    // une donnée appartenant volontairement à la Boutique B.
    $categoryB = \App\Models\Category::withoutEvents(function () use ($storeB) {
        $category = new \App\Models\Category();
        $category->store_id = $storeB->id;
        $category->name = 'Téléphones';
        $category->slug = 'telephones';
        $category->status = 'ACTIVE';
        $category->save();

        return $category;
    });

    $response = $this
        ->actingAs($user)
        ->getJson(route('categories.index'));

    $response
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'Chaussures',
        ])
        ->assertJsonMissing([
            'name' => 'Téléphones',
        ]);
}

public function test_store_owner_can_update_category_from_own_store(): void
{
    $this->seed();

    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $storeOwnerRole = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($storeOwnerRole);

    app(\App\Support\TenantContext::class)->setFromUser($user);

    $category = \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson(route('categories.update', $category), [
            'name' => 'Chaussures et baskets',
            'slug' => 'chaussures-baskets',
            'description' => 'Nouvelle description',
            'status' => 'ACTIVE',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Catégorie modifiée avec succès.'
        )
        ->assertJsonPath('data.name', 'Chaussures et baskets');

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'store_id' => $store->id,
        'name' => 'Chaussures et baskets',
        'slug' => 'chaussures-baskets',
    ]);
}

public function test_store_owner_cannot_update_category_from_another_store(): void
{
    $this->seed();

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

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $storeOwnerRole = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $userA->roles()->attach($storeOwnerRole);

    // Fixture volontairement créée dans Boutique B.
    $categoryB = \App\Models\Category::withoutEvents(function () use ($storeB) {
        $category = new \App\Models\Category();
        $category->store_id = $storeB->id;
        $category->name = 'Téléphones';
        $category->slug = 'telephones';
        $category->status = 'ACTIVE';
        $category->save();

        return $category;
    });

    $response = $this
        ->actingAs($userA)
        ->putJson(route('categories.update', $categoryB->id), [
            'name' => 'Téléphones piratés',
            'slug' => 'telephones-pirates',
            'status' => 'ACTIVE',
        ]);

    $response->assertNotFound();

    $this->assertDatabaseHas('categories', [
        'id' => $categoryB->id,
        'store_id' => $storeB->id,
        'name' => 'Téléphones',
        'slug' => 'telephones',
    ]);
}
}