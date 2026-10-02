<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreUserRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_belongs_to_a_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->assertTrue($user->store->is($store));
    }

    public function test_store_has_its_users(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->assertTrue(
        $store->users->contains($user)
    );
}

public function test_store_only_contains_its_own_users(): void
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

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    $this->assertTrue($storeA->users->contains($userA));

    $this->assertFalse($storeA->users->contains($userB));
}
}