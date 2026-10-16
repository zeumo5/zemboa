<?php
namespace App\Actions\Returns;


use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InspectReturnRequest
{
    public function execute(
        int $returnRequestId,
        array $items
    ): ReturnRequest {
        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        // Autorisation du commerçant.
        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('returns.manage')
        ) {
            throw new AuthorizationException(
                'Inspection du retour non autorisée.'
            );
        }

        $validated = Validator::make(
            ['items' => $items],
            [
                'items' => 'required|array|min:1',
                'items.*.return_request_item_id' =>
                    'required|integer|min:1|distinct',
                'items.*.restockable_quantity' =>
                    'required|integer|min:0',
                'items.*.inspection_notes' =>
                    'nullable|string|max:1000',
            ]
        )->validate();

        return DB::transaction(function () use (
            $returnRequestId,
            $storeId,
            $user,
            $validated
        ) {
            // Identifier la commande.
            $snapshot = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->firstOrFail();

            // Verrouiller la commande en premier.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Verrouiller la demande ensuite.
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
                    'return' =>
                        'La demande ne correspond pas à cette commande.',
                ]);
            }

            // Inspection uniquement après réception physique.
            if (
                $returnRequest->status !== 'RECEIVED' ||
                $returnRequest->received_at === null
            ) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Le retour doit être réceptionné avant inspection.',
                ]);
            }

            // Interdire une deuxième inspection.
            if ($returnRequest->inspected_at !== null) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Ce retour a déjà été inspecté.',
                ]);
            }

            $returnItems = $returnRequest->items()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Tous les articles du retour doivent être inspectés.
            if (
                $returnItems->isEmpty() ||
                count($validated['items']) !== $returnItems->count()
            ) {
                throw ValidationException::withMessages([
                    'items' =>
                        'Tous les articles retournés doivent être inspectés.',
                ]);
            }

            foreach ($validated['items'] as $item) {
                $itemId = (int) $item['return_request_item_id'];
                $restockable = (int) $item['restockable_quantity'];

                $returnItem = $returnItems->get($itemId);

                if ($returnItem === null) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Un article ne fait pas partie de ce retour.',
                    ]);
                }

                if ($restockable > (int) $returnItem->quantity) {
                    throw ValidationException::withMessages([
                        'restockable_quantity' =>
                            'La quantité revendable dépasse la quantité retournée.',
                    ]);
                }

                // Enregistrer uniquement le résultat d'inspection.
                $returnItem->restockable_quantity = $restockable;

                $notes = trim($item['inspection_notes'] ?? '');

                $returnItem->inspection_notes =
                    $notes !== '' ? $notes : null;

                $returnItem->save();
            }

            // Historiser l'inspection.
            $returnRequest->status = 'INSPECTED';
            $returnRequest->inspected_at = now();
            $returnRequest->inspected_by = $user->id;
            $returnRequest->save();

            return $returnRequest->refresh()->load('items');
        }, 3);
    }
}
