<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\StoreSetting;
use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use App\Support\BuyerIdentity;
use App\Support\EmployeeAccess;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    private function authorizeOwner(Request $request): int
    {
        $storeId = app(TenantContext::class)->storeId();
        abort_unless(EmployeeAccess::owner($request->user('web'), $storeId), 403);

        return $storeId;
    }

    private function findEmployee(int $storeId, int $id): User
    {
        $employee = User::where('store_id', $storeId)->whereKey($id)->lockForUpdate()->with('roles')->firstOrFail();
        abort_unless(EmployeeAccess::employee($employee), 403);

        return $employee;
    }

    private function reply(Request $request, array $body, int $status = 200): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json($body, $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            : redirect()->route('employees.index')->with('status', $body['message']);
    }

    public function index(Request $request): JsonResponse|Response
    {
        $storeId = $this->authorizeOwner($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(EmployeeAccess::ROLES)],
            'status' => ['nullable', Rule::in(['INVITED', 'ACTIVE', 'INACTIVE'])],
        ]);
        $query = User::where('store_id', $storeId)->whereHas('roles', fn ($q) => $q->whereIn('code', EmployeeAccess::ROLES))
            ->whereDoesntHave('roles', fn ($q) => $q->whereNotIn('code', EmployeeAccess::ROLES))
            ->with('roles:id,name,code');
        if ($filters['q'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%')->orWhere('phone', 'like', '%'.$filters['q'].'%'));
        }
        if ($filters['role'] ?? null) {
            $query->whereHas('roles', fn ($q) => $q->where('code', $filters['role']));
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        $employees = $query->orderByDesc('id')->paginate(20, ['id', 'name', 'email', 'phone', 'status', 'email_verified_at', 'created_at'])->withQueryString();
        $tokens = DB::table(config('auth.passwords.users.table'))->whereIn('email', $employees->pluck('email'))->pluck('created_at', 'email');
        foreach ($employees as $employee) {
            $created = $tokens[$employee->email] ?? null;
            $employee->invitation_state = $employee->email_verified_at !== null ? 'accepted'
                : ($employee->status !== 'INVITED' ? 'disabled' : ($created === null ? 'absent'
                    : (Carbon::parse($created)->addMinutes((int) config('auth.passwords.users.expire'))->isPast() ? 'expired' : 'pending')));
        }
        if ($request->expectsJson()) {
            return response()->json($employees)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
        $actor = $request->user('web');
        $store = app(TenantContext::class)->store();
        $settings = StoreSetting::where('store_id', $storeId)->first(['store_id', 'logo_path']);

        return response()->view('professional.team', compact('employees', 'filters', 'actor', 'store', 'settings'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $storeId = $this->authorizeOwner($request);
        $request->merge(['email' => is_string($request->email) ? Str::lower(trim($request->email)) : $request->email]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64'], 'role' => ['required', Rule::in(EmployeeAccess::ROLES)],
            'store_id' => ['prohibited'], 'is_buyer' => ['prohibited'], 'roles' => ['prohibited'],
        ]);
        $data['phone'] = BuyerIdentity::phoneOrNull($data['phone']);
        if ($data['phone'] === null) {
            throw ValidationException::withMessages(['phone' => __('team.invalid_phone')]);
        }
        $employee = DB::transaction(function () use ($data, $storeId) {
            if (User::whereRaw('LOWER(email) = ?', [$data['email']])->exists() || BuyerIdentity::phoneQuery($data['phone'])->exists()) {
                throw ValidationException::withMessages(['email' => __('team.identity_conflict')]);
            }
            $role = Role::where('code', $data['role'])->firstOrFail();
            $employee = new User;
            $employee->forceFill(['store_id' => $storeId, 'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'password' => Str::random(64), 'status' => 'INVITED', 'is_buyer' => false])->save();
            $employee->roles()->attach($role->id);
            $this->sendInvitation($employee);

            return $employee;
        });

        return $this->reply($request, ['message' => __('team.invited'), 'employee' => [
            'id' => $employee->id, 'name' => $employee->name, 'email' => $employee->email, 'status' => $employee->status, 'role' => $data['role'],
        ]], 201);
    }

    private function sendInvitation(User $employee): void
    {
        $broker = Password::broker('users');
        if ($broker->getRepository()->recentlyCreatedToken($employee)) {
            throw ValidationException::withMessages(['invitation' => __('team.wait')]);
        }
        $token = $broker->createToken($employee);
        $employee->notify((new EmployeeInvitationNotification($token))->locale(app()->getLocale()));
    }

    public function resend(Request $request, int $employeeId): JsonResponse|RedirectResponse
    {
        $storeId = $this->authorizeOwner($request);
        DB::transaction(function () use ($storeId, $employeeId): void {
            $employee = $this->findEmployee($storeId, $employeeId);
            abort_unless($employee->status === 'INVITED' && $employee->email_verified_at === null, 422);
            $this->sendInvitation($employee);
        });

        return $this->reply($request, ['message' => __('team.resent')]);
    }

    public function revoke(Request $request, int $employeeId): JsonResponse|RedirectResponse
    {
        $storeId = $this->authorizeOwner($request);
        DB::transaction(function () use ($storeId, $employeeId): void {
            $employee = $this->findEmployee($storeId, $employeeId);
            abort_unless($employee->status === 'INVITED' && $employee->email_verified_at === null, 422);
            Password::broker('users')->deleteToken($employee);
        });

        return $this->reply($request, ['message' => __('team.revoked')]);
    }

    public function updateRole(Request $request, int $employeeId): JsonResponse|RedirectResponse
    {
        $storeId = $this->authorizeOwner($request);
        $data = $request->validate(['role' => ['required', Rule::in(EmployeeAccess::ROLES)]]);
        DB::transaction(function () use ($storeId, $employeeId, $data): void {
            $employee = $this->findEmployee($storeId, $employeeId);
            $role = Role::where('code', $data['role'])->firstOrFail();
            $employee->roles()->sync([$role->id]);
        });

        return $this->reply($request, ['message' => __('team.role_updated')]);
    }

    public function updateStatus(Request $request, int $employeeId): JsonResponse|RedirectResponse
    {
        $storeId = $this->authorizeOwner($request);
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);
        $employee = DB::transaction(function () use ($storeId, $employeeId, $data): User {
            $employee = $this->findEmployee($storeId, $employeeId);
            if ($data['status'] === 'ACTIVE' && ($employee->status === 'INVITED' || $employee->email_verified_at === null)) {
                throw ValidationException::withMessages(['status' => __('team.must_accept')]);
            }
            $employee->status = $data['status'];
            $employee->save();
            if ($data['status'] === 'INACTIVE') {
                Password::broker('users')->deleteToken($employee);
            }

            return $employee;
        });

        return $this->reply($request, ['message' => __('team.status_updated'), 'employee' => ['id' => $employee->id, 'status' => $employee->status]]);
    }
}
