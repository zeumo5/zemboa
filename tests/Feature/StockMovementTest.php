<?php

namespace Tests\Feature;


use App\Actions\Stock\ConvertStockReservation;
use App\Actions\Stock\ExpireStockReservation;
use App\Actions\Stock\ReleaseStockReservation;
use App\Actions\Stock\CreateStockReservation;
use App\Models\StockReservation;
use App\Actions\Stock\ReturnStock;
use App\Actions\Stock\AdjustStockOut;
use App\Actions\Stock\AdjustStockIn;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Stock\CreateStockMovement;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private function createStockMovement(): StockMovement
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

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung Galaxy S26',
            'slug' => 'samsung-galaxy-s26',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $variant = app(CreateProductVariant::class)
            ->execute([
                'product_id' => $product->id,
                'sku' => 'SGS26-128',
                'price' => 450000,
                'status' => 'ACTIVE',
            ]);

        return StockMovement::create([
            'product_variant_id' => $variant->id,
            'type' => 'RECEIPT',
            'quantity' => 10,
            'reason' => 'Stock initial',
            'created_by' => $user->id,
        ]);
    }

    public function test_existing_stock_movement_cannot_be_updated(): void
    {
        $movement = $this->createStockMovement();

        $this->expectException(\LogicException::class);

        $movement->update([
            'quantity' => 50,
        ]);
    }

    public function test_existing_stock_movement_cannot_be_deleted(): void
    {
        $movement = $this->createStockMovement();

        $this->expectException(\LogicException::class);

        $movement->delete();
    }

    public function test_stock_movement_quantity_must_be_greater_than_zero(): void
{
    $movement = $this->createStockMovement();

    $this->expectException(\Illuminate\Validation\ValidationException::class);

   app(CreateStockMovement::class)->execute([
    'product_variant_id' => $movement->product_variant_id,
    'type' => 'RECEIPT',
    'quantity' => 0,
    'reason' => 'Mouvement invalide',
]);
}

public function test_stock_movement_type_must_be_valid(): void
{
    $movement = $this->createStockMovement();

    $this->expectException(
        \Illuminate\Validation\ValidationException::class
    );

    app(CreateStockMovement::class)->execute([
        'product_variant_id' => $movement->product_variant_id,
        'type' => 'INVALID_TYPE',
        'quantity' => 5,
        'reason' => 'Type invalide',
    ]);
}

public function test_receiving_stock_increases_physical_quantity_and_creates_movement(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    $stockLevel = $variant->stockLevel;

    $this->assertSame(0, $stockLevel->physical_quantity);

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 20,
        userId: $movement->created_by,
        reason: 'Réception fournisseur'
    );

    $stockLevel->refresh();

    $this->assertSame(20, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(20, $stockLevel->availableQuantity());

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $variant->store_id,
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
        'quantity' => 20,
        'reason' => 'Réception fournisseur',
        'created_by' => $movement->created_by,
    ]);
}

public function test_receiving_zero_stock_is_rejected_without_changing_stock(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;
    $stockLevel = $variant->stockLevel;

    $movementsBefore = StockMovement::query()->count();

    try {
        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 0,
            userId: $movement->created_by,
            reason: 'Réception invalide'
        );

        $this->fail('Une quantité nulle aurait dû être refusée.');
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'quantity',
            $exception->errors()
        );
    }

    $stockLevel->refresh();

    $this->assertSame(0, $stockLevel->physical_quantity);
    $this->assertSame($movementsBefore, StockMovement::query()->count());
}

public function test_adjustment_in_increases_physical_stock_and_creates_movement(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;
    $stockLevel = $variant->stockLevel;

    app(AdjustStockIn::class)->execute(
        productVariantId: $variant->id,
        quantity: 3,
        userId: $movement->created_by,
        reason: 'Écart positif après inventaire'
    );

    $stockLevel->refresh();

    $this->assertSame(3, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(3, $stockLevel->availableQuantity());

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $variant->store_id,
        'product_variant_id' => $variant->id,
        'type' => 'ADJUSTMENT_IN',
        'quantity' => 3,
        'reason' => 'Écart positif après inventaire',
        'created_by' => $movement->created_by,
    ]);
} 

public function test_adjustment_out_decreases_physical_stock_and_creates_movement(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $stockLevel = $variant->stockLevel()->firstOrFail();

    app(AdjustStockOut::class)->execute(
        productVariantId: $variant->id,
        quantity: 3,
        userId: $movement->created_by,
        reason: 'Écart négatif après inventaire'
    );

    $stockLevel->refresh();

    $this->assertSame(7, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(7, $stockLevel->availableQuantity());

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $variant->store_id,
        'product_variant_id' => $variant->id,
        'type' => 'ADJUSTMENT_OUT',
        'quantity' => 3,
        'reason' => 'Écart négatif après inventaire',
        'created_by' => $movement->created_by,
    ]);
}

public function test_adjustment_out_cannot_reduce_physical_stock_below_reserved_stock(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $stockLevel = $variant->stockLevel()->firstOrFail();

    // Simulation temporaire d'une réservation.
    $stockLevel->reserved_quantity = 8;
    $stockLevel->save();

    $movementsBefore = StockMovement::query()->count();

    try {
        app(AdjustStockOut::class)->execute(
            productVariantId: $variant->id,
            quantity: 3,
            userId: $movement->created_by,
            reason: 'Correction impossible'
        );

        $this->fail(
            'La correction aurait dû être refusée.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'quantity',
            $exception->errors()
        );
    }

    $stockLevel->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(8, $stockLevel->reserved_quantity);
    $this->assertSame(2, $stockLevel->availableQuantity());

    $this->assertSame(
        $movementsBefore,
        StockMovement::query()->count()
    );
}

public function test_return_stock_increases_physical_stock_and_creates_movement(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;
    $stockLevel = $variant->stockLevel()->firstOrFail();

    app(ReturnStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 2,
        userId: $movement->created_by,
        reason: 'Retour vérifié par le commerçant'
    );

    $stockLevel->refresh();

    $this->assertSame(2, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(2, $stockLevel->availableQuantity());

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $variant->store_id,
        'product_variant_id' => $variant->id,
        'type' => 'RETURN',
        'quantity' => 2,
        'reason' => 'Retour vérifié par le commerçant',
        'created_by' => $movement->created_by,
    ]);
}

public function test_creating_stock_reservation_increases_reserved_quantity(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $stockLevel = $variant->stockLevel()->firstOrFail();

    $reservation = app(CreateStockReservation::class)->execute(
        productVariantId: $variant->id,
        quantity: 3
    );

    $stockLevel->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(3, $stockLevel->reserved_quantity);
    $this->assertSame(7, $stockLevel->availableQuantity());

    $this->assertSame('ACTIVE', $reservation->status);
    $this->assertSame(3, $reservation->quantity);
    $this->assertSame($variant->id, $reservation->product_variant_id);
    $this->assertSame($variant->store_id, $reservation->store_id);

    $this->assertDatabaseHas('stock_reservations', [
        'store_id' => $variant->store_id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
        'status' => 'ACTIVE',
    ]);
}

public function test_stock_reservation_cannot_exceed_available_stock(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $stockLevel = $variant->stockLevel()->firstOrFail();

    $reservationsBefore = StockReservation::query()->count();

    try {
        app(CreateStockReservation::class)->execute(
            productVariantId: $variant->id,
            quantity: 11
        );

        $this->fail(
            'La réservation aurait dû être refusée.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'quantity',
            $exception->errors()
        );
    }

    $stockLevel->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(10, $stockLevel->availableQuantity());

    $this->assertSame(
        $reservationsBefore,
        StockReservation::query()->count()
    );
}

public function test_stock_reservation_quantity_must_be_greater_than_zero(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;
    $stockLevel = $variant->stockLevel()->firstOrFail();

    $reservationsBefore = StockReservation::query()->count();

    try {
        app(CreateStockReservation::class)->execute(
            productVariantId: $variant->id,
            quantity: 0
        );

        $this->fail(
            'Une réservation de quantité zéro aurait dû être refusée.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'quantity',
            $exception->errors()
        );
    }

    $stockLevel->refresh();

    $this->assertSame(0, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);

    $this->assertSame(
        $reservationsBefore,
        StockReservation::query()->count()
    );
} 

public function test_active_stock_reservation_can_be_released(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $reservation = app(CreateStockReservation::class)->execute(
        productVariantId: $variant->id,
        quantity: 3
    );

    $stockLevel = $variant->stockLevel()->firstOrFail();

    app(ReleaseStockReservation::class)->execute(
        reservationId: $reservation->id
    );

    $stockLevel->refresh();
    $reservation->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(10, $stockLevel->availableQuantity());

    $this->assertSame('RELEASED', $reservation->status);
    $this->assertNotNull($reservation->released_at);
    $this->assertNull($reservation->converted_at);
}

public function test_released_stock_reservation_cannot_be_released_again(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $reservation = app(CreateStockReservation::class)->execute(
        productVariantId: $variant->id,
        quantity: 3
    );

    app(ReleaseStockReservation::class)->execute(
        reservationId: $reservation->id
    );

    try {
        app(ReleaseStockReservation::class)->execute(
            reservationId: $reservation->id
        );

        $this->fail(
            'Une réservation déjà libérée ne devrait pas pouvoir être libérée à nouveau.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'reservation',
            $exception->errors()
        );
    }

    $stockLevel = $variant->stockLevel()->firstOrFail();
    $reservation->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(10, $stockLevel->availableQuantity());

    $this->assertSame('RELEASED', $reservation->status);
}

public function test_active_stock_reservation_can_be_expired(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $reservation = app(CreateStockReservation::class)->execute(
        productVariantId: $variant->id,
        quantity: 3
    );

    $reservation->expires_at = now()->subMinute();
$reservation->save();

    $stockLevel = $variant->stockLevel()->firstOrFail();

    app(ExpireStockReservation::class)->execute(
        reservationId: $reservation->id
    );

    $stockLevel->refresh();
    $reservation->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(10, $stockLevel->availableQuantity());

    $this->assertSame('EXPIRED', $reservation->status);
    $this->assertNotNull($reservation->released_at);
    $this->assertNull($reservation->converted_at);
}

public function test_active_stock_reservation_can_be_converted_to_sale(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $reservation = app(CreateStockReservation::class)->execute(
        productVariantId: $variant->id,
        quantity: 3
    );

    $stockLevel = $variant->stockLevel()->firstOrFail();

    app(ConvertStockReservation::class)->execute(
        reservationId: $reservation->id,
        userId: $movement->created_by
    );

    $stockLevel->refresh();
    $reservation->refresh();

    $this->assertSame(7, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(7, $stockLevel->availableQuantity());

    $this->assertSame('CONVERTED', $reservation->status);
    $this->assertNotNull($reservation->converted_at);
    $this->assertNull($reservation->released_at);

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $variant->store_id,
        'product_variant_id' => $variant->id,
        'type' => 'SALE',
        'quantity' => 3,
        'created_by' => $movement->created_by,
    ]);
}

public function test_converted_stock_reservation_cannot_be_converted_again(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    app(ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $movement->created_by,
        reason: 'Stock initial'
    );

    $reservation = app(CreateStockReservation::class)->execute(
        productVariantId: $variant->id,
        quantity: 3
    );

    app(ConvertStockReservation::class)->execute(
        reservationId: $reservation->id,
        userId: $movement->created_by
    );

    try {
        app(ConvertStockReservation::class)->execute(
            reservationId: $reservation->id,
            userId: $movement->created_by
        );

        $this->fail(
            'Une réservation convertie ne devrait pas pouvoir être convertie une deuxième fois.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'reservation',
            $exception->errors()
        );
    }

    $stockLevel = $variant->stockLevel()->firstOrFail();
    $reservation->refresh();

    $this->assertSame(7, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);
    $this->assertSame(7, $stockLevel->availableQuantity());

    $this->assertSame('CONVERTED', $reservation->status);

    $this->assertSame(
        1,
        StockMovement::query()
            ->where('product_variant_id', $variant->id)
            ->where('type', 'SALE')
            ->count()
    );
}

public function test_stock_movement_creator_must_belong_to_current_store(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    $otherStore = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $otherUser = User::factory()->create([
        'store_id' => $otherStore->id,
    ]);

    try {
        app(CreateStockMovement::class)->execute([
            'product_variant_id' => $variant->id,
            'type' => 'RECEIPT',
            'quantity' => 5,
            'reason' => 'Test cross-tenant',
            'created_by' => $otherUser->id,
        ]);

        $this->fail(
            'Un utilisateur d’une autre boutique ne devrait pas pouvoir être enregistré comme créateur du mouvement.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'created_by',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('stock_movements', [
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
        'quantity' => 5,
        'created_by' => $otherUser->id,
    ]);
}

public function test_stock_movement_quantity_must_be_a_whole_integer(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;

    try {
        app(CreateStockMovement::class)->execute([
            'product_variant_id' => $variant->id,
            'type' => 'RECEIPT',
            'quantity' => 1.5,
            'reason' => 'Fractional quantity test',
        ]);

        $this->fail(
            'Une quantité fractionnaire ne devrait pas être acceptée.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'quantity',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('stock_movements', [
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
        'quantity' => 1,
        'reason' => 'Fractional quantity test',
    ]);
}

public function test_active_reservation_cannot_expire_before_expiration_time(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;
    $stockLevel = $variant->stockLevel;

    $stockLevel->physical_quantity = 10;
    $stockLevel->save();

    $reservation = app(CreateStockReservation::class)->execute(
        $variant->id,
        3
    );

    $reservation->expires_at = now()->addHour();
    $reservation->save();

    try {
        app(ExpireStockReservation::class)->execute($reservation->id);

        $this->fail(
            'Une réservation ne devrait pas expirer avant son heure d’expiration.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'reservation',
            $exception->errors()
        );
    }

    $reservation->refresh();
    $stockLevel->refresh();

    $this->assertSame('ACTIVE', $reservation->status);
    $this->assertNull($reservation->released_at);
    $this->assertSame(3, $stockLevel->reserved_quantity);
}

public function test_stock_reservation_can_be_created_with_expiration_time(): void
{
    $movement = $this->createStockMovement();

    $variant = $movement->productVariant;
    $stockLevel = $variant->stockLevel;

    $stockLevel->physical_quantity = 10;
    $stockLevel->save();

   $expiresAt = now()
    ->addMinutes(30)
    ->startOfSecond();

    $reservation = app(CreateStockReservation::class)->execute(
        $variant->id,
        3,
        $expiresAt
    );

    $this->assertNotNull($reservation->expires_at);

    $this->assertTrue(
        $reservation->expires_at->equalTo($expiresAt)
    );

    $this->assertSame('ACTIVE', $reservation->status);
}

}