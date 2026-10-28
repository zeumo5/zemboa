<?php

namespace Tests\Feature;

use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\ProductVariant\UpdateProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\CatalogAccess;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PromotionManagementTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
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
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(10, 0));
    }

    private function variant(string $slug = 'offers', array $data = []): ProductVariant
    {
        $store = Store::create(['name' => 'Fictional '.$slug, 'slug' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['pickup_enabled' => true]);
        $category = Category::create(['name' => 'Accessories', 'slug' => 'accessories', 'status' => 'ACTIVE']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Fictional speaker', 'slug' => 'speaker', 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(array_replace(['product_id' => $product->id, 'sku' => 'SPEAKER-TEST',
            'price' => '100000.00', 'promo_price' => '85000.00', 'status' => 'ACTIVE', 'is_default' => true], $data));
        StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 2]);

        return $variant->refresh();
    }

    private function actor(ProductVariant $variant, string $role = 'STORE_OWNER'): User
    {
        $user = User::factory()->create(['store_id' => $role === 'SUPER_ADMIN' ? null : $variant->store_id,
            'status' => 'ACTIVE', 'is_buyer' => false, 'email_verified_at' => now(), 'preferred_locale' => 'fr']);
        $user->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $user;
    }

    private function form(ProductVariant $variant, array $data = []): array
    {
        return array_replace(['version' => CatalogAccess::version($variant->fresh()), 'price' => '100000', 'promo_price' => '80000',
            'promo_starts_at' => '2026-10-25T11:00', 'promo_ends_at' => '2026-10-26T11:00', 'promotion_timezone' => 'Africa/Douala'], $data);
    }

    public function test_partial_update_validates_the_final_merged_price_inside_the_action(): void
    {
        $variant = $this->variant();
        try {
            app(UpdateProductVariant::class)->execute($variant, ['price' => '80000']);
            $this->fail('The retained promotion exceeds the new base price.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_price', $exception->errors());
        }
        $this->assertSame('100000.00', $variant->fresh()->price);
        app(UpdateProductVariant::class)->execute($variant, ['price' => '80000', 'promo_price' => null]);
        $this->assertSame('80000.00', $variant->fresh()->price);
        $this->assertNull($variant->fresh()->promo_price);
        $this->assertSame(2, $variant->stockLevel->reserved_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_partial_dates_and_fcfa_rounding_are_validated_against_persisted_values(): void
    {
        $variant = $this->variant('offers', ['promo_starts_at' => now(), 'promo_ends_at' => now()->addDay()]);
        foreach ([['promo_ends_at' => now()->subMinute()], ['price' => '85000.40']] as $data) {
            try {
                app(UpdateProductVariant::class)->execute($variant, $data);
                $this->fail('Invalid final state was accepted.');
            } catch (ValidationException) {
                $this->assertSame('100000.00', $variant->fresh()->price);
            }
        }
        $this->assertTrue($variant->fresh()->promo_ends_at->equalTo(now()->addDay()));
    }

    public function test_owner_can_save_clear_and_feature_with_local_dates_and_optimistic_versions(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->actor($variant), 'web');
        $this->put(route('professional.promotions.update', $variant), $this->form($variant))->assertSessionHasNoErrors()->assertRedirect();
        $variant->refresh();
        $this->assertSame('2026-10-25 10:00:00', $variant->promo_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('active', $variant->promotionStatus(now()));
        $this->assertSame('80000.00', $variant->effectivePrice(now()));
        $this->get(route('professional.promotions.edit', $variant))->assertOk()->assertSee('2026-10-25T11:00', false)->assertSee('Africa/Douala');
        $this->put(route('professional.promotions.featured', $variant->product), ['version' => CatalogAccess::version($variant->product), 'is_featured' => 1])->assertRedirect();
        $this->assertSame(1, (int) $variant->product->fresh()->is_featured);
        $oldVersion = CatalogAccess::version($variant);
        $this->delete(route('professional.promotions.destroy', $variant), ['version' => $oldVersion])->assertRedirect();
        $this->assertNull($variant->fresh()->promo_price);
        $this->putJson(route('professional.promotions.update', $variant), $this->form($variant, ['version' => $oldVersion]))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_manager_can_read_and_edit_non_pricing_information_but_no_price_endpoint(): void
    {
        $variant = $this->variant();
        $variant->product->update(['is_featured' => true]);
        $this->actingAs($this->actor($variant, 'STORE_MANAGER'), 'web');
        $this->get(route('professional.promotions.index'))->assertOk()->assertSee(__('promotions.readonly', [], 'fr'));
        $this->get(route('professional.products.edit', $variant->product))->assertOk()->assertDontSee('name="promo_price"', false);
        foreach (['professional.promotions.update', 'professional.variants.update', 'product-variants.update'] as $route) {
            $this->putJson(route($route, $variant), $this->form($variant) + ['sku' => $variant->sku, 'status' => 'ACTIVE'])->assertForbidden();
        }
        $this->putJson(route('professional.variants.update', $variant), ['sku' => 'NEW-SKU', 'status' => 'ACTIVE', 'version' => CatalogAccess::version($variant)])
            ->assertRedirect();
        $product = $variant->product->fresh();
        $data = $product->only(['category_id', 'name', 'slug', 'description', 'status']);
        $data['name'] = 'Updated non-pricing description';
        $this->put(route('products.update', $product), $data)->assertRedirect();
        $this->assertSame(1, (int) $product->fresh()->is_featured);
        $this->putJson(route('products.update', $product), $data + ['is_featured' => 0])->assertForbidden();
        $this->putJson(route('professional.promotions.featured', $product), ['version' => CatalogAccess::version($product->fresh()), 'is_featured' => 0])->assertForbidden();
        $this->deleteJson(route('professional.promotions.destroy', $variant), ['version' => CatalogAccess::version($variant->fresh())])->assertForbidden();
        $this->postJson(route('products.store'), $data + ['default_variant' => ['price' => 1]])->assertForbidden();
        $this->postJson(route('product-variants.store', $product), ['sku' => 'X', 'price' => 1, 'status' => 'ACTIVE'])->assertForbidden();
        $this->assertSame('100000.00', $variant->fresh()->price);
        $this->assertSame('85000.00', $variant->fresh()->promo_price);
    }

    public function test_legacy_owner_endpoint_cannot_leave_incoherent_prices_or_dates(): void
    {
        $variant = $this->variant('offers', ['promo_starts_at' => now(), 'promo_ends_at' => now()->addDay()]);
        $this->actingAs($this->actor($variant), 'web');
        $data = ['sku' => $variant->sku, 'status' => 'ACTIVE'];
        $this->putJson(route('product-variants.update', $variant), $data + ['price' => 80000])->assertUnprocessable()->assertJsonValidationErrors('promo_price');
        $this->putJson(route('product-variants.update', $variant), $data + ['promo_ends_at' => now()->subDay()->toDateTimeString()])->assertUnprocessable()->assertJsonValidationErrors('promo_ends_at');
        $this->putJson(route('product-variants.update', $variant), $data + ['price' => 80000, 'promo_price' => null])->assertRedirect();
        $this->assertNull($variant->fresh()->promo_price);
    }

    public function test_foreign_variants_products_and_cumulative_roles_cannot_modify_promotions(): void
    {
        $own = $this->variant('alpha');
        $actor = $this->actor($own);
        $foreign = $this->variant('beta');
        $foreign->load('product');
        $this->actingAs($actor, 'web');
        $this->get(route('professional.promotions.edit', $foreign))->assertNotFound();
        $this->putJson(route('professional.promotions.update', $foreign), $this->form($foreign))->assertNotFound();
        $this->putJson(route('professional.promotions.featured', $foreign->product), ['version' => CatalogAccess::version($foreign->product), 'is_featured' => 1])->assertNotFound();
        $actor->roles()->attach(Role::where('code', 'STORE_MANAGER')->firstOrFail());
        $this->putJson(route('product-variants.update', $own), ['sku' => 'X', 'price' => 1, 'status' => 'ACTIVE'])->assertForbidden();
        $this->get(route('professional.promotions.index'))->assertForbidden();
    }

    public function test_cashier_delivery_admin_disabled_and_buyer_owners_cannot_manage_prices(): void
    {
        $variant = $this->variant();
        foreach (['CASHIER', 'DELIVERY_AGENT', 'SUPER_ADMIN'] as $role) {
            $this->actingAs($this->actor($variant, $role), 'web');
            $this->putJson(route('product-variants.update', $variant), ['price' => 1])->assertForbidden();
            $this->get(route('professional.promotions.index'))->assertForbidden();
        }
        foreach ([['status' => 'DISABLED'], ['is_buyer' => true]] as $data) {
            $owner = $this->actor($variant);
            $owner->forceFill($data)->save();
            $this->actingAs($owner, 'web');
            $this->putJson(route('product-variants.update', $variant), ['price' => 1])->assertForbidden();
        }
    }

    public function test_status_filters_and_inclusive_boundaries_match_the_price_engine(): void
    {
        $variant = $this->variant('offers', ['promo_starts_at' => now(), 'promo_ends_at' => now()->addHour()]);
        $this->assertSame('active', $variant->promotionStatus(now()));
        $this->assertSame('85000.00', $variant->effectivePrice(now()->addHour()));
        $this->assertSame('active', $variant->promotionStatus(now()->addHour()));
        $this->assertSame('scheduled', $variant->promotionStatus(now()->subSecond()));
        $this->assertSame('expired', $variant->promotionStatus(now()->addHour()->addSecond()));
        $this->actingAs($this->actor($variant), 'web');
        $this->get(route('professional.promotions.index', ['state' => 'active']))->assertOk()->assertViewHas('variants', fn ($rows) => $rows->total() === 1);
        $this->get(route('professional.promotions.index', ['state' => 'expired']))->assertOk()->assertViewHas('variants', fn ($rows) => $rows->total() === 0);
        $this->putJson(route('professional.promotions.update', $variant), $this->form($variant, ['promo_starts_at' => '2026-02-30T11:00']))->assertUnprocessable();
    }

    public function test_global_marketplace_does_not_prioritize_a_merchant_feature_flag_and_prices_match_storefront(): void
    {
        $variant = $this->variant();
        $variant->product->update(['is_featured' => true]);
        $new = Product::create(['category_id' => $variant->product->category_id, 'name' => 'Newer unfeatured product', 'slug' => 'newer', 'status' => 'ACTIVE']);
        ProductVariant::create(['product_id' => $new->id, 'sku' => 'NEW', 'price' => 1000, 'status' => 'ACTIVE']);
        $this->get(route('marketplace.products'))->assertOk()->assertViewHas('products', fn ($products) => $products->first()->id === $new->id);
        $this->get(route('marketplace.home'))->assertOk()->assertDontSee('class="zm-featured"', false)
            ->assertViewHas('products', fn ($products) => $products->first()->id === $new->id);
        $this->get(route('marketplace.shops.show', ['shopSlug' => 'offers']))->assertOk()->assertViewHas('products', fn ($products) => $products->first()->id === $variant->product_id);
        foreach ([route('marketplace.products'), route('marketplace.shops.products', ['shopSlug' => 'offers']), route('marketplace.products.show', ['shopSlug' => 'offers', 'product' => $variant->product_id])] as $url) {
            $this->get($url)->assertOk()->assertSee('85 000 FCFA')->assertSee('100 000 FCFA')->assertSee('15%');
        }
    }

    public function test_promotions_search_pagination_sort_and_bilingual_views_use_actual_variant_data(): void
    {
        $variant = $this->variant();
        foreach (range(1, 14) as $index) {
            ProductVariant::create(['product_id' => $variant->product_id, 'sku' => 'OPTION-'.$index, 'price' => 100000 + $index,
                'promo_price' => $index % 4 === 0 ? null : 80000,
                'promo_starts_at' => $index % 4 === 1 ? now()->addDay() : null,
                'promo_ends_at' => $index % 4 === 2 ? now()->subDay() : null, 'status' => 'ACTIVE']);
        }
        $actor = $this->actor($variant);
        $this->actingAs($actor, 'web');
        $this->get(route('professional.promotions.index'))->assertOk()->assertViewHas('variants', fn ($rows) => $rows->total() === 15 && $rows->count() === 12);
        $this->get(route('professional.promotions.index', ['q' => 'OPTION-14']))->assertOk()->assertViewHas('variants', fn ($rows) => $rows->total() === 1);
        $this->get(route('professional.promotions.index', ['sort' => 'price']))->assertOk()->assertViewHas('variants', fn ($rows) => $rows->first()->id === $variant->id);
        foreach (['fr', 'en'] as $locale) {
            $actor->forceFill(['preferred_locale' => $locale])->save();
            $this->actingAs($actor->fresh(), 'web');
            $this->withSession(['locale' => $locale]);
            $response = $this->get(route('professional.promotions.edit', $variant))->assertOk()
                ->assertSee(__('promotions.timezone', [], $locale))->assertDontSee('@include', false)->assertDontSee('promotions.', false);
            if (getenv('ZEMBOA_PROMOTIONS_PREVIEW') === '1') {
                $directory = storage_path('framework/testing/promotions-preview');
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                $html = preg_replace('/(name="_token" value=")[^"]+/', '$1preview', $response->getContent());
                $html = preg_replace('/(name="csrf-token" content=")[^"]+/', '$1preview', $html);
                file_put_contents($directory.'/promotions-'.$locale.'.html', $html);
                $this->app['auth']->guard('web')->logout();
                $public = $this->get(route('marketplace.products.show', ['shopSlug' => 'offers', 'product' => $variant->product_id]))->assertOk();
                file_put_contents($directory.'/product-'.$locale.'.html', preg_replace('/(name="csrf-token" content=")[^"]+/', '$1preview', $public->getContent()));
            }
        }
    }

    public function test_invalid_local_dates_do_not_break_the_html_validation_response(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->actor($variant), 'web');
        $url = route('professional.promotions.edit', $variant);
        $this->from($url)->put(route('professional.promotions.update', $variant), $this->form($variant, ['promo_starts_at' => 'not-a-date']))
            ->assertRedirect($url)->assertSessionHasErrors('promo_starts_at');
        $this->get($url)->assertOk();
        $this->assertSame('85000.00', $variant->fresh()->promo_price);
    }

    public function test_decimal_database_projections_and_numeric_json_use_the_existing_fcfa_rounding(): void
    {
        $variant = $this->variant('offers', ['price' => '100000.50', 'promo_price' => '85000.50']);
        $this->get(route('marketplace.products'))->assertOk()->assertSee('85 001 FCFA')->assertSee('100 001 FCFA');
        $this->get(route('marketplace.products.show', ['shopSlug' => 'offers', 'product' => $variant->product_id]))->assertOk()->assertSee('85 001 FCFA');
        app(UpdateProductVariant::class)->execute($variant, ['price' => 100.5, 'promo_price' => 100.4]);
        $this->assertSame('100.50', $variant->fresh()->price);
        $this->assertSame('100.40', $variant->fresh()->promo_price);
        $this->get(route('marketplace.products'))->assertOk()->assertSee('100 FCFA')->assertSee('101 FCFA')->assertDontSee('−0%');
    }

    public function test_explicit_json_offsets_are_normalized_to_utc_at_the_shared_action_boundary(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->actor($variant), 'web');
        $dates = ['promo_starts_at' => '2026-10-25T11:00:00+01:00', 'promo_ends_at' => '2026-10-25T12:00:00+01:00'];
        $this->putJson(route('product-variants.update', $variant), $dates + ['sku' => $variant->sku, 'status' => 'ACTIVE'])->assertRedirect();
        $this->assertSame('2026-10-25 10:00:00', $variant->fresh()->getRawOriginal('promo_starts_at'));
        $this->assertSame('2026-10-25 11:00:00', $variant->fresh()->getRawOriginal('promo_ends_at'));
        $created = app(CreateProductVariant::class)->execute($dates + [
            'product_id' => $variant->product_id, 'sku' => 'OFFSET', 'price' => 1000, 'promo_price' => 800, 'status' => 'ACTIVE']);
        $this->assertSame('2026-10-25 10:00:00', $created->getRawOriginal('promo_starts_at'));
        $this->assertSame('2026-10-25 11:00:00', $created->getRawOriginal('promo_ends_at'));
        $this->assertSame('800.00', $created->effectivePrice(now()));
    }
}
