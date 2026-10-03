<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('categories'));

        $this->assertTrue(
            Schema::hasColumns('categories', [
                'id',
                'store_id',
                'name',
                'slug',
                'description',
                'status',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_category_is_automatically_assigned_to_current_store(): void
{
    $store = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = \App\Models\User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $category = \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'description' => 'Toutes les chaussures',
        'status' => 'ACTIVE',
    ]);

    $this->assertSame($store->id, $category->store_id);
    $this->assertTrue($category->store()->is($store));
}
public function test_category_queries_only_return_current_store_categories(): void
{
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

    $userA = \App\Models\User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    // Création d'une catégorie pour Boutique A
    app(\App\Support\TenantContext::class)
        ->setFromUser($userA);

    \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    // On crée directement une donnée de Boutique B
    // en dehors du comportement tenant, uniquement pour préparer le test.
    \App\Models\Category::withoutEvents(function () use ($storeB) {
    $category = new \App\Models\Category();

    $category->store_id = $storeB->id;
    $category->name = 'Téléphones';
    $category->slug = 'telephones';
    $category->status = 'ACTIVE';

    $category->save();
});

    $categories = \App\Models\Category::all();

    $this->assertCount(1, $categories);
    $this->assertSame('Chaussures', $categories->first()->name);
    $this->assertSame($storeA->id, $categories->first()->store_id);
}

public function test_same_slug_can_exist_in_different_stores(): void
{
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

    $userA = \App\Models\User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = \App\Models\User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    app(\App\Support\TenantContext::class)->setFromUser($userA);

    \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    app(\App\Support\TenantContext::class)->setFromUser($userB);

    $categoryB = \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $this->assertSame($storeB->id, $categoryB->store_id);
    $this->assertSame('chaussures', $categoryB->slug);
}

public function test_same_store_cannot_have_duplicate_category_slug(): void
{
    $store = \App\Models\Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = \App\Models\User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    \App\Models\Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $this->expectException(
        \Illuminate\Database\QueryException::class
    );

    \App\Models\Category::create([
        'name' => 'Autres chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);
}
}