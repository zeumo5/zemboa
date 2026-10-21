<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Stock\ReceiveStock;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\DemoDatabase;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Mandatory even when invoked directly with artisan db:seed.
        DemoDatabase::assertIsolated();
        DemoDatabase::assertLocalPath(public_path('marketplace/demo'), 'public/marketplace/demo');
        $password = config('demo.owner_password', '');
        if (strlen($password) < 12
            || config('filesystems.disks.public.root') !== public_path('marketplace/demo')) {
            throw new RuntimeException('Demo requires a local password >= 12 characters and isolated media.');
        }
        $previousContext = app(TenantContext::class);
        $context = new TenantContext;
        app()->instance(TenantContext::class, $context);
        try {
            DB::transaction(function () use ($context, $password): void {
                $this->call([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
                foreach (require __DIR__.'/catalog.php' as $storeIndex => $definition) {
                    $store = Store::firstOrCreate(['slug' => $definition['slug']], [
                        'name' => $definition['name'], 'address' => $definition['city'].' - DEMO',
                        'description' => 'DEMO / Demonstration uniquement. Boutique fictive, aucun achat reel.',
                        'slogan' => 'DEMO / Demonstration only', 'status' => 'ACTIVE',
                        'email' => $definition['slug'].'@example.test',
                    ]);
                    if (! str_starts_with($store->name, 'DEMO - ')) {
                        throw new RuntimeException('Demo slug collides with a non-demo store.');
                    }
                    $context->setFromStore($store);
                    StoreSetting::firstOrCreate(['store_id' => $store->id], [
                        'default_locale' => $definition['locale'], 'primary_color' => '#0F6B57',
                        'delivery_enabled' => false, 'pickup_enabled' => true,
                        'allow_partial_payments' => false, 'notify_new_orders' => false,
                        'notify_low_stock' => false,
                    ]);
                    $owner = User::firstOrNew(['email' => $definition['slug'].'@example.test']);
                    if (! $owner->exists) {
                        $owner->forceFill([
                            'name' => 'DEMO Owner - '.$definition['city'], 'store_id' => $store->id,
                            'status' => 'ACTIVE', 'preferred_locale' => $definition['locale'],
                            'email_verified_at' => now(), 'password' => $password,
                        ])->save();
                    }
                    if ((int) $owner->store_id !== (int) $store->id) {
                        throw new RuntimeException('Demo owner belongs to another store.');
                    }
                    if ($owner->hasRole('SUPER_ADMIN')) {
                        throw new RuntimeException('Demo owner must not have the SUPER_ADMIN role.');
                    }
                    $owner->roles()->syncWithoutDetaching([Role::where('code', 'STORE_OWNER')->sole()->id]);
                    $attribute = Attribute::firstOrCreate(['slug' => 'demo-edition'], [
                        'name' => 'DEMO Edition', 'status' => 'ACTIVE',
                    ]);
                    foreach ($definition['categories'] as $categorySlug => [$categoryName, $products]) {
                        $category = Category::firstOrCreate(['slug' => $categorySlug], [
                            'name' => $categoryName, 'status' => 'ACTIVE',
                        ]);
                        foreach ($products as $productIndex => [$name, $price]) {
                            $slug = 'demo-'.Str::slug($name);
                            $product = Product::firstOrCreate(['slug' => $slug], [
                                'category_id' => $category->id, 'name' => 'DEMO - '.$name,
                                'description' => 'DEMO / Illustration et prix fictifs en FCFA. Not for sale.',
                                'status' => 'ACTIVE', 'is_featured' => $productIndex === 0,
                            ]);
                            if ((int) $product->category_id !== (int) $category->id) {
                                throw new RuntimeException('Demo product/category mismatch.');
                            }
                            foreach (['Standard', 'Plus'] as $editionIndex => $edition) {
                                $value = AttributeValue::firstOrCreate([
                                    'attribute_id' => $attribute->id, 'value' => $edition,
                                ]);
                                $variant = ProductVariant::firstOrCreate([
                                    'sku' => strtoupper($definition['slug'].'-'.$slug.'-'.$edition),
                                ], [
                                    'product_id' => $product->id, 'price' => $price + $editionIndex * 2000,
                                    'status' => 'ACTIVE', 'is_default' => $editionIndex === 0,
                                ]);
                                if ((int) $variant->product_id !== (int) $product->id) {
                                    throw new RuntimeException('Demo variant/product mismatch.');
                                }
                                $variant->attributeValues()->syncWithoutDetaching([$value->id]);
                                $stock = StockLevel::firstOrCreate(['product_variant_id' => $variant->id], [
                                    'physical_quantity' => 0, 'reserved_quantity' => 0, 'low_stock_threshold' => 5,
                                ]);
                                // Only receive initial stock once. Reruns never refill a sold/depleted item.
                                if ($stock->wasRecentlyCreated) {
                                    $quantity = [20, 3, 0, 12][$storeIndex];
                                    if ($quantity > 0) {
                                        app(ReceiveStock::class)->execute($variant->id, $quantity, $owner->id, 'DEMO initial stock');
                                    }
                                }
                            }
                            $path = $definition['slug'].'/'.$slug.'.svg';
                            $label = htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
                            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">'
                                .'<rect width="800" height="600" fill="#F8FAFC"/>'
                                .'<rect x="280" y="120" width="240" height="240" rx="48" fill="#0F6B57"/>'
                                .'<path d="M340 180h120v24l-80 72h80v24H340v-24l80-72h-80z" fill="#F8FAFC"/>'
                                .'<path d="M340 320h120" stroke="#F2D95C" stroke-width="8"/>'
                                .'<text x="400" y="430" text-anchor="middle" font-family="sans-serif" font-size="28" fill="#15202B">'.$label.'</text>'
                                .'<text x="400" y="490" text-anchor="middle" font-family="sans-serif" font-size="24" fill="#334155">DEMO / ILLUSTRATION - NOT FOR SALE</text></svg>';
                            DemoDatabase::assertLocalPath(public_path('marketplace/demo/'.$path), 'public/marketplace/demo/'.$path);
                            Storage::disk('public')->put($path, $svg);
                            ProductImage::firstOrCreate(['product_id' => $product->id, 'path' => $path], [
                                'alt_text' => 'DEMO - '.$name, 'position' => 0, 'is_primary' => true,
                            ]);
                        }
                    }
                }
            });
        } finally {
            app()->instance(TenantContext::class, $previousContext);
        }
    }
}
