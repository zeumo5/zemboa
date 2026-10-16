<?php

namespace App\Http\Controllers;


use App\Actions\Refunds\SimulateRefund;
use App\Models\Refund;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use App\Actions\Refunds\ApproveRefund;
use App\Actions\Refunds\PrepareRefund;
use Illuminate\Http\JsonResponse;

class RefundController extends Controller
{
    /**
     * Préparer un remboursement simulé.
     */
    public function store(
        int $returnRequestId,
        PrepareRefund $action
    ): JsonResponse {
        // L'action vérifie :
        // - les permissions du commerçant ;
        // - l'isolation multiboutiques ;
        // - l'inspection du retour ;
        // - les paiements confirmés ;
        // - le plafond des remboursements ;
        // - l'absence de doublon.

        $refund = $action->execute($returnRequestId);

        return response()->json([
            'message' => 'Remboursement préparé avec succès.',
            'refund' => $refund,
        ], 201);
    }


    /**
     * Approuver un remboursement préparé.
     */
    public function approve(
        int $refundId,
        ApproveRefund $action
    ): JsonResponse {
        // L'action vérifie :
        // - les permissions RBAC ;
        // - l'isolation multiboutiques ;
        // - le statut PENDING ;
        // - les paiements confirmés ;
        // - le plafond des remboursements.

        $refund = $action->execute($refundId);

        return response()->json([
            'message' => 'Remboursement approuvé avec succès.',
            'refund' => $refund,
        ]);
    }


    /**
     * Liste des remboursements de la boutique.
     */
    public function index(): JsonResponse
    {
        $this->authorizeView();

        $storeId = app(TenantContext::class)->storeId();

        $refunds = Refund::query()
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($refunds);
    }

    /**
     * Consulter un remboursement précis.
     */
    public function show(int $refundId): JsonResponse
    {
        $this->authorizeView();

        $storeId = app(TenantContext::class)->storeId();

        $refund = Refund::query()
            ->where('store_id', $storeId)
            ->whereKey($refundId)
            ->firstOrFail();

        return response()->json([
            'refund' => $refund,
        ]);
    }

    /**
     * Enregistrer la simulation finale.
     * Aucun transfert d'argent réel.
     */
    public function simulate(
        int $refundId,
        SimulateRefund $action
    ): JsonResponse {
        $refund = $action->execute($refundId);

        return response()->json([
            'message' => 'Simulation du remboursement enregistrée.',
            'refund' => $refund,
        ]);
    }

    /**
     * Autoriser la consultation des remboursements.
     */
    private function authorizeView(): void
    {
        $user = auth()->user();
        $storeId = app(TenantContext::class)->storeId();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $user->hasRole('STORE_OWNER') ||
            ! $user->hasPermission('refunds.view')
        ) {
            throw new AuthorizationException(
                'Consultation des remboursements non autorisée.'
            );
        }
    }
}
