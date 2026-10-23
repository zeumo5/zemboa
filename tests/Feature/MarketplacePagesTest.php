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

    public function test_distinct_merchant_pages_are_public_and_use_the_merchant_layout(): void
    {
        $this->createPublicProduct('alpha');
        foreach (['' => 'marketplace.shop', '/produits' => 'marketplace.merchant.catalog',
            '/categories' => 'marketplace.merchant.categories', '/a-propos' => 'marketplace.merchant.about',
            '/livraison-retrait' => 'marketplace.merchant.services'] as $suffix => $view) {
            $this->get('/boutiques/alpha'.$suffix)->assertOk()->assertViewIs($view)
                ->assertSee('merchant-sidebar')->assertSee('merchant-footer')
                ->assertDontSee('zm-nav-login')->assertDontSee('class="zm-header"', false);
        }
    }

    public function test_merchant_catalogue_filters_are_isolated_and_shareable(): void
    {
        $this->createPublicProduct('alpha');
        $this->createPublicProduct('beta');
        $this->get('/boutiques/alpha/produits?category=maison-alpha&q=alpha')
            ->assertOk()->assertSee('Produit alpha')->assertDontSee('Produit beta');
        $this->get('/boutiques/alpha/produits?category=maison-beta')->assertNotFound();
        $this->get('/boutiques/alpha/produits?q=absent')->assertOk()->assertDontSee('Produit alpha');
        $this->get('/boutiques/alpha/categories')->assertOk()->assertSee('maison-alpha')->assertDontSee('maison-beta');
    }

    public function test_product_and_inactive_pages_preserve_publication_rules(): void
    {
        [, $product] = $this->createPublicProduct('alpha');
        $this->createPublicProduct('hidden', 'INACTIVE');
        $this->get('/boutiques/alpha/produits/'.$product)->assertOk()
            ->assertSee('merchant-footer')->assertDontSee('zm-nav-login');
        foreach (['', '/produits', '/categories', '/a-propos', '/livraison-retrait'] as $suffix) {
            $this->get('/boutiques/hidden'.$suffix)->assertNotFound();
        }
    }

    public function test_merchant_identity_settings_and_languages_do_not_leak(): void
    {
        [$alpha] = $this->createPublicProduct('alpha');
        [$beta] = $this->createPublicProduct('beta');
        foreach ([[$alpha, '#542040', 'alpha-banner.jpg'], [$beta, '#174860', 'beta-banner.jpg']] as [$store, $color, $banner]) {
            DB::table('store_settings')->insert([
                'store_id' => $store->id, 'primary_color' => $color, 'banner_path' => $banner,
                'delivery_enabled' => false, 'pickup_enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->withSession(['locale' => 'en'])->get('/boutiques/alpha')->assertOk()
            ->assertSee('#542040')->assertSee('alpha-banner.jpg')->assertSee('Powered by ZEMBOA')
            ->assertDontSee('beta-banner.jpg')->assertDontSee('Boutique beta');
        $this->withSession(['locale' => 'fr'])->get('/boutiques/beta')->assertOk()
            ->assertSee('#174860')->assertSee('beta-banner.jpg')->assertSee('Propulsé par ZEMBOA')
            ->assertDontSee('alpha-banner.jpg');
        $this->withSession(['locale' => 'en'])->get('/boutiques/alpha/livraison-retrait')->assertOk()
            ->assertSee('Store pickup offered')->assertDontSee('Delivery offered');
    }

    public function test_category_counts_match_public_catalogue_without_tenant_leaks_or_pagination(): void
    {
        [$store, $productId] = $this->createPublicProduct('category-counts');
        [, $foreignId] = $this->createPublicProduct('other-category-store');
        $product = (array) DB::table('products')->find($productId);
        $categoryId = $product['category_id'];
        unset($product['id']);
        $variant = (array) DB::table('product_variants')->where('product_id', $productId)->first();
        unset($variant['id']);
        for ($i = 0; $i < 13; $i++) {
            $copyId = DB::table('products')->insertGetId(array_replace($product, ['name' => 'Public '.$i, 'slug' => 'public-'.$i]));
            DB::table('product_variants')->insert(array_replace($variant, ['product_id' => $copyId, 'sku' => 'PUBLIC-'.$i]));
        }
        // Extra variants must not inflate the number of products.
        DB::table('product_variants')->insert(array_replace($variant, ['sku' => 'SECOND-VARIANT', 'is_default' => false]));
        $inactiveId = DB::table('products')->insertGetId(array_replace($product, ['name' => 'Hidden product', 'slug' => 'hidden-product', 'status' => 'INACTIVE']));
        DB::table('product_variants')->insert(array_replace($variant, ['product_id' => $inactiveId, 'sku' => 'HIDDEN-PRODUCT']));
        DB::table('products')->insert(array_replace($product, ['name' => 'No public variants', 'slug' => 'no-variants']));
        DB::table('products')->where('id', $foreignId)->update(['category_id' => $categoryId]);
        DB::table('categories')->insert([
            ['store_id' => $store->id, 'name' => 'Empty collection', 'slug' => 'empty-collection', 'status' => 'ACTIVE'],
            ['store_id' => $store->id, 'name' => 'Hidden collection', 'slug' => 'hidden-collection', 'status' => 'INACTIVE'],
        ]);

        $this->get('/boutiques/category-counts/categories')
            ->assertOk()->assertViewHas('categories', function ($categories) use ($categoryId) {
                return $categories->count() === 2
                    && $categories->firstWhere('id', $categoryId)->public_products_count === 14
                    && $categories->firstWhere('slug', 'empty-collection')->public_products_count === 0;
            })
            ->assertSee('premium-category-card')->assertSee('empty-collection')
            ->assertDontSee('hidden-collection')->assertDontSee('Produit other-category-store')
            ->assertDontSee('FCFA')->assertDontSee('data-cart-options');
    }

    public function test_category_page_handles_no_active_categories(): void
    {
        [$store] = $this->createPublicProduct('no-collections');
        DB::table('categories')->where('store_id', $store->id)->update(['status' => 'INACTIVE']);
        $this->get('/boutiques/no-collections/categories')->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->isEmpty())
            ->assertSee('categories-empty')->assertDontSee('premium-category-card');
    }
}
