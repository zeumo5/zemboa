<?php
namespace App\Http\Controllers;


use App\Models\User;
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
            !$user->roles()
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
            !Password::broker('users')
                ->tokenExists($employee, $token)
        ) {
            abort(404, 'Invitation invalide ou expirée.');
        }

        return view('employee-invitations.accept', [
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
                'email' => 'Invitation invalide.',
            ]);
        }

        $result = Password::broker('users')->reset(
            [
                'email' => $data['email'],
                'token' => $token,
                'password' => $data['password'],
                'password_confirmation' =>
                    $request->input('password_confirmation'),
            ],
            function (User $user, string $password) {
                DB::transaction(function () use (
                    $user,
                    $password
                ) {
                    $employee = User::query()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $this->eligibleEmployee(
                            $employee->email
                        ) === null
                    ) {
                        throw ValidationException::withMessages([
                            'email' => 'Invitation non autorisée.',
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
                'email' =>
                    'Invitation invalide ou expirée.',
            ]);
        }

        return view('employee-invitations.complete');
    }
}
