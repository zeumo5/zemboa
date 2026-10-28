<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OwnerInvitationController extends Controller
{
    private function eligibleOwner(string $email): ?User
    {
        $owner = User::query()
            ->with('store')
            ->where('email', $email)
            ->where('status', 'INVITED')
            ->whereNull('email_verified_at')
            ->first();

        if (
            $owner === null ||
            $owner->store === null ||
            ! in_array(
                $owner->store->status,
                ['PENDING', 'ACTIVE'],
                true
            ) ||
            ! $owner->hasRole('STORE_OWNER') ||
            $owner->roles()
                ->where('code', '!=', 'STORE_OWNER')
                ->exists()
        ) {
            return null;
        }

        return $owner;
    }

    public function show(
        Request $request,
        string $token
    ): View {
        $email = (string) $request->query('email', '');

        $owner = $this->eligibleOwner($email);

        if (
            $owner === null ||
            ! Password::broker('users')
                ->tokenExists($owner, $token)
        ) {
            abort(404, 'Invitation invalide ou expirée.');
        }

        return view('owner-invitations.accept', [
            'invitationStore' => $owner->store,
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function accept(
        Request $request,
        string $token
    ): View {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
            ],
        ]);

        if ($this->eligibleOwner($data['email']) === null) {
            throw ValidationException::withMessages([
                'email' => 'Invitation non autorisée.',
            ]);
        }

        $result = Password::broker('users')->reset(
            [
                'email' => $data['email'],
                'token' => $token,
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user, string $password) use ($token): void {
                DB::transaction(function () use (
                    $user,
                    $password,
                    $token
                ): void {
                    $owner = User::query()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $eligible = $this->eligibleOwner(
                        $owner->email
                    );

                    if (
                        $eligible === null ||
                        $eligible->id !== $owner->id ||
                        ! Password::broker('users')->tokenExists($owner, $token)
                    ) {
                        throw ValidationException::withMessages([
                            'email' => 'Invitation non autorisée.',
                        ]);
                    }

                    // Le modèle User utilise le cast hashed.
                    $owner->password = $password;
                    $owner->email_verified_at = now();
                    $owner->status = 'ACTIVE';
                    $owner->save();
                });
            }
        );

        if ($result !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Invitation invalide ou expirée.',
            ]);
        }

        return view('owner-invitations.complete');
    }
}
