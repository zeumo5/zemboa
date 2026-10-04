<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use App\Actions\ProductVariant\CreateProductVariant;
use Illuminate\Validation\ValidationException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_variant_belongs_to_product(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'NIKE-BLK-42',
            'price' => 45000,
            'status' => 'ACTIVE',
            'is_default' => true,
        ]);

        $this->assertTrue(
            $variant->product->is($product)
        );

        $this->assertSame(
            $store->id,
            $variant->store_id
        );

        $this->assertTrue(
            $product->variants->contains($variant)
        );
    }

    public function test_create_product_variant_rejects_product_from_another_store(): void
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

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    // Création du produit de la Boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Samsung Galaxy',
        'slug' => 'samsung-galaxy',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Maintenant Boutique A devient le tenant actif
    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    app(TenantContext::class)->setFromUser($userA);

    try {
        app(CreateProductVariant::class)->execute([
            'product_id' => $productB->id,
            'sku' => 'SAMSUNG-001',
            'price' => 150000,
            'status' => 'ACTIVE',
            'is_default' => true,
        ]);

        $this->fail(
            'CreateProductVariant aurait dû refuser un produit appartenant à une autre boutique.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'product_id',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('product_variants', [
        'store_id' => $storeA->id,
        'product_id' => $productB->id,
    ]);
}public function test_product_variant_queries_only_return_current_store_variants(): void
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
        'name' => 'Chaussures A',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productA = Product::create([
        'category_id' => $categoryA->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    ProductVariant::create([
        'product_id' => $productA->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    // Boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones B',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Samsung Galaxy',
        'slug' => 'samsung-galaxy',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    ProductVariant::create([
        'product_id' => $productB->id,
        'sku' => 'SAMSUNG-001',
        'price' => 150000,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    // Retour dans Boutique A
    app(TenantContext::class)->setFromUser($userA);

    $variants = ProductVariant::all();

    $this->assertCount(1, $variants);
    $this->assertSame('NIKE-001', $variants->first()->sku);
    $this->assertSame($storeA->id, $variants->first()->store_id);
}

public function test_same_variant_sku_is_allowed_in_different_stores(): void
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
        'name' => 'Chaussures A',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productA = Product::create([
        'category_id' => $categoryA->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variantA = ProductVariant::create([
        'product_id' => $productA->id,
        'sku' => 'SKU-001',
        'price' => 45000,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    // Boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Chaussures B',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Adidas Run',
        'slug' => 'adidas-run',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variantB = ProductVariant::create([
        'product_id' => $productB->id,
        'sku' => 'SKU-001',
        'price' => 50000,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $this->assertSame('SKU-001', $variantA->sku);
    $this->assertSame('SKU-001', $variantB->sku);

    $this->assertNotSame(
        $variantA->store_id,
        $variantB->store_id
    );
}

public function test_duplicate_variant_sku_is_rejected_in_same_store(): void
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
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $this->expectException(QueryException::class);

    ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 50000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);
}

public function test_create_product_variant_rejects_promo_price_greater_than_or_equal_to_price(): void
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

    $this->expectException(ValidationException::class);

    app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 50000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);
}

public function test_create_product_variant_rejects_promo_end_before_promo_start(): void
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

    $this->expectException(ValidationException::class);

    app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,

        // Incohérent volontairement
        'promo_starts_at' => '2026-10-20 00:00:00',
        'promo_ends_at' => '2026-10-10 00:00:00',

        'status' => 'ACTIVE',
        'is_default' => false,
    ]);
}

public function test_create_product_variant_rejects_negative_price(): void
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

    $this->expectException(ValidationException::class);

    app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => -5000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);
}

public function test_create_product_variant_rejects_negative_promo_price(): void
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

    $this->expectException(ValidationException::class);

    app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => -5000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);
}

public function test_effective_price_returns_normal_price_without_promotion(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => null,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $this->assertSame('45000.00', $variant->effectivePrice());
}

public function test_effective_price_returns_promo_price_when_promotion_has_no_dates(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $this->assertSame('39900.00', $variant->effectivePrice());
}

public function test_effective_price_returns_normal_price_before_promotion_starts(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'promo_starts_at' => '2026-10-10 00:00:00',
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $at = Carbon::parse('2026-10-09 12:00:00');

    $this->assertSame(
        '45000.00',
        $variant->effectivePrice($at)
    );
}

public function test_effective_price_returns_normal_price_after_promotion_ends(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'promo_starts_at' => '2026-10-10 00:00:00',
        'promo_ends_at' => '2026-10-20 23:59:59',
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $at = Carbon::parse('2026-10-21 12:00:00');

    $this->assertSame(
        '45000.00',
        $variant->effectivePrice($at)
    );
}

public function test_effective_price_returns_promo_price_during_promotion(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'promo_starts_at' => '2026-10-10 00:00:00',
        'promo_ends_at' => '2026-10-20 23:59:59',
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $at = Carbon::parse('2026-10-15 12:00:00');

    $this->assertSame(
        '39900.00',
        $variant->effectivePrice($at)
    );
}

public function test_effective_price_returns_promo_price_when_only_end_date_is_defined(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'promo_ends_at' => '2026-10-20 23:59:59',
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $at = Carbon::parse('2026-10-15 12:00:00');

    $this->assertSame(
        '39900.00',
        $variant->effectivePrice($at)
    );
}

public function test_effective_price_returns_promo_price_when_only_start_date_has_been_reached(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'promo_starts_at' => '2026-10-10 00:00:00',
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $at = Carbon::parse('2026-10-15 12:00:00');

    $this->assertSame(
        '39900.00',
        $variant->effectivePrice($at)
    );
}

public function test_effective_price_includes_promotion_start_and_end_boundaries(): void
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'promo_price' => 39900,
        'promo_starts_at' => '2026-10-10 00:00:00',
        'promo_ends_at' => '2026-10-20 23:59:59',
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $this->assertSame(
        '39900.00',
        $variant->effectivePrice(
            Carbon::parse('2026-10-10 00:00:00')
        )
    );

    $this->assertSame(
        '39900.00',
        $variant->effectivePrice(
            Carbon::parse('2026-10-20 23:59:59')
        )
    );
}

public function test_first_variant_becomes_default_automatically(): void
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
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone',
        'slug' => 'iphone',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'IPHONE-128',
        'price' => 500000,
        'status' => 'ACTIVE',
    ]);

    $this->assertTrue($variant->is_default);

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'product_id' => $product->id,
        'is_default' => true,
    ]);
}

public function test_new_default_variant_replaces_previous_default(): void
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
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone',
        'slug' => 'iphone',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $firstVariant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'IPHONE-128',
        'price' => 500000,
        'status' => 'ACTIVE',
    ]);

    $secondVariant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'IPHONE-256',
        'price' => 600000,
        'status' => 'ACTIVE',
        'is_default' => true,
    ]);

    $this->assertFalse(
        $firstVariant->fresh()->is_default
    );

    $this->assertTrue(
        $secondVariant->fresh()->is_default
    );

    $this->assertSame(
        1,
        ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_default', true)
            ->count()
    );
}

public function test_store_owner_can_create_variant_for_own_product(): void
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

    $role = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->post(
            route('product-variants.store', $product),
            [
                'sku' => 'IPH17-256',
                'price' => 650000,
                'status' => 'ACTIVE',
            ]
        );

    $response->assertRedirect(
        route('products.show', $product)
    );

    $this->assertDatabaseHas('product_variants', [
        'store_id' => $store->id,
        'product_id' => $product->id,
        'sku' => 'IPH17-256',
        'price' => 650000,
        'is_default' => true,
        'status' => 'ACTIVE',
    ]);
}

public function test_store_owner_cannot_create_variant_for_product_from_another_store(): void
{
    $this->seed();

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

    $role = Role::where('code', 'STORE_OWNER')->firstOrFail();

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userA->roles()->attach($role);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    // On crée le produit dans la boutique B.
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'Galaxy S26',
        'slug' => 'galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // La requête sera effectuée comme utilisateur de la boutique A.
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->post(
            route('product-variants.store', $productB),
            [
                'sku' => 'GALAXY-S26-256',
                'price' => 600000,
                'status' => 'ACTIVE',
            ]
        );

    $response->assertNotFound();

    $this->assertDatabaseMissing('product_variants', [
        'product_id' => $productB->id,
        'sku' => 'GALAXY-S26-256',
    ]);
}

public function test_user_without_products_update_permission_cannot_create_variant(): void
{
    $this->seed();

    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    // Utilisateur utilisé pour préparer les données de la boutique.
    $owner = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($owner);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Même boutique, mais aucun rôle/permission.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->post(
            route('product-variants.store', $product),
            [
                'sku' => 'IPH17-512',
                'price' => 750000,
                'status' => 'ACTIVE',
            ]
        );

    $response->assertForbidden();

    $this->assertDatabaseMissing('product_variants', [
        'product_id' => $product->id,
        'sku' => 'IPH17-512',
    ]);
}

public function test_cannot_create_variant_with_duplicate_sku_in_same_store(): void
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

    $role = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'IPH17-256',
        'price' => 650000,
        'is_default' => true,
        'status' => 'ACTIVE',
    ]);

    $response = $this
        ->actingAs($user)
        ->post(
            route('product-variants.store', $product),
            [
                'sku' => 'IPH17-256',
                'price' => 700000,
                'status' => 'ACTIVE',
            ]
        );

    $response->assertSessionHasErrors('sku');

    $this->assertSame(
        1,
        ProductVariant::query()
            ->where('sku', 'IPH17-256')
            ->count()
    );
}

public function test_cannot_create_variant_with_negative_price(): void
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

    $role = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->post(
            route('product-variants.store', $product),
            [
                'sku' => 'IPH17-NEG',
                'price' => -1000,
                'status' => 'ACTIVE',
            ]
        );

    $response->assertSessionHasErrors('price');

    $this->assertDatabaseMissing('product_variants', [
        'sku' => 'IPH17-NEG',
    ]);
}

public function test_cannot_create_variant_with_promo_price_greater_than_or_equal_to_price(): void
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

    $role = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->post(
            route('product-variants.store', $product),
            [
                'sku' => 'IPH17-PROMO',
                'price' => 650000,
                'promo_price' => 650000,
                'status' => 'ACTIVE',
            ]
        );

    $response->assertSessionHasErrors('promo_price');

    $this->assertDatabaseMissing('product_variants', [
        'sku' => 'IPH17-PROMO',
    ]);
}

public function test_cannot_create_variant_with_promo_end_before_promo_start(): void
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

    $role = Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->post(
            route('product-variants.store', $product),
            [
                'sku' => 'IPH17-DATE',
                'price' => 650000,
                'promo_price' => 600000,
                'promo_starts_at' => '2026-10-20 08:00:00',
                'promo_ends_at' => '2026-10-10 08:00:00',
                'status' => 'ACTIVE',
            ]
        );

    $response->assertSessionHasErrors('promo_ends_at');

    $this->assertDatabaseMissing('product_variants', [
        'sku' => 'IPH17-DATE',
    ]);
}
}