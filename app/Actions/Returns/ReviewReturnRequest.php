<?php
namespace App\Actions\Returns;


use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewReturnRequest
{
    public function execute(
        int $returnRequestId,
        string $decision,
        ?string $notes = null
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
                'Examen du retour non autorisé.'
            );
        }

        if (! in_array($decision, [
            'APPROVED',
            'REJECTED',
        ], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Décision invalide.',
            ]);
        }

        $notes = trim($notes ?? '');

        if (mb_strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'review_notes' =>
                    'Le commentaire ne doit pas dépasser 1000 caractères.',
            ]);
        }

        if ($decision === 'REJECTED' && $notes === '') {
            throw ValidationException::withMessages([
                'review_notes' =>
                    'Un motif est obligatoire pour refuser un retour.',
            ]);
        }

        return DB::transaction(function () use (
            $returnRequestId,
            $storeId,
            $user,
            $decision,
            $notes
        ) {
            // Identifier la commande concernée.
            $snapshot = ReturnRequest::query()
                ->where('store_id', $storeId)
                ->whereKey($returnRequestId)
                ->firstOrFail();

            // Verrouiller d'abord la commande.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($snapshot->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Verrouiller ensuite la demande.
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
                        'Demande et commande incompatibles.',
                ]);
            }

            if ($returnRequest->status !== 'REQUESTED') {
                throw ValidationException::withMessages([
                    'status' =>
                        'Cette demande a déjà été examinée.',
                ]);
            }

            // Vérifier que la commande est toujours éligible
            // avant d'autoriser le retour.
            if (
                $decision === 'APPROVED' &&
                $order->payment_status !== 'PAID'
            ) {
                throw ValidationException::withMessages([
                    'order' =>
                        'La commande doit être intégralement payée.',
                ]);
            }

            // Enregistrer la décision du commerçant.
            $returnRequest->status = $decision;
            $returnRequest->reviewed_by = $user->id;
            $returnRequest->reviewed_at = now();
            $returnRequest->review_notes =
                $notes !== '' ? $notes : null;

            $returnRequest->save();

            return $returnRequest->refresh();
        }, 3);
    }
}
