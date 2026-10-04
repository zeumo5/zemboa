<?php

namespace Tests\Feature;

use App\Actions\ProductImage\CreateProductImage;
use Illuminate\Validation\ValidationException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_has_images_and_image_belongs_to_product(): void
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
            'name' => 'Nike Air Max',
            'slug' => 'nike-air-max',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $image = ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/nike-air-max/front.webp',
            'alt_text' => 'Nike Air Max vue de face',
            'position' => 0,
            'is_primary' => true,
        ]);

        $this->assertTrue(
            $image->product->is($product)
        );

        $this->assertCount(
            1,
            $product->images
        );

        $this->assertTrue(
            $product->images->contains($image)
        );

        $this->assertSame(
            $store->id,
            $image->store_id
        );

        $this->assertTrue(
            $image->is_primary
        );

        $this->assertSame(
            0,
            $image->position
        );
    }

    public function test_create_product_image_rejects_product_from_another_store(): void
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

    // Produit appartenant à la Boutique B
    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Boutique A devient le tenant actif
    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    app(TenantContext::class)->setFromUser($userA);

    try {
        app(CreateProductImage::class)->execute([
            'product_id' => $productB->id,
            'path' => 'products/nike-air-max/front.webp',
            'position' => 0,
            'is_primary' => true,
        ]);

        $this->fail(
            'Une image ne doit pas pouvoir être créée pour un produit d’une autre boutique.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'product_id',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('product_images', [
        'store_id' => $storeA->id,
        'product_id' => $productB->id,
    ]);
}

public function test_product_cannot_have_two_primary_images(): void
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
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $action = app(CreateProductImage::class);

    // Première image principale : autorisée
    $action->execute([
        'product_id' => $product->id,
        'path' => 'products/nike-air-max/front.webp',
        'position' => 0,
        'is_primary' => true,
    ]);

    $this->expectException(ValidationException::class);

    // Deuxième image principale : doit être refusée
    $action->execute([
        'product_id' => $product->id,
        'path' => 'products/nike-air-max/side.webp',
        'position' => 1,
        'is_primary' => true,
    ]);
}
}