<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplacePagesTest extends TestCase
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
            throw new \RuntimeException('SECURITE ZEMBOA : tests uniquement sur SQLite :memory:.');
        }

        $this->refreshIsolatedDatabase();
    }

    private function createPublicProduct(string $slug, string $status = 'ACTIVE'): array
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => $status,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'store_id' => $store->id,
            'name' => 'Maison',
            'slug' => 'maison-' . $slug,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'store_id' => $store->id,
            'category_id' => $categoryId,
            'name' => 'Produit ' . $slug,
            'slug' => 'produit-' . $slug,
            'status' => 'ACTIVE',
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_variants')->insert([
            'store_id' => $store->id,
            'product_id' => $productId,
            'sku' => 'SKU-' . $slug,
            'price' => '8500.00',
            'status' => 'ACTIVE',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$store, $productId];
    }

    public function test_homepage_shows_only_products_from_active_stores(): void
    {
        $this->createPublicProduct('active');
        $this->createPublicProduct('suspended', 'SUSPENDED');

        $this->get('/')
            ->assertOk()
            ->assertViewIs('marketplace.index')
            ->assertSee('Produit active')
            ->assertDontSee('Produit suspended');
    }

    public function test_store_and_product_pages_are_tenant_isolated(): void
    {
        [, $productAlpha] = $this->createPublicProduct('alpha');
        [, $productBeta] = $this->createPublicProduct('beta');

        $this->get('/boutiques/alpha')->assertOk()->assertSee('Produit alpha')->assertDontSee('Produit beta');
        $this->get('/boutiques/alpha/produits/' . $productAlpha)->assertOk()->assertSee('Produit alpha');
        $this->get('/boutiques/alpha/produits/' . $productBeta)->assertNotFound();
    }

    public function test_suspended_store_not_public_and_original_json_api_still_works(): void
    {
        $this->createPublicProduct('visible');
        $this->createPublicProduct('hidden', 'SUSPENDED');

        $this->get('/boutiques/hidden')->assertNotFound();
        $this->get('/boutiques/hidden/produits/1')->assertNotFound();
        $this->getJson('/shops/visible')->assertOk()->assertJsonPath('data.slug', 'visible');
    }
}
