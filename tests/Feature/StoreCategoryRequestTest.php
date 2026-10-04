<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\User;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StoreCategoryRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('tenant')
            ->post('/test/categories', function (StoreCategoryRequest $request) {
                return response()->json($request->validated());
            });
    }

    public function test_valid_category_data_is_accepted(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/test/categories', [
                'name' => 'Chaussures',
                'slug' => 'chaussures',
                'description' => 'Toutes les chaussures',
                'status' => 'ACTIVE',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'name' => 'Chaussures',
                'slug' => 'chaussures',
                'description' => 'Toutes les chaussures',
                'status' => 'ACTIVE',
            ]);
    }

    public function test_duplicate_slug_is_rejected_in_same_store(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->actingAs($user);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $response = $this->postJson('/test/categories', [
        'name' => 'Autres chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
}

public function test_same_slug_is_allowed_in_different_store(): void
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

    // Création de "chaussures" dans Boutique A
    app(\App\Support\TenantContext::class)
        ->setFromUser($userA);

    \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    // Boutique B envoie le même slug
    $response = $this
        ->actingAs($userB)
        ->postJson('/test/categories', [
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'status' => 'ACTIVE',
        ]);

    $response->assertOk();
}

public function test_invalid_category_data_is_rejected(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson('/test/categories', [
            'name' => '',
            'slug' => '',
            'status' => 'UNKNOWN',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'name',
            'slug',
            'status',
        ]);
}
}