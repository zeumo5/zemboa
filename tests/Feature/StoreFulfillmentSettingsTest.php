<?php
namespace Tests\Feature;


use App\Actions\Orders\CreateOrder;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreFulfillmentSettingsTest extends TestCase
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

    private function prepareStore(
        bool $deliveryEnabled,
        bool $pickupEnabled
    ): void {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        StoreSetting::create([
            'delivery_enabled' => $deliveryEnabled,
            'pickup_enabled' => $pickupEnabled,
        ]);
    }

    private function attemptOrder(string $type): void
    {
        app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690000001',
            customerEmail: null,
            fulfillmentType: $type,
            items: [
                [
                    'product_variant_id' => 1,
                    'quantity' => 1,
                ],
            ],
            deliveryZoneId: $type === 'DELIVERY' ? 1 : null,
            deliveryAddress: $type === 'DELIVERY'
                ? 'Douala, Makepe'
                : null
        );
    }

    public function test_disabled_delivery_is_rejected(): void
    {
        $this->prepareStore(
            deliveryEnabled: false,
            pickupEnabled: true
        );

        try {
            $this->attemptOrder('DELIVERY');

            $this->fail('La livraison devait être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'fulfillment_type',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_disabled_pickup_is_rejected(): void
    {
        $this->prepareStore(
            deliveryEnabled: true,
            pickupEnabled: false
        );

        try {
            $this->attemptOrder('PICKUP');

            $this->fail('Le retrait devait être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'fulfillment_type',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }
}
