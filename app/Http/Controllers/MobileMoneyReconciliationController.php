<?php
namespace App\Http\Controllers;

use App\Actions\Payments\ResolveMobileMoneyReconciliationCase;
use App\Models\MobileMoneyReconciliationCase;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileMoneyReconciliationController extends Controller
{
    /**
     * Consulter les dossiers de réconciliation
     * appartenant uniquement à sa boutique.
     */
    public function index(Request $request): JsonResponse
    {
        // 1. Vérifier l'authentification.
        $user = $request->user();

        if (!$user instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // 2. Vérifier la permission RBAC.
        if (!$user->hasPermission(
            'payments.reconciliation.view'
        )) {
            abort(403, 'Permission de consultation refusée.');
        }

        // 3. Exiger une boutique associée.
        if ($user->store_id === null) {
            abort(403, 'Aucune boutique autorisée.');
        }

        // 4. Limiter explicitement les dossiers
        // à la boutique de l'utilisateur.
        $cases = MobileMoneyReconciliationCase::query()
            ->where('store_id', $user->store_id)
            ->select([
                'id',
                'payment_id',
                'provider',
                'payment_reference',
                'amount',
                'currency',
                'status',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        // 5. Retourner les dossiers en JSON.
        return response()->json($cases);
    }


    /**
     * Consulter un dossier précis de sa boutique.
     */
    public function show(
        Request $request,
        int $caseId
    ): JsonResponse {
        // 1. Vérifier l'utilisateur connecté.
        $user = $request->user();

        if (!$user instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // 2. Vérifier la permission RBAC.
        if (!$user->hasPermission(
            'payments.reconciliation.view'
        )) {
            abort(403, 'Permission de consultation refusée.');
        }

        // 3. Vérifier l'appartenance à une boutique.
        if ($user->store_id === null) {
            abort(403, 'Aucune boutique autorisée.');
        }

        // 4. Retrouver uniquement un dossier
        // appartenant à la boutique de l'utilisateur.
        $case = MobileMoneyReconciliationCase::query()
            ->where('store_id', $user->store_id)
            ->whereKey($caseId)
            ->select([
                'id',
                'payment_id',
                'provider',
                'payment_reference',
                'provider_transaction_id',
                'amount',
                'currency',
                'original_payment_status',
                'reported_provider_status',
                'status',
                'reason',
                'resolution_notes',
                'resolved_at',
                'created_at',
            ])
            ->firstOrFail();

        // 5. Retourner le dossier en JSON.
        return response()->json([
            'data' => $case,
        ]);
    }


    /**
     * Clôturer administrativement un dossier
     * de réconciliation Mobile Money.
     */
    public function resolve(
        Request $request,
        int $caseId
    ): JsonResponse {
        // 1. Vérifier l'utilisateur authentifié.
        $user = $request->user();

        if (!$user instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // 2. Vérifier la permission RBAC
        // avant de traiter les données reçues.
        if (!$user->hasPermission(
            'payments.reconciliation.resolve'
        )) {
            abort(403, 'Permission de résolution refusée.');
        }

        // 3. Valider les notes de résolution.
        $validated = $request->validate([
            'resolution_notes' => [
                'required',
                'string',
                'min:15',
                'max:2000',
            ],
        ]);

        // 4. Appeler l'action métier sécurisée.
        // Elle revérifie les permissions, la boutique
        // et verrouille le dossier dans une transaction SQL.
        $case = app(
            ResolveMobileMoneyReconciliationCase::class
        )->execute(
            $caseId,
            $validated['resolution_notes']
        );

        // 5. Retourner uniquement les informations
        // nécessaires à la confirmation administrative.
        return response()->json([
            'message' => 'Dossier clôturé administrativement.',
            'data' => [
                'id' => $case->id,
                'status' => $case->status,
                'resolution_notes' => $case->resolution_notes,
                'resolved_at' => $case->resolved_at,
                'resolved_by' => $case->resolved_by,
            ],
        ]);
    }


}
