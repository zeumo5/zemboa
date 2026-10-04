<?php

namespace Tests\Feature;

use App\Actions\Category\CreateCategory;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_category_for_current_store(): void
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

        $category = app(CreateCategory::class)->execute([
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'description' => 'Toutes les chaussures',
            'status' => 'ACTIVE',
        ]);

        $this->assertSame('Chaussures', $category->name);
        $this->assertSame('chaussures', $category->slug);
        $this->assertSame($store->id, $category->store_id);

        $this->assertDatabaseHas('categories', [
            'store_id' => $store->id,
            'name' => 'Chaussures',
            'slug' => 'chaussures',
        ]);
    }
}