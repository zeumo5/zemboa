<?php

namespace Tests\Feature;

use App\Actions\Category\UpdateCategory;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_category_without_changing_its_store(): void
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
            'description' => 'Ancienne description',
            'status' => 'ACTIVE',
        ]);

        $updatedCategory = app(UpdateCategory::class)->execute(
            $category,
            [
                'name' => 'Chaussures et baskets',
                'slug' => 'chaussures-baskets',
                'description' => 'Nouvelle description',
                'status' => 'INACTIVE',
            ]
        );

        $this->assertSame(
            'Chaussures et baskets',
            $updatedCategory->name
        );

        $this->assertSame(
            'chaussures-baskets',
            $updatedCategory->slug
        );

        $this->assertSame(
            'INACTIVE',
            $updatedCategory->status
        );

        $this->assertSame(
            $store->id,
            $updatedCategory->store_id
        );

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'store_id' => $store->id,
            'name' => 'Chaussures et baskets',
            'slug' => 'chaussures-baskets',
            'status' => 'INACTIVE',
        ]);
    }
}