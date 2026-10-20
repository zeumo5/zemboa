<?php

namespace Tests\Feature;


use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StoreDocumentSettingHttpTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Seeders uniquement en SQLite :memory:.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createActor(
        Store $store,
        string $roleCode = 'STORE_OWNER'
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function url(): string
    {
        return route('store.document-settings.show');
    }

    public function test_guest_cannot_access_document_settings(): void
    {
        $this->getJson($this->url())
            ->assertUnauthorized();

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'A4',
        ])->assertUnauthorized();
    }

    public function test_owner_can_view_defaults_without_creating_row(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        $this->actingAs($owner);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath(
                'settings.invoice_print_format',
                'A4'
            )
            ->assertJsonPath(
                'settings.receipt_print_format',
                'TICKET_80'
            )
            ->assertJsonPath(
                'settings.digital_invoices_enabled',
                true
            )
            ->assertJsonPath(
                'settings.digital_receipts_enabled',
                true
            )
            ->assertJsonPath(
                'settings.document_locale',
                null
            );

        $this->assertDatabaseCount(
            'store_document_settings',
            0
        );
    }

    public function test_owner_can_update_document_settings(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        $this->actingAs($owner);

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'TICKET_58',
            'receipt_print_format' => 'TICKET_80',
            'digital_invoices_enabled' => true,
            'digital_receipts_enabled' => false,
            'show_logo' => false,
            'footer_message' => 'Merci pour votre achat',
            'document_locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath(
                'settings.invoice_print_format',
                'TICKET_58'
            )
            ->assertJsonPath(
                'settings.digital_receipts_enabled',
                false
            )
            ->assertJsonPath(
                'settings.document_locale',
                'en'
            );

        $this->assertDatabaseHas(
            'store_document_settings',
            [
                'store_id' => $store->id,
                'invoice_print_format' => 'TICKET_58',
                'receipt_print_format' => 'TICKET_80',
                'document_locale' => 'en',
            ]
        );

        $this->assertDatabaseCount(
            'store_document_settings',
            1
        );
    }

    public function test_manager_can_update_document_settings(): void
    {
        $store = $this->createStore('alpha');

        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $this->actingAs($manager);

        $this->patchJson($this->url(), [
            'receipt_print_format' => 'TICKET_58',
        ])
            ->assertOk()
            ->assertJsonPath(
                'settings.receipt_print_format',
                'TICKET_58'
            );
    }

    public function test_cashier_and_delivery_agent_are_forbidden(): void
    {
        $store = $this->createStore('alpha');

        foreach (['CASHIER', 'DELIVERY_AGENT'] as $role) {
            $actor = $this->createActor($store, $role);

            $this->actingAs($actor);

            $this->getJson($this->url())
                ->assertForbidden();

            $this->patchJson($this->url(), [
                'invoice_print_format' => 'A4',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount(
            'store_document_settings',
            0
        );
    }

    public function test_invalid_document_preferences_are_rejected(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        $this->actingAs($owner);

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'INVALID',
            'receipt_print_format' => 'TICKET_100',
            'document_locale' => 'es',
            'digital_invoices_enabled' => 'invalid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'invoice_print_format',
                'receipt_print_format',
                'document_locale',
                'digital_invoices_enabled',
            ]);

        $this->assertDatabaseCount(
            'store_document_settings',
            0
        );
    }

    public function test_user_cannot_force_another_store_id(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createActor($storeA);

        $this->actingAs($ownerA);

        $this->patchJson($this->url(), [
            'store_id' => $storeB->id,
            'invoice_print_format' => 'TICKET_80',
        ])->assertOk();

        $this->assertDatabaseHas(
            'store_document_settings',
            [
                'store_id' => $storeA->id,
                'invoice_print_format' => 'TICKET_80',
            ]
        );

        $this->assertDatabaseMissing(
            'store_document_settings',
            ['store_id' => $storeB->id]
        );
    }

    public function test_document_settings_are_isolated_between_stores(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createActor($storeA);
        $ownerB = $this->createActor($storeB);

        $this->actingAs($ownerA);

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'A4',
            'document_locale' => 'fr',
        ])->assertOk();

        $this->actingAs($ownerB);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath(
                'settings.document_locale',
                null
            );

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'TICKET_58',
            'document_locale' => 'en',
        ])->assertOk();

        $this->actingAs($ownerA);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath(
                'settings.invoice_print_format',
                'A4'
            )
            ->assertJsonPath(
                'settings.document_locale',
                'fr'
            );

        $this->actingAs($ownerB);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath(
                'settings.invoice_print_format',
                'TICKET_58'
            )
            ->assertJsonPath(
                'settings.document_locale',
                'en'
            );

        $this->assertDatabaseCount(
            'store_document_settings',
            2
        );
    }

    public function test_document_settings_message_is_in_french(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        // Le propriétaire préfère le français.
        $owner->preferred_locale = 'fr';
        $owner->save();

        $this->actingAs($owner);

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'A4',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Paramètres documentaires enregistrés.'
            )
            ->assertJsonPath(
                'settings.invoice_print_format',
                'A4'
            );
    }

    public function test_document_settings_message_is_in_english(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        // Le propriétaire préfère l'anglais.
        $owner->preferred_locale = 'en';
        $owner->save();

        $this->actingAs($owner);

        $this->patchJson($this->url(), [
            'invoice_print_format' => 'TICKET_80',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Document settings saved successfully.'
            )
            ->assertJsonPath(
                'settings.invoice_print_format',
                'TICKET_80'
            );

        // La traduction ne doit pas modifier
        // la préférence de langue enregistrée.
        $this->assertSame(
            'en',
            $owner->fresh()->preferred_locale
        );
    }
}
