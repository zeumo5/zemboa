<?php
namespace Tests\Feature;


use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshIsolatedDatabase;
    }

    public function refreshDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'SECURITE ZEMBOA : SQLite :memory: obligatoire.'
            );
        }

        $this->refreshIsolatedDatabase();
    }

    private function createShop(string $slug): array
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setFromStore($store);

        $category = Category::create([
            'name' => 'Catégorie ' . $slug,
            'slug' => 'categorie-' . $slug,
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit ' . $slug,
            'slug' => 'produit-' . $slug,
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-' . strtoupper($slug),
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        StockLevel::create([
            'product_variant_id' => $variant->id,
            'physical_quantity' => 10,
            'reserved_quantity' => 2,
            'low_stock_threshold' => 5,
        ]);

        return [$store, $category, $product, $variant];
    }

    public function test_visitor_can_access_public_catalog(): void
    {
        [$store, $category, $product] =
            $this->createShop('alpha');

        $this->getJson('/shops/alpha')
            ->assertOk()
            ->assertJsonPath('data.slug', 'alpha');

        $this->getJson('/shops/alpha/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/shops/alpha/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.variants.0.in_stock',
                true
            )
            ->assertJsonMissingPath(
                'data.0.variants.0.physical_quantity'
            );

        $this->getJson(
            '/shops/alpha/products/' . $product->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Produit alpha'
            );
    }

    public function test_store_cannot_expose_another_store_products(): void
    {
        [$storeA, $categoryA, $productA] =
            $this->createShop('alpha');

        [$storeB, $categoryB, $productB] =
            $this->createShop('beta');

        $this->getJson('/shops/alpha/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $productA->id
            );

        $this->getJson(
            '/shops/alpha/products/' . $productB->id
        )->assertNotFound();

        $this->getJson('/shops/beta/products')
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $productB->id
            );
    }

    public function test_inactive_store_is_not_public(): void
    {
        [$store] = $this->createShop('alpha');

        $store->update([
            'status' => 'INACTIVE',
        ]);

        $this->getJson('/shops/alpha')
            ->assertNotFound();

        $this->getJson('/shops/alpha/products')
            ->assertNotFound();
    }

    public function test_inactive_catalog_items_are_hidden(): void
    {
        [$store, $category, $product, $variant] =
            $this->createShop('alpha');

        $variant->update(['status' => 'INACTIVE']);

        $this->getJson('/shops/alpha/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $variant->update(['status' => 'ACTIVE']);
        $product->update(['status' => 'INACTIVE']);

        $this->getJson('/shops/alpha/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $product->update(['status' => 'ACTIVE']);
        $category->update(['status' => 'INACTIVE']);

        $this->getJson('/shops/alpha/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(
            '/shops/alpha/products/' . $product->id
        )->assertNotFound();
    }


public function test_public_catalog_search_and_category_filters(): void
{
    // Boutique Alpha avec son premier produit.
    $this->createShop('alpha');

    // Deuxième catégorie.
    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    // Deuxième produit.
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy',
        'slug' => 'samsung-galaxy',
        'status' => 'ACTIVE',
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'SAMSUNG-001',
        'price' => '150000.00',
        'is_default' => true,
        'status' => 'ACTIVE',
    ]);

    // Recherche par nom.
    $this->getJson('/shops/alpha/products?search=Samsung')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $product->id);

    // Filtrage par catégorie.
    $this->getJson('/shops/alpha/products?category=telephones')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $product->id);

    // Recherche et catégorie compatibles.
    $this->getJson(
        '/shops/alpha/products?search=Samsung&category=telephones'
    )
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Recherche et catégorie incompatibles.
    $this->getJson(
        '/shops/alpha/products?search=Samsung&category=categorie-alpha'
    )
        ->assertOk()
        ->assertJsonCount(0, 'data');
}

}
