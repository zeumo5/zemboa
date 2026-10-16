<?php
namespace App\Actions\Returns;


use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceiveReturnRequest
{
    public function execute(int $returnRequestId): ReturnRequest
    {
        $storeId = app(TenantContext::class)->storeId();
        $user = auth()->user();

        // Autorisation RBAC et isolation multiboutiques.
        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('returns.manage')
        ) {
            throw new AuthorizationException(
                'Confirmation de réception non autorisée.'
            );
        }

        return DB::transaction(function () use (
            $returnRequestId,
            $storeId,
            $user
        ) {
            // Identifier la commande associée.
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

            // Verrouiller ensuite la demande de retour.
            $returnRequest = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            // Vérifier la cohérence de la boutique.
            if (
                (int) $returnRequest->order_id !== (int) $order->id ||
                (int) $returnRequest->store_id !== (int) $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'return' =>
                        'La demande ne correspond pas à cette commande.',
                ]);
            }

            // Seule une demande approuvée peut être réceptionnée.
            if ($returnRequest->status !== 'APPROVED') {
                throw ValidationException::withMessages([
                    'status' =>
                        'Seule une demande approuvée peut être réceptionnée.',
                ]);
            }

            // Empêcher une double confirmation.
            if ($returnRequest->received_at !== null) {
                throw ValidationException::withMessages([
                    'received_at' =>
                        'Ce retour a déjà été réceptionné.',
                ]);
            }

            // Vérifier la présence des articles.
            if (! $returnRequest->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' =>
                        'Cette demande ne contient aucun article.',
                ]);
            }

            // Enregistrer uniquement la réception physique.
            $returnRequest->status = 'RECEIVED';
            $returnRequest->received_at = now();
            $returnRequest->received_by = $user->id;

            $returnRequest->save();

            return $returnRequest->refresh();
        }, 3);
    }
}
