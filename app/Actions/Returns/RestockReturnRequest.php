<?php
namespace App\Actions\Returns;


use App\Actions\Stock\ReturnStock;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\StockReservation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestockReturnRequest
{
    public function __construct(
        private readonly ReturnStock $returnStock
    ) {
    }

    public function execute(int $returnRequestId): ReturnRequest
    {
        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('returns.manage') ||
            ! $user->hasPermission('inventory.manage')
        ) {
            throw new AuthorizationException(
                'Remise en stock non autorisée.'
            );
        }

        return DB::transaction(function () use (
            $returnRequestId,
            $storeId,
            $user
        ) {
            $snapshot = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->firstOrFail();

            // Ordre des verrouillages :
            // commande, demande, articles, stock.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $returnRequest = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $returnRequest->order_id !== (int) $order->id ||
                (int) $returnRequest->store_id !== (int) $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'return' => 'Demande et commande incompatibles.',
                ]);
            }

            if (
                $returnRequest->status !== 'INSPECTED' ||
                $returnRequest->inspected_at === null ||
                $returnRequest->received_at === null
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Le retour doit être réceptionné et inspecté.',
                ]);
            }

            if ($order->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'payment_status' =>
                        'La commande doit être intégralement payée.',
                ]);
            }

            $items = $returnRequest->items()
                ->with('orderItem')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn ($item) =>
                    (int) ($item->orderItem?->product_variant_id ?? 0)
                );

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Aucun article à traiter.',
                ]);
            }

            // Valider tous les articles avant
            // le premier mouvement de stock.
            foreach ($items as $item) {
                if ($item->restocked_at !== null) {
                    throw ValidationException::withMessages([
                        'stock' =>
                            'Ce retour a déjà été traité.',
                    ]);
                }

                if (
                    $item->restockable_quantity === null ||
                    $item->restockable_quantity < 0 ||
                    $item->restockable_quantity > $item->quantity
                ) {
                    throw ValidationException::withMessages([
                        'stock' =>
                            'Quantité revendable invalide.',
                    ]);
                }

                $orderItem = $item->orderItem;

                if (
                    $orderItem === null ||
                    (int) $orderItem->order_id !== (int) $order->id ||
                    $orderItem->product_variant_id === null
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Article incompatible avec la commande.',
                    ]);
                }

                // Une vente doit avoir réellement
                // décrémenté le stock avant son retour.
                $converted = StockReservation::query()
                    ->where('store_id', $storeId)
                    ->where('reference_type', 'ORDER')
                    ->where('reference_id', $order->id)
                    ->where(
                        'product_variant_id',
                        $orderItem->product_variant_id
                    )
                    ->where('status', 'CONVERTED')
                    ->exists();

                if (! $converted) {
                    throw ValidationException::withMessages([
                        'stock' =>
                            'Aucune conversion de stock en vente trouvée.',
                    ]);
                }
            }

            foreach ($items as $item) {
                $quantity = (int) $item->restockable_quantity;

                if ($quantity > 0) {
                    $this->returnStock->execute(
                        productVariantId:
                            (int) $item->orderItem->product_variant_id,
                        quantity: $quantity,
                        userId: $user->id,
                        reason:
                            'Retour SAV #' . $returnRequest->id .
                            ' - article #' . $item->id
                    );
                }

                // Marquer le traitement terminé,
                // même lorsque la quantité revendable est zéro.
                $item->restocked_at = now();
                $item->save();
            }

            return $returnRequest->refresh()->load('items');
        }, 3);
    }
}
