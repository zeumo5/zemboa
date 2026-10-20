<?php

namespace App\Http\Controllers;


use App\Models\PosCashMovement;
use App\Models\PosSession;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use App\Actions\Pos\RecordPosCashMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PosCashMovementController extends Controller
{

    public function index(
        Request $request,
        int $sessionId
    ): JsonResponse {
        $actor = $request->user();
        $storeId = app(TenantContext::class)->storeId();

        if (! $actor instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // Consultation réservée aux responsables POS.
        $supervisor = $actor->roles()
            ->whereIn('code', [
                'STORE_OWNER',
                'STORE_MANAGER',
            ])
            ->exists();

        // Refuser les comptes combinant des rôles interdits.
        $forbiddenRole = $actor->roles()
            ->whereIn('code', [
                'DELIVERY_AGENT',
                'SUPER_ADMIN',
            ])
            ->exists();

        if (
            $storeId === null ||
            (int) $actor->store_id !== (int) $storeId ||
            $actor->status !== 'ACTIVE' ||
            ! $supervisor ||
            $forbiddenRole ||
            ! $actor->hasPermission(
                'pos.cash_movements.manage'
            )
        ) {
            throw new AuthorizationException(
                'Consultation des mouvements POS non autorisée.'
            );
        }

        if ($actor->store()->value('status') !== 'ACTIVE') {
            throw new AuthorizationException(
                'Cette boutique est suspendue.'
            );
        }

        // La session doit appartenir à la boutique active.
        $session = PosSession::query()
            ->where('store_id', $storeId)
            ->whereKey($sessionId)
            ->firstOrFail();

        // Limiter le nombre d'opérations par page.
        $validated = $request->validate([
            'per_page' => [
                'sometimes',
                'integer',
                'between:1,100',
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        // Historique en lecture seule.
        // Aucune modification financière.
        $movements = PosCashMovement::query()
            ->where('store_id', $storeId)
            ->where('pos_session_id', $session->id)
            ->with(['recordedBy:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(
                static function (PosCashMovement $movement): array {
                    return [
                        'id' => $movement->id,
                        'type' => $movement->type,
                        'amount' => (string) $movement->amount,
                        'reason' => $movement->reason,
                        'reference' => $movement->reference,
                        'recorded_by' => [
                            'id' => $movement->recorded_by_user_id,
                            'name' => $movement->recordedBy?->name,
                        ],
                        'created_at' =>
                        $movement->created_at?->toIso8601String(),
                    ];
                }
            );

        return response()->json([
            'session_id' => $session->id,
            'pos_register_id' => $session->pos_register_id,
            'status' => $session->status,
            'movements' => $movements,
        ]);
    }

    public function store(
        Request $request,
        int $sessionId,
        RecordPosCashMovement $recorder
    ): JsonResponse {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401, 'Authentification requise.');
        }

        $data = $request->validate([
            'type' => [
                'required',
                'string',
                Rule::in(['CASH_IN', 'CASH_OUT']),
            ],
            'amount' => [
                'required',
                'string',
                'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',
            ],
            'reason' => [
                'required',
                'string',
                'max:255',
            ],
            'reference' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9._:-]{8,64}$/D',
            ],
        ]);

        // L'action métier vérifie :
        // - les permissions du responsable ;
        // - la boutique et la session ;
        // - le statut de la caisse ;
        // - le solde disponible ;
        // - l'unicité de la référence.
        $movement = $recorder->execute(
            sessionId: $sessionId,
            type: $data['type'],
            amount: $data['amount'],
            reason: $data['reason'],
            reference: $data['reference'],
            actor: $actor
        );

        return response()->json([
            'message' => __('pos.cash_movement_recorded'),
            'movement' => $movement,
        ], 201);
    }
}
