<?php
namespace App\Http\Controllers;


use App\Actions\Pos\CalculatePosCashBalance;
use App\Models\PosSession;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PosClosingReportController extends Controller
{
    public function show(
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
            (int) $actor->store_id !== (int) $storeId ||
            $actor->status !== 'ACTIVE' ||
            (! $supervisor && ! $cashier) ||
            $forbiddenRole ||
            ! $actor->hasPermission('pos.sessions.close')
        ) {
            throw new AuthorizationException(
                __('pos.report_access_denied'),
            );
        }

        if ($actor->store()->value('status') !== 'ACTIVE') {
            throw new AuthorizationException(
                __('pos.store_suspended'),
            );
        }

        $session = PosSession::query()
            ->where('store_id', $storeId)
            ->whereKey($sessionId)
            ->firstOrFail();

        if (
            ! $supervisor &&
            (int) $session->opened_by_user_id !== (int) $actor->id
        ) {
            throw new AuthorizationException(
                __('pos.session_access_denied'),
            );
        }

        // Le rapport de clôture n'existe
        // qu'après fermeture de la session.
        if ($session->status !== 'CLOSED') {
            throw ValidationException::withMessages([
                'session' =>
                   __('pos.report_requires_closed_session'),
            ]);
        }

        $session->load([
            'register:id,name,code',
            'openedBy:id,name',
            'closedBy:id,name',
        ]);

        // Détail des opérations actuellement
        // enregistrées, recalculé en lecture seule.
        $breakdown = $calculator->execute($session);

        // Ces trois montants viennent du
        // résultat enregistré lors de la clôture.
        $expected = (string) $session->closing_cash_expected;
        $counted = (string) $session->closing_cash_counted;
        $difference = (string) $session->cash_difference;

        // Détecter une éventuelle divergence
        // entre le détail actuel et le bilan clos.
        $matchesSnapshot = $breakdown['expected_cash']
            === $expected;

        return response()->json([
            'report_type' => 'POS_CLOSING',
            'session_id' => $session->id,
            'status' => 'CLOSED',

            'register' => [
                'id' => $session->register?->id,
                'name' => $session->register?->name,
                'code' => $session->register?->code,
            ],

            'opened_by' => [
                'id' => $session->opened_by_user_id,
                'name' => $session->openedBy?->name,
            ],

            'closed_by' => [
                'id' => $session->closed_by_user_id,
                'name' => $session->closedBy?->name,
            ],

            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,

            'breakdown' => [
                'opening_cash' =>
                    $breakdown['opening_cash'],
                'confirmed_cash_payments' =>
                    $breakdown['confirmed_cash_payments'],
                'cash_in' => $breakdown['cash_in'],
                'cash_out' => $breakdown['cash_out'],
            ],

            'closing' => [
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'cash_difference' => $difference,
            ],

            'audit' => [
                'breakdown_matches_closing_snapshot' =>
                    $matchesSnapshot,
            ],
        ]);
    }
}
