<?php

namespace App\Http\Controllers;

use App\Notifications\EmployeeInvitationNotification;
use Illuminate\Support\Facades\Password;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private const EMPLOYEE_ROLES = [
        'STORE_MANAGER',
        'CASHIER',
        'DELIVERY_AGENT',
    ];

    private function authorizeOwner(Request $request): int
    {
        $user = $request->user();
        $storeId = app(TenantContext::class)->storeId();

        if (
            ! $user instanceof User ||
            (int) $user->store_id !== $storeId ||
            ! $user->hasRole('STORE_OWNER')
        ) {
            throw new AuthorizationException(
                'Gestion des employés non autorisée.'
            );
        }

        return $storeId;
    }

    private function findEmployee(
        int $storeId,
        int $employeeId
    ): User {
        $employee = User::query()
            ->where('store_id', $storeId)
            ->whereKey($employeeId)
            ->whereHas('roles', function ($query) {
                $query->whereIn(
                    'code',
                    self::EMPLOYEE_ROLES
                );
            })
            ->firstOrFail();

        // Ne jamais modifier un propriétaire
        // ou un administrateur global par cette API.
        if (
            $employee->hasRole('STORE_OWNER') ||
            $employee->hasRole('SUPER_ADMIN')
        ) {
            throw new AuthorizationException(
                'Compte protégé.'
            );
        }

        return $employee;
    }

    public function index(Request $request): JsonResponse
    {
        $storeId = $this->authorizeOwner($request);

        $employees = User::query()
            ->where('store_id', $storeId)
            ->whereHas('roles', function ($query) {
                $query->whereIn(
                    'code',
                    self::EMPLOYEE_ROLES
                );
            })
            ->whereDoesntHave('roles', function ($query) {
                $query->whereIn('code', [
                    'STORE_OWNER',
                    'SUPER_ADMIN',
                ]);
            })
            ->with('roles:id,name,code')
            ->orderByDesc('id')
            ->paginate(20, [
                'id',
                'name',
                'email',
                'status',
                'created_at',
            ]);

        return response()->json($employees);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->authorizeOwner($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'role' => [
                'required',
                Rule::in(self::EMPLOYEE_ROLES),
            ],
        ]);

        $employee = DB::transaction(
            function () use ($data, $storeId) {
                $role = Role::query()
                    ->where('code', $data['role'])
                    ->firstOrFail();

                $employee = new User();

                // Le serveur fixe la boutique et le statut.
                $employee->store_id = $storeId;
                $employee->name = $data['name'];
                $employee->email = $data['email'];

                // Mot de passe aléatoire inutilisable
                // par l'employé avant son invitation.
                $employee->password = Str::random(64);
                $employee->status = 'INVITED';

                $employee->save();

                $employee->roles()->attach($role->id);

                return $employee;
            }
        );

        // Générer un jeton sécurisé.
        // Laravel conserve son empreinte dans
        // password_reset_tokens.
        $token = Password::broker('users')
            ->createToken($employee);

        // Envoyer l'invitation à l'employé.
        // Le mot de passe n'est jamais envoyé.
        $employee->notify(
            new EmployeeInvitationNotification($token)
        );


        return response()->json([
            'message' => 'Employé créé en attente d’invitation.',
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'status' => $employee->status,
                'role' => $data['role'],
            ],
        ], 201);
    }

    public function updateRole(
        Request $request,
        int $employeeId
    ): JsonResponse {
        $storeId = $this->authorizeOwner($request);

        $data = $request->validate([
            'role' => [
                'required',
                Rule::in(self::EMPLOYEE_ROLES),
            ],
        ]);

        DB::transaction(function () use (
            $storeId,
            $employeeId,
            $data
        ) {
            $employee = $this->findEmployee(
                $storeId,
                $employeeId
            );

            $role = Role::query()
                ->where('code', $data['role'])
                ->firstOrFail();

            // Un seul rôle métier par employé.
            $employee->roles()->sync([$role->id]);
        });

        return response()->json([
            'message' => 'Rôle mis à jour.',
        ]);
    }

    public function updateStatus(
        Request $request,
        int $employeeId
    ): JsonResponse {
        $storeId = $this->authorizeOwner($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['ACTIVE', 'INACTIVE']),
            ],
        ]);

        $employee = DB::transaction(function () use (
            $storeId,
            $employeeId,
            $data
        ) {
            $employee = $this->findEmployee(
                $storeId,
                $employeeId
            );

            // Le compte INVITED doit d'abord accepter
            // son invitation et créer son mot de passe.
            if (
                $data['status'] === 'ACTIVE' &&
                (
                    $employee->status === 'INVITED' ||
                    $employee->email_verified_at === null
                )
            ) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' =>
                    'Une invitation doit être acceptée avant activation.',
                ]);
            }

            $employee->status = $data['status'];
            $employee->save();

            return $employee;
        });

        return response()->json([
            'message' => 'Statut du compte mis à jour.',
            'employee' => [
                'id' => $employee->id,
                'status' => $employee->status,
            ],
        ]);
    }
}
