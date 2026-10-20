<?php

namespace App\Http\Controllers;


use App\Actions\Pos\CalculatePosCashBalance;
use App\Models\PosSession;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use App\Actions\Pos\OpenPosSession;
use App\Actions\Pos\ClosePosSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSessionController extends Controller
{
    public function __construct(
        private readonly OpenPosSession $opener,
        private readonly ClosePosSession $closer
    ) {}

    public function open(
        Request $request,
        int $registerId
    ): JsonResponse {
        $data = $request->validate([
            'opening_cash' => [
                'required',
                'string',
                'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',
            ],
        ]);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // Les autorisations, la boutique et
        // la disponibilité de la caisse sont
        // vérifiées par l'action métier.
        $session = $this->opener->execute(
            registerId: $registerId,
            openingCash: $data['opening_cash'],
            actor: $actor
        );

        return response()->json([
            'message' => 'Session de caisse ouverte.',
            'session' => $session,
        ], 201);
    }

    public function close(
        Request $request,
        int $sessionId
    ): JsonResponse {
        $data = $request->validate([
            'closing_cash_counted' => [
                'required',
                'string',
                'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',
            ],
        ]);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // Clôture autorisée uniquement selon
        // les règles de ClosePosSession.
        $session = $this->closer->execute(
            sessionId: $sessionId,
            cashCounted: $data['closing_cash_counted'],
            actor: $actor
        );

        return response()->json([
            'message' => 'Session de caisse clôturée.',
            'session' => $session,
        ]);
    }

    public function balance(
        Request $request,
        int $sessionId,
        CalculatePosCashBalance $calculator
    ): JsonResponse {
        $actor = $request->user();
        $storeId = app(TenantContext::class)->storeId();

        if (! $actor instanceof User) {
            abort(401, 'Authentification requise.');
        }

        $supervisor = $actor->roles()
            ->whereIn('code', [
                'STORE_OWNER',
                'STORE_MANAGER',
            ])
            ->exists();

        $cashier = $actor->hasRole('CASHIER');

        $forbiddenRole = $actor->roles()
            ->whereIn('code', [
                'DELIVERY_AGENT',
                'SUPER_ADMIN',
            ])
            ->exists();

        if (
            $storeId === null ||
            auth()->id() !== $actor->id ||
            $actor->status !== 'ACTIVE' ||
            (int) $actor->store_id !== (int) $storeId ||
            (! $supervisor && ! $cashier) ||
            $forbiddenRole ||
            ! $actor->hasPermission('pos.sessions.close')
        ) {
            throw new AuthorizationException(
                'Consultation du bilan POS non autorisée.'
            );
        }

        if ($actor->store()->value('status') !== 'ACTIVE') {
            throw new AuthorizationException(
                'Cette boutique est suspendue.'
            );
        }

        // Recherche limitée à la boutique courante.
        $session = PosSession::query()
            ->where('store_id', $storeId)
            ->whereKey($sessionId)
            ->firstOrFail();

        // La caissière consulte uniquement sa session.
        // Les responsables peuvent superviser.
        if (
            ! $supervisor &&
            (int) $session->opened_by_user_id !== (int) $actor->id
        ) {
            throw new AuthorizationException(
                'Cette session appartient à un autre caissier.'
            );
        }

        if ($session->status === 'OPEN') {
            return response()->json([
                'session_id' => $session->id,
                'pos_register_id' => $session->pos_register_id,
                'status' => 'OPEN',
                'balance' => $calculator->execute($session),
                'closing' => null,
            ]);
        }

        if ($session->status === 'CLOSED') {
            // Ne pas recalculer une ancienne clôture :
            // retourner les montants figés à sa fermeture.
            return response()->json([
                'session_id' => $session->id,
                'pos_register_id' => $session->pos_register_id,
                'status' => 'CLOSED',
                'balance' => null,
                'closing' => [
                    'expected_cash' =>
                    $session->closing_cash_expected,
                    'counted_cash' =>
                    $session->closing_cash_counted,
                    'cash_difference' =>
                    $session->cash_difference,
                    'closed_by_user_id' =>
                    $session->closed_by_user_id,
                    'closed_at' =>
                    $session->closed_at,
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'session' => 'Statut de session invalide.',
        ]);
    }
}
