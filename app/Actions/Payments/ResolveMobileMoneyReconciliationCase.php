<?php
namespace App\Actions\Payments;


use App\Models\MobileMoneyReconciliationCase;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveMobileMoneyReconciliationCase
{
    public function execute(
        int $caseId,
        string $notes
    ): MobileMoneyReconciliationCase {

        // 1. Exiger un utilisateur authentifié.
        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // 2. Vérifier la permission RBAC.
        if (!$user->hasPermission(
            'payments.reconciliation.resolve'
        )) {
            abort(403, 'Permission de résolution refusée.');
        }

        // 3. Exiger une boutique.
        if ($user->store_id === null) {
            abort(403, 'Aucune boutique autorisée.');
        }

        // 4. Exiger des notes explicatives.
        $notes = trim($notes);

        if (
            mb_strlen($notes) < 15 ||
            mb_strlen($notes) > 2000
        ) {
            throw ValidationException::withMessages([
                'resolution_notes' =>
                    'Les notes doivent contenir entre '
                    . '15 et 2000 caractères.',
            ]);
        }

        // 5. Verrouiller le dossier pour empêcher
        // deux résolutions simultanées.
        return DB::transaction(function () use (
            $caseId,
            $notes,
            $user
        ) {
            // Rechercher uniquement dans la boutique
            // de l'utilisateur authentifié.
            $case = MobileMoneyReconciliationCase::query()
                ->where('store_id', $user->store_id)
                ->whereKey($caseId)
                ->lockForUpdate()
                ->firstOrFail();

            // Seul un dossier OPEN peut être résolu.
            if ($case->status !== 'OPEN') {
                throw ValidationException::withMessages([
                    'reconciliation' =>
                        'Ce dossier est déjà clôturé '
                        . 'ou ne peut pas être résolu.',
                ]);
            }

            // 6. Enregistrer uniquement la décision
            // administrative de résolution.
            $case->forceFill([
                'status' => 'RESOLVED',
                'resolution_notes' => $notes,
                'resolved_at' => now(),
                'resolved_by' => $user->id,
            ]);


$case->save();

// 7. Historiser la résolution administrative.
// L'événement appartient à la même transaction SQL.
DB::table('mobile_money_reconciliation_events')
    ->insert([
        'store_id' => $case->store_id,
        'reconciliation_case_id' => $case->id,
        'actor_user_id' => $user->id,
        'event_type' => 'RESOLVED',
        'details' => json_encode(
            [
                'resolution_notes' => $notes,
            ],
            JSON_THROW_ON_ERROR
        ),
        'created_at' => now(),
    ]);

// Ne modifier ni Payment, ni Order,
// ni les stocks.
return $case->refresh();

        });
    }
}
