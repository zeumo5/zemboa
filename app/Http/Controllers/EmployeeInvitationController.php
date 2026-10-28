<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EmployeeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeInvitationController extends Controller
{
    private function eligibleEmployee(
        string $email
    ): ?User {
        $user = User::query()
            ->where('email', $email)
            ->where('status', 'INVITED')
            ->with('store')
            ->first();

        if (
            $user === null ||
            $user->store === null ||
            $user->store->status !== 'ACTIVE' ||
            ! EmployeeAccess::employee($user) ||
            ! $user->roles()
                ->whereIn('code', [
                    'STORE_MANAGER',
                    'CASHIER',
                    'DELIVERY_AGENT',
                ])
                ->exists() ||
            $user->roles()
                ->whereIn('code', [
                    'STORE_OWNER',
                    'SUPER_ADMIN',
                ])
                ->exists()
        ) {
            return null;
        }

        return $user;
    }

    public function show(
        Request $request,
        string $token
    ): View {
        $email = (string) $request->query('email', '');

        $employee = $this->eligibleEmployee($email);

        if (
            $employee === null ||
            ! Password::broker('users')
                ->tokenExists($employee, $token)
        ) {
            abort(404, __('team.invalid_invitation'));
        }

        return view('employee-invitations.accept', [
            'invitationStore' => $employee->store()->firstOrFail(),
            'invitationRole' => $employee->roles()->value('code'),
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function accept(
        Request $request,
        string $token
    ): View {
        $data = $request->validate([
            'email' => [
                'required',
                'email',
            ],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
            ],
        ]);

        $employee = $this->eligibleEmployee(
            $data['email']
        );

        if ($employee === null) {
            throw ValidationException::withMessages([
                'email' => __('team.invalid_invitation'),
            ]);
        }

        $result = Password::broker('users')->reset(
            [
                'email' => $data['email'],
                'token' => $token,
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user, string $password) use ($token) {
                DB::transaction(function () use (
                    $user,
                    $password,
                    $token
                ) {
                    $employee = User::query()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $this->eligibleEmployee(
                            $employee->email
                        ) === null || ! Password::broker('users')->tokenExists($employee, $token)
                    ) {
                        throw ValidationException::withMessages([
                            'email' => __('team.invalid_invitation'),
                        ]);
                    }

                    $employee->password =
                        Hash::make($password);

                    $employee->email_verified_at = now();
                    $employee->status = 'ACTIVE';
                    $employee->save();
                });
            }
        );

        if ($result !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __('team.invalid_invitation'),
            ]);
        }

        return view('employee-invitations.complete');
    }
}
