<?php

namespace App\Actions\Returns;


use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreateReturnRequest
{
    public function execute(
        int $orderId,
        array $items,
        string $reason
    ): ReturnRequest {
        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('returns.manage')
        ) {
            throw new AuthorizationException(
                'Création de demande de retour non autorisée.'
            );
        }

        $validated = Validator::make([
            'reason' => trim($reason),
            'items' => $items,
        ], [
            'reason' => 'required|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' =>
            'required|integer|min:1|distinct',
            'items.*.quantity' =>
            'required|integer|min:1',
        ])->validate();

        return DB::transaction(function () use (
            $orderId,
            $storeId,
            $validated
        ) {
            // Verrouiller la commande pour éviter
            // deux demandes concurrentes excessives.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $settings = StoreSetting::query()
                ->where('store_id', $storeId)
                ->first();

            if (
                $settings === null ||
                ! $settings->returns_enabled ||
                (int) $settings->return_window_days <= 0
            ) {
                throw ValidationException::withMessages([
                    'returns' =>
                    'Les retours sont désactivés pour cette boutique.',
                ]);
            }

            if ($order->payment_status !== 'PAID') {
                throw ValidationException::withMessages([
                    'order' =>
                    'La commande doit être intégralement payée.',
                ]);
            }

            // Pour le moment : livraisons réellement terminées.
            $delivery = $order->delivery()
                ->where('status', 'DELIVERED')
                ->first();

            if (
                $delivery === null ||
                $delivery->delivered_at === null ||
                $delivery->delivered_at->isFuture()
            ) {
                throw ValidationException::withMessages([
                    'delivery' =>
                    'La réception de cette commande n’est pas confirmée.',
                ]);
            }

            $deadline = $delivery->delivered_at
                ->copy()
                ->addDays((int) $settings->return_window_days);

            if ($deadline->isPast()) {
                throw ValidationException::withMessages([
                    'returns' =>
                    'Le délai autorisé pour le retour est dépassé.',
                ]);
            }

            // Articles réellement présents dans cette commande.
            $orderedItems = $order->items()
                ->get()
                ->keyBy('id');

            // Quantités déjà demandées dans les retours
            // qui ne sont pas rejetés.
            $alreadyRequested = ReturnRequestItem::query()
                ->whereHas('returnRequest', function ($query) use (
                    $storeId,
                    $order
                ) {
                    $query
                        ->where('store_id', $storeId)
                        ->where('order_id', $order->id)
                        ->where('status', '!=', 'REJECTED');
                })
                ->selectRaw(
                    'order_item_id, SUM(quantity) as total_quantity'
                )
                ->groupBy('order_item_id')
                ->pluck('total_quantity', 'order_item_id');

            foreach ($validated['items'] as $item) {
                $itemId = (int) $item['order_item_id'];
                $quantity = (int) $item['quantity'];

                $orderedItem = $orderedItems->get($itemId);

                if ($orderedItem === null) {
                    throw ValidationException::withMessages([
                        'items' =>
                        'Un article ne fait pas partie de cette commande.',
                    ]);
                }

                $previousQuantity = (int) $alreadyRequested
                    ->get($itemId, 0);

                if (
                    $quantity + $previousQuantity >
                    (int) $orderedItem->quantity
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                        'La quantité demandée dépasse la quantité retournable.',
                    ]);
                }
            }

            // Créer la demande avec son statut
            // initial REQUESTED défini dans la migration.
            $returnRequest = ReturnRequest::create([
                'order_id' => $order->id,
                'reason' => $validated['reason'],
            ]);

            foreach ($validated['items'] as $item) {
                $returnRequest->items()->create([
                    'order_item_id' => $item['order_item_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            // Recharger les valeurs générées par la base de données,
            // notamment le statut REQUESTED.
            $returnRequest->refresh();

            return $returnRequest->load('items');
        }, 3);
    }
}
