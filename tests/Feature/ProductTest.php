<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use App\Actions\Product\CreateProduct;
use Illuminate\Validation\ValidationException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_belongs_to_category(): void
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

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Nike Air',
            'slug' => 'nike-air',
            'description' => 'Chaussure de sport',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $this->assertTrue(
            $product->category->is($category)
        );

        $this->assertSame(
            $store->id,
            $product->store_id
        );
    }

    public function test_category_has_many_products(): void
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

    Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    Product::create([
        'category_id' => $category->id,
        'name' => 'Adidas Run',
        'slug' => 'adidas-run',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $this->assertCount(2, $category->products);

    $this->assertTrue(
        $category->products->contains('slug', 'nike-air')
    );

    $this->assertTrue(
        $category->products->contains('slug', 'adidas-run')
    );
}

public function test_create_product_rejects_category_from_another_store(): void
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

    $categoryB = Category::withoutEvents(function () use ($storeB) {
        $category = new Category();
        $category->store_id = $storeB->id;
        $category->name = 'Téléphones';
        $category->slug = 'telephones';
        $category->status = 'ACTIVE';
        $category->save();

        return $category;
    });

    app(TenantContext::class)->setFromUser($userA);

    try {
        app(CreateProduct::class)->execute([
            'category_id' => $categoryB->id,
            'name' => 'iPhone',
            'slug' => 'iphone',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $this->fail(
            'CreateProduct aurait dû refuser une catégorie appartenant à une autre boutique.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'category_id',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('products', [
        'store_id' => $storeA->id,
        'category_id' => $categoryB->id,
    ]);
}

public function test_product_queries_only_return_current_store_products(): void
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

    // Boutique A
    app(TenantContext::class)->setFromUser($userA);

    $categoryA = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    Product::create([
        'category_id' => $categoryA->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Samsung Galaxy',
        'slug' => 'samsung-galaxy',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // On revient dans la boutique A
    app(TenantContext::class)->setFromUser($userA);

    $products = Product::all();

    $this->assertCount(1, $products);
    $this->assertSame('Nike Air', $products->first()->name);
    $this->assertSame($storeA->id, $products->first()->store_id);
}

public function test_same_product_slug_is_allowed_in_different_stores(): void
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

    // Boutique A
    app(TenantContext::class)->setFromUser($userA);

    $categoryA = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productA = Product::create([
        'category_id' => $categoryA->id,
        'name' => 'Nike Air Boutique A',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Nike Air Boutique B',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $this->assertSame('nike-air', $productA->slug);
    $this->assertSame('nike-air', $productB->slug);

    $this->assertNotSame(
        $productA->store_id,
        $productB->store_id
    );
}

public function test_duplicate_product_slug_is_rejected_in_same_store(): void
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

    Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air 1',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $this->expectException(QueryException::class);

    Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air 2',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);
}
}