<?php

namespace Tests\Feature;

use App\Actions\Product\CreateProduct;
use App\Actions\ProductImage\CreateProductImage;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\CatalogAccess;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProfessionalCatalogTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function shop(string $slug = 'catalogue'): array
    {
        $store = Store::create(['name' => 'Fictional '.$slug, 'slug' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['pickup_enabled' => true, 'delivery_enabled' => false]);
        $category = Category::create(['name' => 'Accessories '.$slug, 'slug' => 'accessories', 'status' => 'ACTIVE']);

        $category->refresh();

        return compact('store', 'category');
    }

    private function actor(Store $store, string $role = 'STORE_OWNER'): User
    {
        $user = User::factory()->create(['name' => 'Fictional merchant', 'email' => 'account-'.fake()->unique()->numerify('########').'@example.test', 'store_id' => $role === 'SUPER_ADMIN' ? null : $store->id, 'status' => 'ACTIVE', 'email_verified_at' => now(), 'is_buyer' => false, 'preferred_locale' => 'fr']);
        $user->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $user;
    }

    private function payload(Category $category, array $extra = []): array
    {
        return array_replace(['category_id' => $category->id, 'name' => 'Ceramic cup', 'slug' => 'ceramic-cup', 'description' => 'A fictional product', 'status' => 'ACTIVE', 'default_variant' => ['price' => '4500.00', 'sku' => 'CUP-001']], $extra);
    }

    private function product(array $shop, array $extra = []): Product
    {
        app(TenantContext::class)->setFromStore($shop['store']);

        return app(CreateProduct::class)->execute($this->payload($shop['category'], $extra))->refresh();
    }

    public function test_owner_creates_a_product_with_default_variant_and_zero_stock_without_changing_stock_from_input(): void
    {
        $s = $this->shop();
        $this->actingAs($this->actor($s['store']), 'web');
        $this->get(route('professional.products.create'))->assertOk()->assertDontSee('name="physical_quantity"', false);
        $this->post(route('professional.products.store'), $this->payload($s['category'], ['physical_quantity' => 999, 'reserved_quantity' => 999, 'store_id' => 999]))->assertSessionHasNoErrors()->assertRedirect();
        $product = Product::firstOrFail();
        $variant = $product->variants()->firstOrFail();
        $this->assertSame($s['store']->id, $product->store_id);
        $this->assertSame('4500.00', $variant->price);
        $this->assertTrue($variant->is_default);
        $this->assertSame(0, (int) $variant->stockLevel->physical_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->get(route('professional.products.edit', $product))->assertOk()->assertSee('CUP-001');
    }

    public function test_manager_can_manage_categories_and_non_pricing_product_information_but_cannot_delete_categories(): void
    {
        $s = $this->shop();
        $this->actingAs($this->actor($s['store'], 'STORE_MANAGER'), 'web');
        $this->post(route('professional.categories.store'), ['name' => 'Workspace', 'slug' => 'workspace', 'description' => 'Desk essentials', 'status' => 'ACTIVE', 'store_id' => 999])->assertSessionHasNoErrors()->assertRedirect();
        $category = Category::where('slug', 'workspace')->firstOrFail();
        $this->assertSame($s['store']->id, $category->store_id);
        $this->put(route('professional.categories.update', $category), ['name' => 'Office', 'slug' => 'workspace', 'status' => 'INACTIVE', 'version' => CatalogAccess::version($category)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Office', $category->fresh()->name);
        $product = $this->product($s);
        $this->put(route('professional.products.update', $product), $this->payload($category, ['name' => 'Updated cup', 'version' => CatalogAccess::version($product), 'physical_quantity' => 900]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->deleteJson(route('professional.categories.destroy', $category), ['version' => CatalogAccess::version($category->fresh())])->assertForbidden();
    }

    public function test_categories_with_products_cannot_be_deleted_and_empty_categories_can(): void
    {
        $s = $this->shop();
        $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        $this->deleteJson(route('professional.categories.destroy', $s['category']), ['version' => CatalogAccess::version($s['category'])])->assertUnprocessable()->assertJsonValidationErrors('category');
        $empty = Category::create(['name' => 'Empty', 'slug' => 'empty', 'status' => 'INACTIVE']);
        $this->delete(route('professional.categories.destroy', $empty), ['version' => CatalogAccess::version($empty->fresh())])->assertRedirect(route('professional.categories.index'));
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
        $this->assertDatabaseHas('categories', ['id' => $s['category']->id]);
    }

    public function test_foreign_products_variants_categories_and_images_are_invisible_on_html_and_json(): void
    {
        $a = $this->shop('alpha');
        $own = $this->product($a);
        $b = $this->shop('beta');
        $foreign = $this->product($b);
        $variant = $foreign->variants()->firstOrFail();
        $image = ProductImage::create(['product_id' => $foreign->id, 'path' => 'foreign.jpg', 'is_primary' => true]);
        $this->actingAs($this->actor($a['store']), 'web');
        $this->get(route('professional.products.edit', $foreign))->assertNotFound();
        $this->putJson(route('professional.products.update', $foreign), $this->payload($a['category']))->assertNotFound();
        $this->putJson(route('professional.variants.update', $variant), [])->assertNotFound();
        $this->putJson(route('professional.images.update', $image), [])->assertNotFound();
        $this->get(route('professional.categories.edit', $b['category']))->assertNotFound();
        $this->deleteJson(route('professional.categories.destroy', $b['category']), [])->assertNotFound();
        $this->getJson(route('products.show', $foreign))->assertNotFound();
        $this->get(route('professional.products.index', ['store_id' => $b['store']->id]))->assertOk()->assertDontSee('Fictional beta');
        $this->putJson(route('professional.products.update', $own), $this->payload($b['category'], ['version' => CatalogAccess::version($own)]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_invalid_prices_duplicate_skus_and_slugs_are_refused_without_partial_products(): void
    {
        $s = $this->shop();
        $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        $this->postJson(route('professional.products.store'), $this->payload($s['category']))->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson(route('professional.products.store'), $this->payload($s['category'], ['slug' => 'other']))->assertUnprocessable()->assertJsonValidationErrors('default_variant.sku');
        foreach (['-1', '10000000000', '1.234'] as $price) {
            $this->postJson(route('professional.products.store'), $this->payload($s['category'], ['slug' => 'other', 'default_variant' => ['price' => $price, 'sku' => 'NEW']]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_variant_mutations_preserve_stock_and_refuse_stale_forms_and_duplicate_skus(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $variant = $product->variants()->firstOrFail();
        $variant->stockLevel->forceFill(['physical_quantity' => 12, 'reserved_quantity' => 2])->save();
        $this->actingAs($this->actor($s['store']), 'web');
        $old = CatalogAccess::version($variant);
        $payload = ['sku' => 'CUP-001', 'price' => '5000.00', 'status' => 'ACTIVE', 'is_default' => 1, 'version' => $old, 'physical_quantity' => 777, 'reserved_quantity' => 777];
        $this->put(route('professional.variants.update', $variant), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->putJson(route('professional.variants.update', $variant), $payload)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->post(route('professional.variants.store', $product), ['sku' => 'CUP-PLUS', 'price' => '6500', 'status' => 'ACTIVE', 'is_default' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->postJson(route('professional.variants.store', $product), ['sku' => 'CUP-PLUS', 'price' => '6500', 'status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertFalse($variant->fresh()->is_default);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
        $this->assertSame(12, (int) $variant->stockLevel->fresh()->physical_quantity);
        $this->assertSame(2, (int) $variant->stockLevel->fresh()->reserved_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_stale_product_and_category_forms_do_not_overwrite_newer_changes(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        $version = CatalogAccess::version($product);
        $this->put(route('professional.products.update', $product), $this->payload($s['category'], ['name' => 'First edit', 'version' => $version]))->assertRedirect();
        $this->putJson(route('professional.products.update', $product), $this->payload($s['category'], ['name' => 'Obsolete edit', 'version' => $version]))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('First edit', $product->fresh()->name);
        $this->from(route('professional.products.edit', $product))->put(route('professional.products.update', $product), $this->payload($s['category'], ['name' => 'Obsolete edit', 'version' => $version, '_form' => 'product']))
            ->assertRedirect()->assertSessionHasErrors('version');
        $this->get(route('professional.products.edit', $product))->assertOk()->assertSee('name="version" value="'.$version.'"', false);
        $category = $s['category'];
        $old = CatalogAccess::version($category);
        $category->update(['description' => 'Changed elsewhere']);
        $this->putJson(route('professional.categories.update', $category), ['name' => 'Obsolete', 'slug' => 'accessories', 'status' => 'ACTIVE', 'version' => $old])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_raster_uploads_use_owned_paths_and_primary_image_changes_are_versioned(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        $this->post(route('professional.images.store', $product), ['image' => UploadedFile::fake()->image('photo.jpg', 600, 600), 'alt_text' => 'Cup photo', 'path' => '../outside.php', 'store_id' => 999])->assertSessionHasNoErrors()->assertRedirect();
        $image = $product->images()->firstOrFail();
        $this->assertStringStartsWith('stores/'.$s['store']->id.'/products/'.$product->id.'/', $image->path);
        Storage::disk('public')->assertExists($image->path);
        $this->assertTrue($image->is_primary);
        $this->post(route('professional.images.store', $product), ['image' => UploadedFile::fake()->image('photo.png', 600, 600), 'is_primary' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $image = $image->fresh();
        $old = CatalogAccess::version($image);
        $data = ['alt_text' => 'Updated', 'position' => 0, 'is_primary' => 1, 'version' => $old];
        $this->put(route('professional.images.update', $image), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->putJson(route('professional.images.update', $image), $data)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertTrue($image->fresh()->is_primary);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_unsafe_oversized_or_misnamed_uploads_are_refused_without_files_or_rows(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        foreach ([UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), UploadedFile::fake()->create('image.jpg', 6000, 'image/jpeg'), UploadedFile::fake()->image('image.php', 100, 100), UploadedFile::fake()->image('large.png', 4097, 17)] as $file) {
            $this->postJson(route('professional.images.store', $product), ['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unqualified_identities_and_inactive_stores_cannot_manage_catalogue(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        foreach (['CASHIER', 'DELIVERY_AGENT', 'SUPER_ADMIN'] as $role) {
            $this->actingAs($this->actor($s['store'], $role), 'web');
            $this->postJson(route('professional.products.store'), $this->payload($s['category'], ['slug' => 'another']))->assertForbidden();
        }
        $owner = $this->actor($s['store']);
        $owner->roles()->attach(Role::where('code', 'CASHIER')->firstOrFail());
        $this->actingAs($owner, 'web');
        $this->get(route('professional.products.index'))->assertForbidden();
        $owner->roles()->detach(Role::where('code', 'CASHIER')->firstOrFail());
        $owner->forceFill(['status' => 'INACTIVE'])->save();
        $this->get(route('professional.products.index'))->assertForbidden();
        $owner->forceFill(['status' => 'ACTIVE'])->save();
        $s['store']->update(['status' => 'SUSPENDED']);
        $this->get(route('professional.products.index'))->assertForbidden();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_html_and_existing_json_catalogues_keep_publication_rules(): void
    {
        $s = $this->shop();
        $active = $this->product($s);
        $hidden = $this->product($s, ['name' => 'Hidden product', 'slug' => 'hidden', 'status' => 'INACTIVE', 'default_variant' => ['price' => '2000', 'sku' => 'HIDDEN']]);
        $this->actingAs($this->actor($s['store']), 'web');
        $this->getJson(route('categories.index'))->assertOk()->assertJsonPath('data.0.id', $s['category']->id);
        $this->getJson(route('products.show', $active))->assertOk()->assertJsonPath('id', $active->id);
        $this->get(route('professional.products.index', ['q' => 'HIDDEN']))->assertOk()->assertSee('Hidden product')->assertDontSee('Ceramic cup');
        auth('web')->logout();
        $this->get('/produits')->assertOk()->assertSee('Ceramic cup')->assertDontSee('Hidden product');
        $this->get('/boutiques/'.$s['store']->slug.'/produits/'.$hidden->id)->assertNotFound();
    }

    public function test_bilingual_pages_and_render_fixtures_for_isolated_chrome(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $actor = $this->actor($s['store']);
        $this->actingAs($actor, 'web');
        Category::create(['name' => 'Unassigned', 'slug' => 'unassigned', 'status' => 'INACTIVE']);
        foreach (['fr', 'en'] as $locale) {
            $actor->forceFill(['preferred_locale' => $locale])->save();
            foreach (['products' => route('professional.products.index'), 'create' => route('professional.products.create'), 'edit' => route('professional.products.edit', $product), 'categories' => route('professional.categories.index'), 'category' => route('professional.categories.edit', $s['category'])] as $kind => $url) {
                $response = $this->get($url)->assertOk()->assertSee('lang="'.$locale.'"', false);
                if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                    $dir = storage_path('demo/phase20c2a-preview');
                    if (! is_dir($dir)) {
                        mkdir($dir, 0700, true);
                    }file_put_contents($dir.'/'.$kind.'-'.$locale.'.html', str_replace(csrf_token(), 'REDACTED-CSRF', $response->getContent()));
                }
            }
        }
    }

    public function test_product_list_query_count_does_not_grow_per_product(): void
    {
        $s = $this->shop();
        $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        DB::enableQueryLog();
        $this->get(route('professional.products.index'))->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 1; $i <= 20; $i++) {
            $this->product($s, ['name' => 'Cup '.$i, 'slug' => 'cup-'.$i, 'default_variant' => ['price' => '3000', 'sku' => 'CUP-'.$i]]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('professional.products.index'))->assertOk()->assertSee('page=2', false);
        $this->assertLessThanOrEqual($baseline + 3, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_failed_image_registration_cleans_up_the_new_file(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $this->actingAs($this->actor($s['store']), 'web');
        $this->mock(CreateProductImage::class)->shouldReceive('execute')->once()
            ->andThrow(ValidationException::withMessages(['image' => 'Fictional registration failure']));
        $this->postJson(route('professional.images.store', $product), ['image' => UploadedFile::fake()->image('photo.jpg', 100, 100)])->assertUnprocessable();
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_cashier_with_read_permission_has_no_product_mutation_controls_and_cannot_upload(): void
    {
        $s = $this->shop();
        $product = $this->product($s);
        $cashier = $this->actor($s['store'], 'CASHIER');
        $permission = Permission::where('code', 'products.view')->firstOrFail();
        $cashier->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        $this->actingAs($cashier, 'web');
        $this->get(route('professional.products.index'))->assertOk();
        $this->get(route('professional.products.edit', $product))->assertOk()->assertDontSee('data-catalog-upload', false)->assertDontSee('name="price"', false);
        $this->putJson(route('professional.products.update', $product), $this->payload($s['category']))->assertForbidden();
        $this->postJson(route('professional.images.store', $product), ['image' => UploadedFile::fake()->image('photo.jpg', 100, 100)])->assertForbidden();
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_revoked_permissions_and_a_buyer_session_do_not_authorise_catalogue_access(): void
    {
        $s = $this->shop();
        $owner = $this->actor($s['store']);
        $owner->roles()->firstOrFail()->permissions()->detach();
        $this->actingAs($owner, 'web');
        $this->get(route('professional.products.index'))->assertForbidden();
        $this->get(route('professional.categories.index'))->assertForbidden();
        auth('web')->logout();
        $buyer = User::factory()->create(['store_id' => null, 'is_buyer' => true, 'status' => 'ACTIVE']);
        $this->actingAs($buyer, 'buyer');
        $this->getJson(route('professional.products.index'))->assertForbidden();
    }

    public function test_database_unique_conflicts_are_reported_as_validation_errors_without_sql_details(): void
    {
        $s = $this->shop();
        try {
            CatalogAccess::persist(fn () => DB::transaction(fn () => DB::table('categories')->insert(['store_id' => $s['store']->id, 'name' => 'Duplicate', 'slug' => $s['category']->slug, 'status' => 'ACTIVE'])));
            $this->fail('A unique constraint conflict must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('catalog', $exception->errors());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_category_versions_ignore_display_counts_and_business_errors_are_localised(): void
    {
        $s = $this->shop();
        $this->product($s);
        $category = Category::withCount('products')->firstOrFail();
        $this->assertSame(CatalogAccess::version($s['category']->fresh()), CatalogAccess::version($category));
        $actor = $this->actor($s['store']);
        $actor->forceFill(['preferred_locale' => 'en'])->save();
        $this->actingAs($actor, 'web');
        $this->deleteJson(route('professional.categories.destroy', $category), ['version' => CatalogAccess::version($category)])
            ->assertUnprocessable()->assertJsonPath('errors.category.0', __('catalog.contains_products', [], 'en'));
    }
}
