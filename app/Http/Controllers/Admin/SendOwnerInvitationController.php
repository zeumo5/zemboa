<?php
namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OwnerInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class SendOwnerInvitationController extends Controller
{
    public function __invoke(int $storeId): JsonResponse
    {
        $store = Store::query()->findOrFail($storeId);

        // Aucune invitation pour une boutique suspendue.
        if (! in_array(
            $store->status,
            ['PENDING', 'ACTIVE'],
            true
        )) {
            abort(409, 'Boutique non autorisée.');
        }

        // Trouver les propriétaires rattachés
        // exclusivement à cette boutique.
        $owners = User::query()
            ->where('store_id', $store->id)
            ->whereHas('roles', function ($query) {
                $query->where('code', 'STORE_OWNER');
            })
            ->limit(2)
            ->get();

        // Une boutique doit avoir exactement
        // un propriétaire dans ce parcours.
        if ($owners->count() !== 1) {
            abort(409, 'Propriétaire unique requis.');
        }

        $owner = $owners->first();

        if (
            $owner->status !== 'INVITED' ||
            $owner->email_verified_at !== null ||
            $owner->roles()
                ->where('code', '!=', 'STORE_OWNER')
                ->exists()
        ) {
            abort(409, 'Invitation non autorisée.');
        }

        // Le jeton est conservé sous forme hachée
        // par le Password Broker de Laravel.
        $token = Password::broker('users')
            ->createToken($owner);

        // Envoyer la notification au propriétaire.
        $owner->notify(
            new OwnerInvitationNotification(
                $token,
                $store->name
            )
        );

        return response()->json([
            'message' => 'Invitation envoyée au propriétaire.',
            'owner_id' => $owner->id,
        ]);
    }
}
