<?php
namespace Tests\Feature;


use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\ExpireOrder;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreReservationSettingsTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshIsolatedDatabase;
    }

    // Bloquer toute migration sur une base réelle.
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

    protected function setUp(): void
    {
        parent::setUp();

        // Horloge fixe pour des tests reproductibles.
        $this->travelTo(
            Carbon::parse('2026-10-14 10:00:00')
        );
    }

    private function prepareStore(string $slug): array
    {
        $store = Store::create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Produits test',
            'slug' => 'produits-' . $slug,
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit test',
            'slug' => 'produit-' . $slug,
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)
            ->execute([
                'product_id' => $product->id,
                'sku' => strtoupper($slug) . '-001',
                'price' => '10000.00',
                'is_default' => true,
                'status' => 'ACTIVE',
            ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial'
        );

        return [$store, $user, $variant];
    }

    private function createOrder(
        ProductVariant $variant
    ): Order {
        return app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690000001',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        );
    }

    private function assertReservationDuration(
        Order $order,
        int $minutes
    ): void {
        $expected = now()
            ->addMinutes($minutes)
            ->toDateTimeString();

        $reservation = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->firstOrFail();

        $this->assertSame(
            $expected,
            Carbon::parse(
                $order->reservation_expires_at
            )->toDateTimeString()
        );

        $this->assertSame(
            $expected,
            Carbon::parse(
                $reservation->expires_at
            )->toDateTimeString()
        );
    }

    public function test_store_without_settings_keeps_30_minutes(): void
    {
        [, , $variant] = $this->prepareStore(
            'boutique-alpha'
        );

        $order = $this->createOrder($variant);

        $this->assertReservationDuration($order, 30);
    }

    public function test_store_uses_custom_reservation_duration(): void
    {
        [, , $variant] = $this->prepareStore(
            'boutique-alpha'
        );

        StoreSetting::create([
            'reservation_duration_minutes' => 120,
        ]);

        $order = $this->createOrder($variant);

        $this->assertReservationDuration($order, 120);
    }

    public function test_stores_have_independent_reservation_durations(): void
    {
        [, , $variantA] = $this->prepareStore(
            'boutique-alpha'
        );

        StoreSetting::create([
            'reservation_duration_minutes' => 60,
        ]);

        $orderA = $this->createOrder($variantA);

        $this->assertReservationDuration($orderA, 60);

        [, , $variantB] = $this->prepareStore(
            'boutique-beta'
        );

        StoreSetting::create([
            'reservation_duration_minutes' => 1440,
        ]);

        $orderB = $this->createOrder($variantB);

        $this->assertReservationDuration($orderB, 1440);

        $this->assertNotEquals(
            $orderA->reservation_expires_at,
            $orderB->reservation_expires_at
        );
    }

    public function test_order_expires_after_custom_deadline(): void
    {
        [, , $variant] = $this->prepareStore(
            'boutique-alpha'
        );

        StoreSetting::create([
            'reservation_duration_minutes' => 60,
        ]);

        $order = $this->createOrder($variant);

        // Avant expiration : refus obligatoire.
        $this->travel(59)->minutes();

        try {
            app(ExpireOrder::class)->execute($order->id);

            $this->fail(
                'La commande ne doit pas expirer avant 60 minutes.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'reservation_expires_at',
                $exception->errors()
            );
        }

        $this->assertSame(
            'PENDING',
            $order->fresh()->status
        );

        // Après expiration : le stock est libéré.
        $this->travel(2)->minutes();

        app(ExpireOrder::class)->execute($order->id);

        $this->assertSame(
            'CANCELLED',
            $order->fresh()->status
        );

        $reservation = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->firstOrFail();

        $this->assertSame('EXPIRED', $reservation->status);

        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(0, $stock->reserved_quantity);
        $this->assertSame(10, $stock->physical_quantity);
    }

    public function test_setting_change_does_not_alter_existing_order(): void
    {
        [, , $variant] = $this->prepareStore(
            'boutique-alpha'
        );

        $settings = StoreSetting::create([
            'reservation_duration_minutes' => 60,
        ]);

        $order = $this->createOrder($variant);

        $originalExpiry = Carbon::parse(
            $order->reservation_expires_at
        )->toDateTimeString();

        // Modification après création de la commande.
        $settings->update([
            'reservation_duration_minutes' => 120,
        ]);

        $this->assertSame(
            $originalExpiry,
            Carbon::parse(
                $order->fresh()->reservation_expires_at
            )->toDateTimeString()
        );

        // Les nouvelles commandes prennent la nouvelle durée.
        $newOrder = $this->createOrder($variant);

        $this->assertReservationDuration($newOrder, 120);
    }
}
