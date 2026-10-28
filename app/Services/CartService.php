<?php

namespace App\Services;

use App\Actions\Orders\OrderPriceCalculator;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\PublicShopping;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ! is_array($cart) ||
            (int) ($cart['store_id'] ?? 0) !== $this->storeId()
        ) {
            return [];
        }

        return $cart['items'] ?? [];
    }

    public function summary(?CarbonInterface $at = null): array
    {
        $items = $this->items();

        $at ??= now();
        $details = [];
        $pricingItems = [];
        $canCheckout = $items !== [];

        foreach ($items as $variantId => $quantity) {
            $quantity = (int) $quantity;

            // Recherche limitée à la boutique courante.
            $variant = ProductVariant::query()
                ->with(['product.category', 'product.images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('position')->orderBy('id'), 'attributeValues.attribute', 'stockLevel'])
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
                    'product_id' => $variant?->product?->id,
                    'quantity' => $quantity,
                    'available' => false,
                    'message' => 'Article indisponible.',
                ];

                $canCheckout = false;

                continue;
            }

            $unitPrice = (string) $variant->price;
            $finalPrice = (string) $variant->effectivePrice($at);

            $unitFcfa = $this->priceCalculator->toFcfa($unitPrice);
            $finalFcfa = $this->priceCalculator->toFcfa($finalPrice);

            $availableStock = $variant->stockLevel?->availableQuantity() ?? 0;
            $available = $availableStock >= $quantity;

            if (! $available) {
                $canCheckout = false;
            }

            $details[] = [
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product->id,
                'product_name' => $variant->product->name,
                'sku' => $variant->sku,
                'variant_label' => $variant->attributeValues->filter(fn ($value) => $value->attribute !== null)
                    ->map(fn ($value) => $value->attribute->name.': '.$value->value)->implode(' / '),
                'image_url' => ($image = $variant->product->images->first())
                    ? Storage::disk('public')->url($image->path) : null,
                'product_url' => route('marketplace.products.show', ['shopSlug' => app(TenantContext::class)->store()->slug, 'product' => $variant->product->id]),
                'quantity' => $quantity,
                'unit_price' => $unitFcfa.'.00',
                'final_unit_price' => $finalFcfa.'.00',
                'line_total' => ($finalFcfa * $quantity).'.00',
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
            'counts' => self::countDetails($details),
            'products_subtotal' => $totals['total'],
        ];
    }

    /** Read-only badge projection, also usable outside a tenant route. */
    public static function sessionCounts(): array
    {
        $cart = session(self::SESSION_KEY, []);
        $items = $cart['items'] ?? [];
        if (! $items) {
            return self::countDetails([]);
        }
        // Never remove tenant filtering implicitly: both sides are explicitly scoped.
        $products = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('v.store_id', (int) ($cart['store_id'] ?? 0))
            ->where('p.store_id', (int) ($cart['store_id'] ?? 0))
            ->whereIn('v.id', array_keys($items))
            ->get(['p.id as product_id', 'v.id as variant_id'])->pluck('product_id', 'variant_id');
        $details = [];
        foreach ($items as $id => $quantity) {
            $details[] = ['product_id' => $products[$id] ?? null, 'quantity' => $quantity];
        }

        return self::countDetails($details);
    }

    private static function countDetails(array $details): array
    {
        return [
            'distinct_products' => count(array_unique(array_filter(array_column($details, 'product_id')))),
            'units' => array_sum(array_column($details, 'quantity')),
            'unidentified_lines' => count(array_filter($details, fn ($line) => empty($line['product_id']))),
        ];
    }

    public function add(int $variantId, int $quantity): array
    {
        PublicShopping::assertAllowed();
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
        PublicShopping::assertAllowed();
        $this->validateQuantity($quantity);

        $items = $this->editableItems();

        if (! array_key_exists($variantId, $items)) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Cet article ne figure pas dans le panier.',
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
        PublicShopping::assertAllowed();
        $items = $this->editableItems();

        unset($items[$variantId]);

        $this->save($items);

        return $items;
    }

    public function clear(): void
    {
        PublicShopping::assertAllowed();
        $this->editableItems();
        session()->forget(self::SESSION_KEY);
    }

    /** Public owner metadata and an opaque, session/state/target-bound confirmation. */
    public function foreignCart(): ?array
    {
        $cart = session(self::SESSION_KEY, []);
        if (empty($cart['items']) || (int) ($cart['store_id'] ?? 0) === $this->storeId()) {
            return null;
        }
        $owner = Store::query()->where('status', 'ACTIVE')->find((int) ($cart['store_id'] ?? 0));

        return [
            'cart_url' => $owner ? route('marketplace.shops.cart', ['shopSlug' => $owner->slug]) : null,
            'confirmation_token' => $owner ? $this->switchToken($cart) : null,
            'switch_url' => $owner ? route('public.shops.cart.switch', ['shopSlug' => app(TenantContext::class)->store()->slug]) : null,
        ];
    }

    public function switchStore(string $confirmationToken): void
    {
        PublicShopping::assertAllowed();
        $cart = session(self::SESSION_KEY, []);
        $foreign = $this->foreignCart();
        if (! $foreign || ! $foreign['confirmation_token'] || ! hash_equals($foreign['confirmation_token'], $confirmationToken)) {
            throw ValidationException::withMessages(['confirmation_token' => 'Cart confirmation is obsolete or unavailable.']);
        }
        // Deliberately separate from clear(): explicit abandonment of this session only.
        session()->forget(self::SESSION_KEY);
    }

    private function switchToken(array $cart): string
    {
        abort_unless(is_string(config('app.key')) && strlen(config('app.key')) >= 32, 503);

        return hash_hmac('sha256', session()->getId().'|'.$this->storeId().'|'.serialize($cart), (string) config('app.key'));
    }

    private function editableItems(): array
    {
        $cart = session()->get(self::SESSION_KEY, []);

        if (
            ! empty($cart['items']) &&
            (int) ($cart['store_id'] ?? 0) !== $this->storeId()
        ) {
            throw ValidationException::withMessages([
                'cart' => 'Le panier appartient à une autre boutique. Videz-le avant de changer de boutique.',
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
                'quantity' => 'Quantité supérieure au stock disponible.',
            ]);
        }
    }

    private function validateQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La quantité doit être supérieure à zéro.',
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
            'revision' => bin2hex(random_bytes(16)),
        ]);
    }

    private function storeId(): int
    {
        return app(TenantContext::class)->storeId();
    }
}
