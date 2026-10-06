<?php

namespace Tests\Feature;


use App\Actions\Stock\CreateStockReservation;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Product\DeleteProduct;
use App\Models\Permission;
use App\Models\Role;
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

public function test_create_product_also_creates_default_variant(): void
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

    $product = app(CreateProduct::class)->execute([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'description' => 'Chaussure de sport',
        'status' => 'ACTIVE',
        'is_featured' => false,

        'default_variant' => [
            'price' => 45000,
            'sku' => 'NIKE-AM-001',
        ],
    ]);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'store_id' => $store->id,
        'slug' => 'nike-air-max',
    ]);

    $this->assertDatabaseHas('product_variants', [
        'store_id' => $store->id,
        'product_id' => $product->id,
        'sku' => 'NIKE-AM-001',
        'price' => 45000,
        'is_default' => true,
        'status' => 'ACTIVE',
    ]);

    $this->assertCount(1, $product->variants);

    $this->assertTrue(
        $product->variants->first()->is_default
    );
}

public function test_create_product_rejects_negative_default_variant_price(): void
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

    try {
        app(CreateProduct::class)->execute([
            'category_id' => $category->id,
            'name' => 'Produit Prix Invalide',
            'slug' => 'produit-prix-invalide',
            'status' => 'ACTIVE',
            'is_featured' => false,

            'default_variant' => [
                'price' => -5000,
                'sku' => 'INVALID-PRICE-001',
            ],
        ]);

        $this->fail(
            'CreateProduct aurait dû refuser un prix négatif pour la variante par défaut.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'price',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('products', [
        'slug' => 'produit-prix-invalide',
        'store_id' => $store->id,
    ]);

    $this->assertDatabaseMissing('product_variants', [
        'sku' => 'INVALID-PRICE-001',
        'store_id' => $store->id,
    ]);
}

public function test_create_product_generates_sku_when_missing(): void
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

    $product = app(CreateProduct::class)->execute([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,

        'default_variant' => [
            'price' => 45000,
        ],
    ]);

    $variant = $product->variants()->first();

    $this->assertNotNull($variant);

    $this->assertNotEmpty(
        $variant->sku
    );

    $this->assertTrue(
        $variant->is_default
    );

    $this->assertSame(
        $store->id,
        $variant->store_id
    );
}

public function test_product_creation_is_rolled_back_when_default_variant_creation_fails(): void
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

    // Premier produit : utilise ce SKU.
    app(CreateProduct::class)->execute([
        'category_id' => $category->id,
        'name' => 'Premier Produit',
        'slug' => 'premier-produit',
        'status' => 'ACTIVE',
        'is_featured' => false,

        'default_variant' => [
            'price' => 45000,
            'sku' => 'SKU-UNIQUE-001',
        ],
    ]);

    try {
        // Deuxième produit : même SKU → la variante doit échouer.
        app(CreateProduct::class)->execute([
            'category_id' => $category->id,
            'name' => 'Produit à annuler',
            'slug' => 'produit-a-annuler',
            'status' => 'ACTIVE',
            'is_featured' => false,

            'default_variant' => [
                'price' => 50000,
                'sku' => 'SKU-UNIQUE-001',
            ],
        ]);

        $this->fail(
            'La création de la variante aurait dû échouer à cause du SKU dupliqué.'
        );
    } catch (\Illuminate\Database\QueryException $exception) {
        // Échec attendu.
    }

    $this->assertDatabaseMissing('products', [
        'slug' => 'produit-a-annuler',
        'store_id' => $store->id,
    ]);

    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('product_variants', 1);
}

public function test_user_with_products_create_permission_can_create_product(): void
{
    $permission = Permission::create([
        'name' => 'Create products',
        'code' => 'products.create',
    ]);

    $role = Role::create([
        'name' => 'Store Owner',
        'code' => 'STORE_OWNER',
    ]);

    $role->permissions()->attach($permission);

    $user = User::factory()->create();

    $user->roles()->attach($role);

    $this->assertTrue(
        $user->can('create', Product::class)
    );
}

public function test_product_without_stock_history_can_be_deleted(): void
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

    $product = app(CreateProduct::class)->execute([
        'category_id' => $category->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
        'default_variant' => [
            'sku' => 'NIKE-AIR-001',
            'price' => '25000.00',
        ],
    ]);

    $productId = $product->id;
    $variantId = $product->variants()->firstOrFail()->id;

    app(DeleteProduct::class)->execute($product);

    $this->assertDatabaseMissing('products', [
        'id' => $productId,
    ]);

    $this->assertDatabaseMissing('product_variants', [
        'id' => $variantId,
    ]);

    $this->assertDatabaseMissing('stock_levels', [
        'product_variant_id' => $variantId,
    ]);
}

public function test_product_with_stock_movement_cannot_be_deleted(): void
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

    $product = app(CreateProduct::class)->execute([
        'category_id' => $category->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
        'default_variant' => [
            'sku' => 'NIKE-AIR-001',
            'price' => '25000.00',
        ],
    ]);

    $variant = $product->variants()->firstOrFail();

    app(ReceiveStock::class)->execute(
        $variant->id,
        10,
        $user->id,
        'Stock initial'
    );

    try {
        app(DeleteProduct::class)->execute($product);

        $this->fail(
            'La suppression aurait dû être refusée.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'product',
            $exception->errors()
        );
    }

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
    ]);

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
    ]);

    $this->assertDatabaseHas('stock_movements', [
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
        'quantity' => 10,
    ]);
}

public function test_product_with_stock_reservation_cannot_be_deleted(): void
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

    $product = app(CreateProduct::class)->execute([
        'category_id' => $category->id,
        'name' => 'Nike Air',
        'slug' => 'nike-air',
        'status' => 'ACTIVE',
        'is_featured' => false,
        'default_variant' => [
            'sku' => 'NIKE-AIR-001',
            'price' => '25000.00',
        ],
    ]);

    $variant = $product->variants()->firstOrFail();

    // Préparation du stock uniquement pour ce scénario de test.
    // On évite ReceiveStock car il créerait un StockMovement.
    $variant->stockLevel()->update([
        'physical_quantity' => 10,
    ]);

    app(CreateStockReservation::class)->execute(
        $variant->id,
        2,
        now()->addMinutes(30)
    );

    try {
        app(DeleteProduct::class)->execute($product);

        $this->fail(
            'La suppression aurait dû être refusée.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'product',
            $exception->errors()
        );
    }

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
    ]);

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
    ]);

    $this->assertDatabaseHas('stock_reservations', [
        'product_variant_id' => $variant->id,
        'quantity' => 2,
        'status' => 'ACTIVE',
    ]);
}

}