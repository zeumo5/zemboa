<?php
namespace Tests\Feature;


use App\Actions\ProductVariant\CreateProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreLowStockThresholdTest extends TestCase
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
                'SQLite :memory: obligatoire pour les tests ZEMBOA.'
            );
        }

        $this->refreshIsolatedDatabase();
    }

    private function createVariant(?int $threshold): int
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

        if ($threshold !== null) {
            StoreSetting::create([
                'low_stock_threshold' => $threshold,
            ]);
        }

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Téléphone test',
            'slug' => 'telephone-test',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'STOCK-SETTINGS-001',
            'price' => '10000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        return $variant->stockLevel()
            ->firstOrFail()
            ->low_stock_threshold;
    }

    public function test_default_threshold_is_five(): void
    {
        $this->assertSame(5, $this->createVariant(null));
    }

    public function test_new_variant_uses_store_threshold(): void
    {
        $this->assertSame(12, $this->createVariant(12));
    }
}
