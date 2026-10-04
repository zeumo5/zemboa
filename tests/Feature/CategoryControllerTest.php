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
}