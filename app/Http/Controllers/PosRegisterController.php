<?php
namespace App\Http\Controllers;



use App\Models\PosSession;
use Illuminate\Support\Facades\DB;
use App\Models\PosRegister;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosRegisterController extends Controller
{
    private function authorizeAccess(
        Request $request,
        bool $manage = false
    ): int {
        $user = $request->user();
        $storeId = app(TenantContext::class)->storeId();

        $allowedRoles = $manage
            ? ['STORE_OWNER', 'STORE_MANAGER']
            : ['STORE_OWNER', 'STORE_MANAGER', 'CASHIER'];

        $hasAllowedRole = false;

        if ($user instanceof User) {
            foreach ($allowedRoles as $role) {
                if ($user->hasRole($role)) {
                    $hasAllowedRole = true;
                    break;
                }
            }
        }

        $permission = $manage
            ? 'pos.registers.manage'
            : 'pos.registers.view';

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== (int) $storeId ||
            ! $hasAllowedRole ||
            $user->hasRole('DELIVERY_AGENT') ||
            $user->hasRole('SUPER_ADMIN') ||
            ! $user->hasPermission($permission)
        ) {
            throw new AuthorizationException(
                'Accès à la gestion des caisses POS interdit.'
            );
        }

        return $storeId;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json(
            PosRegister::query()
                ->orderBy('name')
                ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->authorizeAccess($request, true);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('pos_registers', 'code')
                    ->where('store_id', $storeId),
            ],
        ]);

        // store_id est défini par BelongsToStore.
        // status reçoit ACTIVE par défaut en base.
        $register = PosRegister::create($data);

        $register->refresh();

        return response()->json([
            'message' => 'Caisse POS créée.',
            'register' => $register,
        ], 201);
    }

    public function update(
        Request $request,
        int $registerId
    ): JsonResponse {
        $storeId = $this->authorizeAccess($request, true);

        // Le scope tenant empêche de modifier
        // une caisse appartenant à une autre boutique.
        $register = PosRegister::query()
            ->findOrFail($registerId);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('pos_registers', 'code')
                    ->where('store_id', $storeId)
                    ->ignore($register->id),
            ],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages([
                'register' => 'Aucune modification fournie.',
            ]);
        }

        $register->update($data);

        return response()->json([
            'message' => 'Caisse POS modifiée.',
            'register' => $register->refresh(),
        ]);
    }

   
public function updateStatus(
    Request $request,
    int $registerId
): JsonResponse {
    $this->authorizeAccess($request, true);

    $data = $request->validate([
        'status' => [
            'required',
            Rule::in(['ACTIVE', 'INACTIVE']),
        ],
    ]);

    $register = DB::transaction(function () use (
        $registerId,
        $data
    ) {
        // Verrouiller la caisse pour empêcher
        // une ouverture concurrente.
        $register = PosRegister::query()
            ->whereKey($registerId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($data['status'] === 'INACTIVE') {
            // Interdire la désactivation lorsqu'une
            // session est encore ouverte.
            $hasOpenSession = PosSession::query()
                ->where('pos_register_id', $register->id)
                ->where(function ($query) {
                    $query->where('status', 'OPEN')
                        ->orWhere('open_slot', 1);
                })
                ->exists();

            if ($hasOpenSession) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Impossible de désactiver une caisse avec une session ouverte.',
                ]);
            }
        }

        $register->status = $data['status'];
        $register->save();

        return $register->refresh();
    }, 3);

    return response()->json([
        'message' => 'Statut de la caisse mis à jour.',
        'register' => $register,
    ]);
}

}
