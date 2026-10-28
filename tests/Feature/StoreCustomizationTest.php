<?php

namespace Tests\Feature;

use App\Actions\Payments\ValidateStorePaymentRules;
use App\Actions\StoreSettings\UpdateStoreSettings;
use App\Models\Category;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\StoreBranding;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreCustomizationTest extends TestCase
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
        Storage::fake('local');
        Storage::fake('public');
    }

    private function owner(string $slug = 'fictional-brand', string $role = 'STORE_OWNER'): User
    {
        $store = Store::create(['name' => 'Fictional store', 'slug' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        $user = User::factory()->create(['name' => 'Fictional owner', 'email' => $slug.'@example.test', 'store_id' => $store->id, 'status' => 'ACTIVE', 'is_buyer' => false, 'email_verified_at' => now(), 'preferred_locale' => 'fr']);
        $user->roles()->attach(Role::where('code', $role)->firstOrFail());
        $this->actingAs($user, 'web');

        return $user;
    }

    private function save(array $data, int $revision = 0): TestResponse
    {
        return $this->post(route('professional.customization.save'), array_replace(['revision' => $revision, 'section' => 'identity'], $data));
    }

    private function publish(int $revision): TestResponse
    {
        return $this->post(route('professional.customization.publish'), ['revision' => $revision]);
    }

    public function test_identity_stays_private_until_explicit_publication_and_slug_is_preserved(): void
    {
        $owner = $this->owner();
        $this->save(['name' => 'New private identity', 'description' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $this->assertSame('Fictional store', $owner->store()->value('name'));
        $this->assertDatabaseCount('store_settings', 0);
        $this->get(route('marketplace.shops.show', 'fictional-brand'))->assertDontSee('New private identity');
        $this->get(route('professional.customization.preview'))->assertOk()->assertSee('New private identity')->assertDontSee('<script>alert(1)</script>', false)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->publish(1)->assertSessionHasNoErrors();
        $this->assertSame('New private identity', $owner->store()->value('name'));
        $this->assertSame('fictional-brand', $owner->store()->value('slug'));
        $this->assertDatabaseCount('store_settings', 0);
        $this->get(route('marketplace.shops.show', 'fictional-brand'))->assertSee('New private identity');
        $this->publish(1)->assertSessionHasErrors('revision');
    }

    public function test_appearance_first_configuration_preserves_effective_commercial_rules(): void
    {
        $this->owner();
        $this->save(['section' => 'appearance', 'primary_color' => '#125699'])->assertSessionHasNoErrors();
        $this->publish(1)->assertSessionHasNoErrors();
        $settings = StoreSetting::firstOrFail();
        $this->assertNull($settings->commercial_configured);
        $this->assertFalse($settings->hasCommercialConfiguration());
        $this->assertSame(30, $settings->reservation_duration_minutes);
        $this->assertFalse($settings->allow_partial_payments);
        $this->assertTrue($settings->delivery_enabled);
        $this->assertTrue($settings->pickup_enabled);
        $this->assertFalse($settings->returns_enabled);
        $this->assertSame('#125699', $settings->primary_color);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->get(route('marketplace.shops.services', 'fictional-brand'))->assertDontSee(__('marketplace.storefront.reservation'));
    }

    public function test_legacy_partial_updates_use_safe_defaults_and_preserve_existing_business_settings(): void
    {
        $this->owner();
        $this->patchJson('/store/settings', ['primary_color' => '#112233'])->assertOk();
        $settings = StoreSetting::firstOrFail();
        $this->assertSame(30, $settings->reservation_duration_minutes);
        $this->assertFalse($settings->allow_partial_payments);
        $this->assertFalse($settings->hasCommercialConfiguration());
        $this->patchJson('/store/settings', ['reservation_duration_minutes' => 80, 'allow_partial_payments' => true])->assertOk();
        $this->assertNull($settings->fresh()->commercial_configured);
        $this->assertFalse($settings->fresh()->hasCommercialConfiguration());
        $this->patchJson('/store/settings', ['primary_color' => '#334455'])->assertOk();
        $this->assertSame(80, $settings->fresh()->reservation_duration_minutes);
        $this->assertTrue($settings->fresh()->allow_partial_payments);
    }

    public function test_partial_sections_merge_without_publishing_or_changing_existing_settings(): void
    {
        $this->owner();
        $verifiedSettings = StoreSetting::create(['reservation_duration_minutes' => 95, 'allow_partial_payments' => true, 'minimum_deposit_percent' => 50, 'pickup_enabled' => false]);
        $verifiedSettings->forceFill(['commercial_configured' => true])->save();
        $this->save(['slogan' => 'Prepared privately'])->assertSessionHasNoErrors();
        $this->save(['section' => 'information', 'email' => 'public@example.test', 'phone' => '+237 699 000 000'], 1)->assertSessionHasNoErrors();
        $this->save(['section' => 'appearance', 'primary_color' => '#FFDD00'], 2)->assertSessionHasNoErrors();
        $this->publish(3)->assertSessionHasNoErrors();
        $settings = StoreSetting::firstOrFail();
        $this->assertSame(95, $settings->reservation_duration_minutes);
        $this->assertSame(50, $settings->minimum_deposit_percent);
        $this->assertFalse($settings->pickup_enabled);
        $this->assertTrue($settings->allow_partial_payments);
        $this->assertTrue($settings->hasCommercialConfiguration());
        $this->assertSame('public@example.test', Store::firstOrFail()->email);
    }

    public function test_business_draft_only_takes_effect_on_publication(): void
    {
        $this->owner();
        $this->patchJson('/store/settings', ['primary_color' => '#123456'])->assertOk();
        $this->save(['section' => 'commerce', 'delivery_enabled' => false, 'pickup_enabled' => true, 'allow_partial_payments' => false, 'reservation_duration_minutes' => 45])->assertSessionHasNoErrors();
        $this->assertFalse(StoreSetting::firstOrFail()->hasCommercialConfiguration());
        $this->get(route('professional.customization.preview', ['page' => 'services']))->assertOk()->assertDontSee(__('marketplace.storefront.pickup', [], 'fr'));
        $this->publish(1)->assertSessionHasNoErrors();
        $settings = StoreSetting::firstOrFail();
        $this->assertNull($settings->commercial_configured);
        $this->assertFalse($settings->hasCommercialConfiguration());
        $this->assertFalse($settings->delivery_enabled);
        $this->assertSame(45, $settings->reservation_duration_minutes);
    }

    public function test_manager_can_only_read_published_information_even_with_manually_granted_write_permissions(): void
    {
        $owner = $this->owner();
        $this->save(['name' => 'Owner private draft'])->assertSessionHasNoErrors();
        $manager = User::factory()->create(['store_id' => $owner->store_id, 'status' => 'ACTIVE', 'email_verified_at' => now()]);
        $role = Role::where('code', 'STORE_MANAGER')->firstOrFail();
        $manager->roles()->attach($role);
        $role->permissions()->syncWithoutDetaching(Permission::where('code', 'settings.update')->pluck('id'));
        $this->actingAs($manager, 'web');
        $this->get(route('professional.customization.index'))->assertOk()->assertSee(__('customization.readonly'))->assertDontSee('Owner private draft');
        $this->save(['name' => 'Forbidden'])->assertForbidden();
        $this->publish(1)->assertForbidden();
        $this->get(route('professional.customization.preview'))->assertForbidden();
        $this->get(route('professional.customization.media', 'logo'))->assertForbidden();
        $this->patchJson('/store/settings', ['primary_color' => '#123456'])->assertForbidden();
    }

    public function test_other_roles_mixed_roles_inactive_accounts_and_stores_cannot_write(): void
    {
        foreach (['CASHIER', 'DELIVERY_AGENT', 'SUPER_ADMIN'] as $role) {
            $this->owner('blocked-'.strtolower($role), $role);
            Role::where('code', $role)->firstOrFail()->permissions()->syncWithoutDetaching(Permission::where('code', 'settings.update')->pluck('id'));
            $this->save(['name' => 'Forbidden'])->assertForbidden();
            $this->get(route('professional.customization.index'))->assertForbidden();
        }
        $owner = $this->owner('mixed');
        $unknown = Role::create(['code' => 'FUTURE_ROLE', 'name' => 'Future role']);
        $owner->roles()->attach($unknown);
        $this->save(['name' => 'Forbidden'])->assertForbidden();
        $this->patchJson('/store/settings', ['primary_color' => '#123456'])->assertForbidden();
        $this->patchJson('/store/document-settings', ['footer_message' => 'Forbidden'])->assertForbidden();
        $owner->roles()->detach($unknown);
        $owner->status = 'INACTIVE';
        $owner->save();
        $this->save(['name' => 'Forbidden'])->assertForbidden();
        $owner->status = 'ACTIVE';
        $owner->save();
        $owner->store()->update(['status' => 'INACTIVE']);
        $this->get(route('professional.customization.index'))->assertForbidden();
        $this->publish(0)->assertForbidden();
    }

    public function test_buyer_guard_and_guests_cannot_enter_the_editor(): void
    {
        $this->getJson(route('professional.customization.index'))->assertUnauthorized();
        $buyer = User::factory()->create(['is_buyer' => true, 'store_id' => null, 'status' => 'ACTIVE']);
        $this->actingAs($buyer, 'buyer');
        auth()->shouldUse('web');
        $this->getJson(route('professional.customization.index'))->assertUnauthorized();
    }

    public function test_store_ids_slugs_financial_fields_and_arbitrary_paths_are_rejected(): void
    {
        $this->owner();
        foreach (['store_id' => 999, 'slug' => 'hijacked', 'status' => 'ACTIVE', 'logo_path' => '../private', 'banner_path' => 'stores/other/banner.png', 'wallet_number' => '699000000', 'commercial_configured' => true, 'allow_reservations' => false] as $field => $value) {
            $this->save([$field => $value])->assertSessionHasErrors($field);
        }
        foreach (['store_id', 'slug', 'status', 'logo_path', 'banner_path'] as $field) {
            $this->save([$field => null])->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('store_customizations', 0);
    }

    public function test_revision_checks_and_published_configuration_drift_refuse_stale_operations(): void
    {
        $this->owner();
        $this->save(['name' => 'Private'])->assertSessionHasNoErrors();
        $this->save(['slogan' => 'stale'])->assertSessionHasErrors('revision');
        $this->patchJson('/store/settings', ['primary_color' => '#123456'])->assertOk();
        $this->publish(1)->assertSessionHasErrors('revision');
        $this->assertSame('Fictional store', Store::firstOrFail()->name);
        $this->post(route('professional.customization.discard'), ['revision' => 1])->assertSessionHasNoErrors();
        $this->save(['name' => 'Fresh'], 2)->assertSessionHasNoErrors();
        $this->publish(3)->assertSessionHasNoErrors();
        $this->assertSame('Fresh', Store::firstOrFail()->name);
    }

    public function test_valid_media_is_private_then_copied_on_publication_and_replaced_or_removed_safely(): void
    {
        $this->owner();
        $this->save(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)])->assertSessionHasNoErrors();
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get(route('professional.customization.media', 'logo'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->publish(1)->assertSessionHasNoErrors();
        $first = StoreSetting::firstOrFail()->logo_path;
        Storage::disk('public')->assertExists($first);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->save(['logo' => UploadedFile::fake()->image('second.png', 80, 80)], 2)->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($first);
        $this->publish(3)->assertSessionHasNoErrors();
        $second = StoreSetting::firstOrFail()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        $this->save(['remove_logo' => true], 4)->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($second);
        $this->publish(5)->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull(StoreSetting::firstOrFail()->logo_path);
    }

    public function test_invalid_uploads_wrong_section_uploads_and_conflicting_intents_do_not_store_files(): void
    {
        $this->owner();
        foreach ([UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->create('fake.png', 2, 'text/plain'), UploadedFile::fake()->image('huge.png', 2100, 20), UploadedFile::fake()->image('big.png')->size(2049)] as $file) {
            $this->save(['logo' => $file])->assertSessionHasErrors('logo');
        }
        $this->save(['logo' => UploadedFile::fake()->image('logo.png'), 'remove_logo' => true])->assertSessionHasErrors('logo');
        $this->save(['section' => 'appearance', 'primary_color' => '#123456', 'logo' => UploadedFile::fake()->create('bad.svg', 2)])->assertSessionHasNoErrors();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_other_store_cannot_read_preview_or_remove_original_media(): void
    {
        $first = $this->owner();
        $this->save(['name' => 'Secret draft', 'logo' => UploadedFile::fake()->image('logo.png', 100, 100)])->assertSessionHasNoErrors();
        $this->owner('other-store');
        $this->get(route('professional.customization.preview'))->assertOk()->assertDontSee('Secret draft');
        $this->get(route('professional.customization.media', 'logo'))->assertNotFound();
        $this->publish(1)->assertSessionHasErrors('revision');
        $this->assertSame('Fictional store', $first->store()->value('name'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_absent_or_partial_schema_fails_closed(): void
    {
        $this->owner();
        Schema::drop('store_customizations');
        $this->save(['name' => 'Forbidden'])->assertStatus(503);
        $this->get(route('professional.customization.index'))->assertStatus(503);
        Schema::create('store_customizations', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('store_id');
        });
        $this->save(['name' => 'Forbidden'])->assertStatus(503);
        $this->assertSame('Fictional store', Store::firstOrFail()->name);
    }

    public function test_publication_rolls_back_identity_and_media_if_a_later_setting_write_fails(): void
    {
        $this->owner();
        $this->save(['name' => 'Not published', 'logo' => UploadedFile::fake()->image('logo.png', 100, 100)])->assertSessionHasNoErrors();
        $this->mock(UpdateStoreSettings::class)->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Fictional write failure'));
        $this->publish(1)->assertStatus(500);
        $this->assertSame('Fictional store', Store::firstOrFail()->name);
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertNotNull(DB::table('store_customizations')->value('draft'));
    }

    public function test_missing_private_media_refuses_publication_without_losing_the_draft(): void
    {
        $this->owner();
        $this->save(['banner' => UploadedFile::fake()->image('banner.png', 800, 200)])->assertSessionHasNoErrors();
        Storage::disk('local')->delete(Storage::disk('local')->allFiles()[0]);
        $this->publish(1)->assertStatus(409);
        $this->assertNotNull(DB::table('store_customizations')->value('draft'));
    }

    public function test_contact_hours_color_validation_and_blank_days(): void
    {
        $this->owner();
        $this->save(['section' => 'information', 'email' => 'wrong', 'phone' => 'javascript:alert(1)'])->assertSessionHasErrors(['email', 'phone']);
        $this->save(['section' => 'appearance', 'primary_color' => 'url(javascript:alert(1))'])->assertSessionHasErrors('primary_color');
        $this->save(['section' => 'hours', 'opening_hours' => ['monday' => ['open' => '18:00', 'close' => '08:00']]])->assertSessionHasErrors('opening_hours.monday.close');
        $this->save(['section' => 'hours', 'default_locale' => 'en', 'opening_hours' => ['monday' => ['open' => '', 'close' => ''], 'tuesday' => ['open' => '08:00', 'close' => '17:00']]])->assertSessionHasNoErrors();
        $this->publish(1)->assertSessionHasNoErrors();
        $this->assertSame(['tuesday' => ['open' => '08:00', 'close' => '17:00']], StoreSetting::firstOrFail()->opening_hours);
    }

    public function test_appearance_only_configuration_does_not_authorize_partial_payments(): void
    {
        $owner = $this->owner();
        $this->patchJson('/store/settings', ['primary_color' => '#125599'])->assertOk();
        $order = new Order;
        $order->forceFill(['store_id' => $owner->store_id, 'total' => '10000.00']);
        $this->expectException(ValidationException::class);
        app(ValidateStorePaymentRules::class)->execute($order, '0.00', '1000.00');
    }

    public function test_appearance_only_configuration_does_not_activate_html_checkout_and_server_commerce_is_applied_explicitly(): void
    {
        $owner = $this->owner();
        $category = Category::create(['name' => 'Accessories', 'slug' => 'accessories', 'status' => 'ACTIVE']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Test item', 'slug' => 'test-item', 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'BRAND-TEST', 'price' => '5000.00', 'status' => 'ACTIVE', 'is_default' => true]);
        StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 0]);
        $this->patchJson('/store/settings', ['primary_color' => '#125599'])->assertOk();
        auth('web')->logout();
        $this->postJson('/shops/fictional-brand/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->get('/boutiques/fictional-brand/commande')->assertOk()->assertDontSee('id="merchant-checkout-confirm"', false);
        $this->assertDatabaseCount('orders', 0);
        $this->actingAs($owner, 'web');
        $this->patchJson('/store/settings', ['pickup_enabled' => true, 'delivery_enabled' => false])->assertOk();
        $this->assertNull(StoreSetting::firstOrFail()->commercial_configured);
        $this->assertDatabaseCount('orders', 0);
        StoreSetting::firstOrFail()->forceFill(['commercial_configured' => true])->save();
        auth('web')->logout();
        $token = $this->getJson('/shops/fictional-brand/checkout')->assertOk()->json('data.token');
        $this->post('/boutiques/fictional-brand/commande', ['customer_name' => 'Fictional buyer', 'customer_phone' => '699000000', 'fulfillment_type' => 'PICKUP', 'checkout_token' => $token])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('PENDING', Order::firstOrFail()->status);
        $this->assertSame('UNPAID', Order::firstOrFail()->payment_status);
    }

    public function test_migration_cycle_preserves_existing_configuration_and_refuses_data_loss(): void
    {
        $migration = require database_path('migrations/2026_10_25_075628_create_store_customizations_table.php');
        $migration->down();
        $this->owner();
        $settings = StoreSetting::create(['reservation_duration_minutes' => 1440, 'allow_partial_payments' => true]);
        $migration->up();
        $this->assertNull($settings->fresh()->commercial_configured);
        $this->assertFalse($settings->fresh()->hasCommercialConfiguration());
        $this->assertSame(1440, $settings->fresh()->reservation_duration_minutes);
        $this->save(['name' => 'Preserved draft'])->assertSessionHasNoErrors();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_public_fallback_and_accessible_accents_are_used(): void
    {
        $this->owner();
        StoreSetting::create(['primary_color' => '#FFFFFF', 'logo_path' => 'missing.png', 'banner_path' => 'missing-banner.png']);
        $response = $this->get(route('marketplace.shops.show', 'fictional-brand'))->assertOk();
        $response->assertDontSee('missing.png')->assertDontSee('missing-banner.png');
        $response->assertSee('--merchant-color: '.StoreBranding::accent('#FFFFFF'), false);
        $this->assertNotSame('#FFFFFF', StoreBranding::accent('#FFFFFF'));
        foreach (['#FFFFFF', '#FFFF00', '#00FF00', '#FF0000', '#000000'] as $color) {
            $channels = array_map(function (string $hex): float {
                $value = hexdec($hex) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr(StoreBranding::accent($color), 1), 2));
            $this->assertGreaterThanOrEqual(4.5, 1.05 / (0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] + 0.05));
        }
        $this->assertSame('#0F6B57', StoreBranding::accent('invalid'));
        $this->assertNull(StoreBranding::url(new StoreSetting(['logo_path' => '../private.png']), 'logo'));
    }

    public function test_all_editor_sections_and_preview_pages_render_in_both_languages(): void
    {
        $owner = $this->owner();
        $this->save(['name' => 'Atelier Horizon', 'slogan' => 'Objets utiles, esprit libre', 'description' => 'Une sélection de produits pour le quotidien.'])->assertSessionHasNoErrors();
        foreach (['fr', 'en'] as $locale) {
            $owner->preferred_locale = $locale;
            $owner->save();
            foreach (['identity', 'appearance', 'information', 'hours', 'commerce', 'documents', 'preview'] as $section) {
                $response = $this->get(route('professional.customization.index', ['section' => $section]))->assertOk();
                if (getenv('ZEMBOA_CUSTOMIZATION_PREVIEW') === '1') {
                    $directory = storage_path('framework/testing/store-customization-preview');
                    File::ensureDirectoryExists($directory);
                    File::put($directory.'/'.$locale.'-'.$section.'.html', $response->getContent());
                }
            }
        }
        foreach (['home', 'catalog', 'categories', 'about', 'services'] as $page) {
            $response = $this->get(route('professional.customization.preview', ['page' => $page]))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            if (getenv('ZEMBOA_CUSTOMIZATION_PREVIEW') === '1') {
                File::put(storage_path('framework/testing/store-customization-preview/store-'.$page.'.html'), $response->getContent());
            }
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
