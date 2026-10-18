<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function index(): JsonResponse
    {
        $stores = Store::query()
            ->select([
                'id',
                'name',
                'slug',
                'status',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($stores);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'slug' => [
                'required',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:stores,slug',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'address' => [
                'nullable',
                'string',
            ],
            'owner_name' => [
                'required',
                'string',
                'max:150',
            ],
            'owner_email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
        ]);

        $result = DB::transaction(function () use ($data) {
            $ownerRole = Role::query()
                ->where('code', 'STORE_OWNER')
                ->firstOrFail();

            $store = Store::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,

                // Jamais ACTIVE dès la création.
                'status' => 'PENDING',
            ]);

            $owner = new User();

            // Le store_id est défini par le serveur.
            $owner->store_id = $store->id;
            $owner->name = $data['owner_name'];
            $owner->email = $data['owner_email'];

            // Le cast hashed du modèle User
            // protège le secret aléatoire.
            // Aucun mot de passe n'est communiqué
            // par le super administrateur.
            $owner->password = Str::random(64);

            $owner->status = 'INVITED';
            $owner->save();

            $owner->roles()->attach($ownerRole->id);

            return [
                'store' => $store,
                'owner' => $owner,
            ];
        });

        $store = $result['store'];
        $owner = $result['owner'];

        return response()->json([
            'message' =>
            'Boutique créée en attente de validation.',
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
            ],
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'status' => $owner->status,
                'role' => 'STORE_OWNER',
            ],
        ], 201);
    }

    public function show(int $storeId): JsonResponse
    {
        $store = Store::query()->findOrFail($storeId);

        $owners = User::query()
            ->where('store_id', $store->id)
            ->whereHas('roles', function ($query) {
                $query->where('code', 'STORE_OWNER');
            })
            ->get([
                'id',
                'name',
                'email',
                'status',
                'email_verified_at',
            ]);

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'phone' => $store->phone,
                'email' => $store->email,
                'address' => $store->address,
                'status' => $store->status,
                'created_at' => $store->created_at,
            ],
            'owners' => $owners,
        ]);
    }


    public function updateStatus(
        Request $request,
        int $storeId
    ): JsonResponse {
        $data = $request->validate([
            'status' => [
                'required',
                'in:ACTIVE,INACTIVE',
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:500',
            ],
        ]);

        $adminId = $request->user()->getKey();

        $store = DB::transaction(function () use (
            $storeId,
            $data,
            $adminId
        ) {
            $store = Store::query()
                ->whereKey($storeId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $store->status;
            $newStatus = $data['status'];

            // Ne pas enregistrer de fausse transition.
            if ($oldStatus === $newStatus) {
                throw ValidationException::withMessages([
                    'status' =>
                    'La boutique possède déjà ce statut.',
                ]);
            }

            // Vérifications obligatoires avant activation.
            if ($newStatus === 'ACTIVE') {
                $owners = User::query()
                    ->where('store_id', $store->id)
                    ->whereHas('roles', function ($query) {
                        $query->where(
                            'code',
                            'STORE_OWNER'
                        );
                    })
                    ->limit(2)
                    ->get();

                if ($owners->count() !== 1) {
                    throw ValidationException::withMessages([
                        'status' =>
                        'Un propriétaire unique est requis.',
                    ]);
                }

                $owner = $owners->first();

                if (
                    $owner->status !== 'ACTIVE' ||
                    $owner->email_verified_at === null ||
                    $owner->roles()
                    ->where('code', '!=', 'STORE_OWNER')
                    ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'status' =>
                        'Le propriétaire doit être activé et posséder uniquement le rôle STORE_OWNER.',
                    ]);
                }
            }

            // Modifier le statut.
            $store->status = $newStatus;
            $store->save();

            // Enregistrer l'historique dans
            // la même transaction.
            DB::table('store_status_events')->insert([
                'store_id' => $store->id,
                'admin_user_id' => $adminId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $data['reason'],
                'created_at' => now(),
            ]);

            return $store;
        });

        return response()->json([
            'message' => 'Statut de la boutique mis à jour.',
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'status' => $store->status,
            ],
        ]);
    }

    public function statusEvents(int $storeId): JsonResponse
    {
        // Vérifier que la boutique existe.
        Store::query()->findOrFail($storeId);

        // Historique de cette boutique uniquement.
        $events = DB::table('store_status_events')
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($events);
    }
}
