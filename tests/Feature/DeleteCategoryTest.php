<?php

namespace Tests\Feature;

use App\Actions\Category\DeleteCategory;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_category_from_current_store(): void
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

        $categoryId = $category->id;

        app(DeleteCategory::class)->execute($category);

        $this->assertDatabaseMissing('categories', [
            'id' => $categoryId,
            'store_id' => $store->id,
        ]);
    }
}