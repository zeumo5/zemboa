<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UpdateCategoryRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('tenant')
            ->put('/test/categories/{category}', function (
                UpdateCategoryRequest $request,
                Category $category
            ) {
                return response()->json($request->validated());
            });
    }

    public function test_category_can_keep_its_own_slug_when_updated(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'status' => 'ACTIVE',
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson('/test/categories/' . $category->id, [
                'name' => 'Chaussures et baskets',
                'slug' => 'chaussures',
                'description' => 'Nouvelle description',
                'status' => 'ACTIVE',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('slug', 'chaussures')
            ->assertJsonPath('name', 'Chaussures et baskets');
    }

    public function test_category_cannot_use_another_category_slug_from_same_store(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $categoryA = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson('/test/categories/' . $categoryA->id, [
            'name' => 'Chaussures',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
}

public function test_category_can_use_slug_that_exists_in_another_store(): void
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

    app(TenantContext::class)->setFromUser($userA);

    $categoryA = Category::create([
        'name' => 'Mode',
        'slug' => 'mode',
        'status' => 'ACTIVE',
    ]);

    // Fixture appartenant volontairement à une autre boutique.
    Category::withoutEvents(function () use ($storeB) {
        $category = new Category();
        $category->store_id = $storeB->id;
        $category->name = 'Chaussures';
        $category->slug = 'chaussures';
        $category->status = 'ACTIVE';
        $category->save();
    });

    $response = $this
        ->actingAs($userA)
        ->putJson('/test/categories/' . $categoryA->id, [
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'status' => 'ACTIVE',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('slug', 'chaussures');
}
}