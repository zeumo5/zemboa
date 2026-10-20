<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\StoreDocumentSetting;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class StoreDocumentSettingTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests interdits hors SQLite :memory:.'
            );
        }

        $this->refreshSafeDatabase();
    }

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function selectStore(Store $store): void
    {
        app(TenantContext::class)->setFromStore($store);
    }

    public function test_document_settings_have_correct_defaults(): void
    {
        $store = $this->createStore('alpha');
        $this->selectStore($store);

        $settings = StoreDocumentSetting::create([])
            ->refresh();

        $this->assertSame(
            $store->id,
            $settings->store_id
        );

        $this->assertSame(
            'A4',
            $settings->invoice_print_format
        );

        $this->assertSame(
            'TICKET_80',
            $settings->receipt_print_format
        );

        $this->assertTrue(
            $settings->digital_invoices_enabled
        );

        $this->assertTrue(
            $settings->digital_receipts_enabled
        );

        $this->assertTrue($settings->show_logo);

        $this->assertTrue(
            $settings->show_business_details
        );

        $this->assertNull($settings->footer_message);
        $this->assertNull($settings->document_locale);

        $this->assertDatabaseCount(
            'store_document_settings',
            1
        );
    }

    public function test_store_can_customize_document_preferences(): void
    {
        $store = $this->createStore('alpha');
        $this->selectStore($store);

        $settings = StoreDocumentSetting::create([
            'invoice_print_format' => 'TICKET_58',
            'receipt_print_format' => 'TICKET_80',
            'digital_invoices_enabled' => false,
            'digital_receipts_enabled' => true,
            'show_logo' => false,
            'show_business_details' => true,
            'footer_message' => 'Merci pour votre achat !',
            'document_locale' => 'en',
        ])->refresh();

        $this->assertSame(
            'TICKET_58',
            $settings->invoice_print_format
        );

        $this->assertSame(
            'TICKET_80',
            $settings->receipt_print_format
        );

        $this->assertFalse(
            $settings->digital_invoices_enabled
        );

        $this->assertTrue(
            $settings->digital_receipts_enabled
        );

        $this->assertFalse($settings->show_logo);

        $this->assertTrue(
            $settings->show_business_details
        );

        $this->assertSame(
            'Merci pour votre achat !',
            $settings->footer_message
        );

        $this->assertSame(
            'en',
            $settings->document_locale
        );
    }

    public function test_document_settings_are_isolated_between_stores(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $this->selectStore($storeA);

        $settingsA = StoreDocumentSetting::create([
            'invoice_print_format' => 'A4',
            'document_locale' => 'fr',
        ]);

        $this->selectStore($storeB);

        $settingsB = StoreDocumentSetting::create([
            'invoice_print_format' => 'TICKET_80',
            'document_locale' => 'en',
        ]);

        // Boutique B : les paramètres de A
        // doivent être invisibles.
        $this->assertNull(
            StoreDocumentSetting::find($settingsA->id)
        );

        $this->assertNotNull(
            StoreDocumentSetting::find($settingsB->id)
        );

        $this->assertSame(
            1,
            StoreDocumentSetting::count()
        );

        // Boutique A : les paramètres de B
        // doivent être invisibles.
        $this->selectStore($storeA);

        $this->assertNotNull(
            StoreDocumentSetting::find($settingsA->id)
        );

        $this->assertNull(
            StoreDocumentSetting::find($settingsB->id)
        );

        $this->assertSame(
            'fr',
            StoreDocumentSetting::firstOrFail()
                ->document_locale
        );

        // La base contient bien les deux boutiques,
        // mais le tenant actif n'en voit qu'une.
        $this->assertSame(
            2,
            DB::table('store_document_settings')->count()
        );
    }

    public function test_store_cannot_have_duplicate_document_settings(): void
    {
        $store = $this->createStore('alpha');
        $this->selectStore($store);

        StoreDocumentSetting::create([
            'invoice_print_format' => 'A4',
        ]);

        try {
            StoreDocumentSetting::create([
                'invoice_print_format' => 'TICKET_58',
            ]);

            $this->fail(
                'Une boutique ne doit pas avoir deux configurations.'
            );
        } catch (QueryException $exception) {
            $this->assertNotEmpty(
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'store_document_settings',
            1
        );
    }

    public function test_store_id_is_not_mass_assignable(): void
    {
        $settings = new StoreDocumentSetting();

        $this->assertFalse(
            $settings->isFillable('store_id')
        );

        $this->assertTrue(
            $settings->isFillable('invoice_print_format')
        );

        $this->assertTrue(
            $settings->isFillable('document_locale')
        );
    }
}
