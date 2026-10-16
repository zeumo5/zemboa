<?php

namespace App\Http\Controllers;

use App\Actions\Deliveries\ConfirmDeliveryReturn;
use App\Actions\Deliveries\AssignDeliveryAgent;
use App\Actions\Deliveries\UpdateDeliveryStatus;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    /**
     * Liste des livraisons accessibles à l'utilisateur.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizeViewer($request);

        $query = Delivery::query()
            ->where('store_id', $user->store_id);


        if (
            ! $user->hasRole('STORE_OWNER') &&
            ! $user->hasRole('STORE_MANAGER')
        ) {
            // Le livreur voit uniquement ses livraisons.
            $query->where('assigned_to', $user->id);
        }


        return response()->json(
            $query->orderByDesc('id')->paginate(20)
        );
    }

    /**
     * Détails d'une livraison autorisée.
     */
    public function show(
        Request $request,
        int $deliveryId
    ): JsonResponse {
        $user = $this->authorizeViewer($request);

        $query = Delivery::query()
            ->where('store_id', $user->store_id);


        if (
            ! $user->hasRole('STORE_OWNER') &&
            ! $user->hasRole('STORE_MANAGER')
        ) {
            // Seul le livreur est limité
            // aux livraisons qui lui sont attribuées.
            $query->where('assigned_to', $user->id);
        }


        $delivery = $query
            ->whereKey($deliveryId)
            ->firstOrFail();

        return response()->json([
            'delivery' => $delivery,
        ]);
    }

    /**
     * Affectation d'un livreur par le commerçant.
     */
    public function assign(
        Request $request,
        int $orderId,
        AssignDeliveryAgent $action
    ): JsonResponse {
        $validated = $request->validate([
            'assigned_to' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        // L'action vérifie le rôle, la boutique
        // et l'éligibilité de la commande.
        $delivery = $action->execute(
            $orderId,
            (int) $validated['assigned_to']
        );

        return response()->json([
            'message' => 'Livreur affecté avec succès.',
            'delivery' => $delivery,
        ], 201);
    }

    /**
     * Mise à jour du suivi par le livreur affecté.
     */
    public function updateStatus(
        Request $request,
        int $deliveryId,
        UpdateDeliveryStatus $action
    ): JsonResponse {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                'in:PICKED_UP,DELIVERED,FAILED',
            ],
            'failure_reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        // L'action vérifie les autorisations
        // et les transitions de statut.
        $delivery = $action->execute(
            $deliveryId,
            $validated['status'],
            $validated['failure_reason'] ?? null
        );

        return response()->json([
            'message' => 'Statut de livraison mis à jour.',
            'delivery' => $delivery,
        ]);
    }

    /**
     * Autorisation de consultation.
     */

    private function authorizeViewer(Request $request): User
    {
        $user = $request->user();

        if (
            ! $user instanceof User ||
            $user->store_id === null ||
            ! $user->hasPermission('deliveries.view') ||
            (
                ! $user->hasRole('STORE_OWNER') &&
                ! $user->hasRole('STORE_MANAGER') &&
                ! $user->hasRole('DELIVERY_AGENT')
            )
        ) {
            throw new AuthorizationException(
                'Accès aux livraisons non autorisé.'
            );
        }

        return $user;
    }



    /**
     * Confirmer la réception physique d'un colis retourné.
     *
     * Seul le commerçant autorisé peut effectuer cette action.
     */
    public function confirmReturn(
        Request $request,
        int $deliveryId,
        ConfirmDeliveryReturn $action
    ): JsonResponse {
        $validated = $request->validate([
            'return_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $delivery = $action->execute(
            $deliveryId,
            $validated['return_notes'] ?? null
        );

        return response()->json([
            'message' => 'Retour physique du colis confirmé.',
            'delivery' => $delivery,
        ]);
    }
}
