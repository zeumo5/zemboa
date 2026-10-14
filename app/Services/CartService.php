<?php
namespace App\Services;

use App\Actions\Orders\OrderPriceCalculator;
use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

final class CartService
{
    private const SESSION_KEY = 'zemboa.cart';

    public function __construct(
    private readonly OrderPriceCalculator $priceCalculator
) {}

    public function items(): array
    {
        $cart = session()->get(self::SESSION_KEY, []);

        if (
            !is_array($cart) ||
            (int) ($cart['store_id'] ?? 0) !== $this->storeId()
        ) {
            return [];
        }

        return $cart['items'] ?? [];
    }

public function summary(): array
{
    $items = $this->items();

    $details = [];
    $pricingItems = [];
    $canCheckout = $items !== [];

    foreach ($items as $variantId => $quantity) {
        $quantity = (int) $quantity;

        // Recherche limitée à la boutique courante.
        $variant = ProductVariant::query()
            ->with(['product.category', 'stockLevel'])
            ->find((int) $variantId);

        // Un article retiré du catalogue reste visible
        // comme indisponible dans le panier.
        if (
            $variant === null ||
            $variant->status !== 'ACTIVE' ||
            $variant->product === null ||
            $variant->product->status !== 'ACTIVE' ||
            $variant->product->category === null ||
            $variant->product->category->status !== 'ACTIVE' ||
            $quantity <= 0
        ) {
            $details[] = [
                'product_variant_id' => (int) $variantId,
                'quantity' => $quantity,
                'available' => false,
                'message' => 'Article indisponible.',
            ];

            $canCheckout = false;
            continue;
        }

        $unitPrice = (string) $variant->price;
        $finalPrice = (string) $variant->effectivePrice();

        $unitFcfa = $this->priceCalculator->toFcfa($unitPrice);
        $finalFcfa = $this->priceCalculator->toFcfa($finalPrice);

        $availableStock = $variant->stockLevel?->availableQuantity() ?? 0;
        $available = $availableStock >= $quantity;

        if (! $available) {
            $canCheckout = false;
        }

        $details[] = [
            'product_variant_id' => $variant->id,
            'product_name' => $variant->product->name,
            'sku' => $variant->sku,
            'quantity' => $quantity,
            'unit_price' => $unitFcfa . '.00',
            'final_unit_price' => $finalFcfa . '.00',
            'line_total' => ($finalFcfa * $quantity) . '.00',
            'available' => $available,
        ];

        $pricingItems[] = [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'final_unit_price' => $finalPrice,
        ];
    }

    $totals = $this->priceCalculator->calculate(
        items: $pricingItems
    );

    return [
        'details' => $details,
        'totals' => $totals,
        'can_checkout' => $canCheckout,
    ];
}


    public function add(int $variantId, int $quantity): array
    {
        $this->validateQuantity($quantity);

        $items = $this->editableItems();
        $variant = $this->sellableVariant($variantId);

        $newQuantity = (int) ($items[$variantId] ?? 0)
            + $quantity;

        $this->checkStock($variant, $newQuantity);

        $items[$variantId] = $newQuantity;

        $this->save($items);

        return $items;
    }

    public function update(int $variantId, int $quantity): array
    {
        $this->validateQuantity($quantity);

        $items = $this->editableItems();

        if (!array_key_exists($variantId, $items)) {
            throw ValidationException::withMessages([
                'product_variant_id' =>
                    'Cet article ne figure pas dans le panier.',
            ]);
        }

        $variant = $this->sellableVariant($variantId);

        $this->checkStock($variant, $quantity);

        $items[$variantId] = $quantity;

        $this->save($items);

        return $items;
    }

    public function remove(int $variantId): array
    {
        $items = $this->editableItems();

        unset($items[$variantId]);

        $this->save($items);

        return $items;
    }

   public function clear(): void
{
    session()->forget(self::SESSION_KEY);
}

    private function editableItems(): array
    {
        $cart = session()->get(self::SESSION_KEY, []);

        if (
            !empty($cart['items']) &&
            (int) ($cart['store_id'] ?? 0) !== $this->storeId()
        ) {
            throw ValidationException::withMessages([
                'cart' =>
                    'Le panier appartient à une autre boutique. Videz-le avant de changer de boutique.',
            ]);
        }

        return $this->items();
    }

    private function sellableVariant(int $variantId): ProductVariant
    {
        return ProductVariant::query()
            ->where('status', 'ACTIVE')
            ->whereHas('product', function ($query) {
                $query->where('status', 'ACTIVE')
                    ->whereHas('category', function ($category) {
                        $category->where('status', 'ACTIVE');
                    });
            })
            ->with('stockLevel')
            ->findOrFail($variantId);
    }

    private function checkStock(
        ProductVariant $variant,
        int $quantity
    ): void {
        $available = $variant->stockLevel
            ? $variant->stockLevel->availableQuantity()
            : 0;

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'quantity' =>
                    'Quantité supérieure au stock disponible.',
            ]);
        }
    }

    private function validateQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' =>
                    'La quantité doit être supérieure à zéro.',
            ]);
        }
    }

    private function save(array $items): void
    {
        if ($items === []) {
            session()->forget(self::SESSION_KEY);
            return;
        }

        session()->put(self::SESSION_KEY, [
            'store_id' => $this->storeId(),
            'items' => $items,
        ]);
    }

    private function storeId(): int
    {
        return app(TenantContext::class)->storeId();
    }
}
