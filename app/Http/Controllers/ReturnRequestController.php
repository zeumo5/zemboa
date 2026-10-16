<?php

namespace App\Http\Controllers;

use App\Actions\Returns\RestockReturnRequest;
use App\Actions\Returns\InspectReturnRequest;
use App\Actions\Returns\ReceiveReturnRequest;
use App\Actions\Returns\ReviewReturnRequest;
use App\Actions\Returns\CreateReturnRequest;
use Illuminate\Http\Request;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class ReturnRequestController extends Controller
{
    /**
     * Liste des retours de la boutique courante.
     */
    public function index(): JsonResponse
    {
        $this->authorizeView();

        $storeId = app(TenantContext::class)->storeId();

        $returns = ReturnRequest::query()
            ->where('store_id', $storeId)
            ->with('items')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($returns);
    }

    /**
     * Détails d'un retour de la boutique.
     */
    public function show(int $returnRequestId): JsonResponse
    {
        $this->authorizeView();

        $storeId = app(TenantContext::class)->storeId();

        $return = ReturnRequest::query()
            ->where('store_id', $storeId)
            ->with('items')
            ->findOrFail($returnRequestId);

        return response()->json($return);
    }

    /**
     * Contrôle RBAC.
     */
    private function authorizeView(): void
    {
        $user = auth()->user();
        $storeId = app(TenantContext::class)->storeId();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('returns.view')
        ) {
            throw new AuthorizationException(
                'Consultation des retours non autorisée.'
            );
        }
    }


    /**
     * Créer une demande de retour SAV.
     */
    public function store(
        Request $request,
        CreateReturnRequest $action
    ): JsonResponse {
        $validated = $request->validate([
            'order_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.order_item_id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        // L'action contrôle également le RBAC,
        // l'isolation des boutiques et les quantités.
        $return = $action->execute(
            (int) $validated['order_id'],
            $validated['items'],
            $validated['reason']
        );

        return response()->json([
            'message' => 'Demande de retour créée avec succès.',
            'return' => $return,
        ], 201);
    }


    /**
     * Approuver ou rejeter une demande de retour.
     */
    public function review(
        Request $request,
        int $returnRequestId,
        ReviewReturnRequest $action
    ): JsonResponse {
        $validated = $request->validate([
            'decision' => [
                'required',
                'string',
                'in:APPROVED,REJECTED',
            ],
            'review_notes' => [
                'required_if:decision,REJECTED',
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        // L'action vérifie les autorisations,
        // la boutique et les transitions de statut.
        $return = $action->execute(
            $returnRequestId,
            $validated['decision'],
            $validated['review_notes'] ?? null
        );

        return response()->json([
            'message' => $return->status === 'APPROVED'
                ? 'Demande de retour approuvée.'
                : 'Demande de retour rejetée.',
            'return' => $return,
        ]);
    }


    /**
     * Confirmer la réception physique des articles retournés.
     */
    public function receive(
        int $returnRequestId,
        ReceiveReturnRequest $action
    ): JsonResponse {
        // L'action vérifie les permissions,
        // la boutique et le statut APPROVED.
        $return = $action->execute($returnRequestId);

        return response()->json([
            'message' => 'Réception du retour confirmée.',
            'return' => $return,
        ]);
    }


    /**
     * Inspecter les articles retournés.
     */
    public function inspect(
        Request $request,
        int $returnRequestId,
        InspectReturnRequest $action
    ): JsonResponse {
        $validated = $request->validate([
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.return_request_item_id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
            'items.*.restockable_quantity' => [
                'required',
                'integer',
                'min:0',
            ],
            'items.*.inspection_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        // L'action contrôle le RBAC,
        // l'isolation multiboutiques et les quantités.
        $return = $action->execute(
            $returnRequestId,
            $validated['items']
        );

        return response()->json([
            'message' => 'Inspection du retour terminée.',
            'return' => $return,
        ]);
    }


    /**
     * Remettre en stock les articles revendables
     * après inspection d'un retour SAV.
     */
    public function restock(
        int $returnRequestId,
        RestockReturnRequest $action
    ): JsonResponse {
        // L'action vérifie :
        // - les permissions RBAC ;
        // - l'isolation de la boutique ;
        // - le statut INSPECTED ;
        // - les quantités revendables ;
        // - l'absence de double remise en stock.

        $return = $action->execute($returnRequestId);

        return response()->json([
            'message' => 'Remise en stock du retour terminée.',
            'return' => $return,
        ]);
    }
}
