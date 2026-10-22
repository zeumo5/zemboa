<?php

namespace App\Http\Controllers\Buyer;

use App\Actions\Buyers\RegisterBuyer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\LoginBuyerRequest;
use App\Http\Requests\Buyer\RegisterBuyerRequest;
use App\Models\User;
use App\Support\BuyerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SessionController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        return Auth::guard('buyer')->check()
            ? redirect()->route('buyer.account') : view('buyer.auth', ['register' => false]);
    }

    public function registerForm(): View|RedirectResponse
    {
        return Auth::guard('buyer')->check()
            ? redirect()->route('buyer.account') : view('buyer.auth', ['register' => true]);
    }

    public function register(RegisterBuyerRequest $request, RegisterBuyer $register): RedirectResponse
    {
        if (Auth::guard('buyer')->check()) {
            return redirect()->route('buyer.account');
        }

        $user = $register->execute($request->validated());
        Auth::guard('buyer')->login($user);
        $request->session()->regenerate();

        return redirect()->route('buyer.account');
    }

    public function login(LoginBuyerRequest $request): RedirectResponse
    {
        if (Auth::guard('buyer')->check()) {
            return redirect()->route('buyer.account');
        }

        $data = $request->validated();
        $identifier = $data['identifier'];
        $candidates = str_contains($identifier, '@')
            ? User::query()->whereRaw('LOWER(email) = ?', [$identifier])->limit(2)->pluck('id')
            : BuyerIdentity::phoneQuery($identifier)->limit(2)->pluck('id');
        // Use Laravel's timed password check even when no unique identity exists.
        try {
            $authenticated = Auth::guard('buyer')->attemptWhen([
                'id' => $candidates->count() === 1 ? $candidates->first() : 0,
                'password' => $data['password'],
                'status' => 'ACTIVE',
            ], function (User $user) use ($request): bool {
                if ($user->hasRole('SUPER_ADMIN')) {
                    return false;
                }
                if (! $user->is_buyer && $request->boolean('enable_buyer')) {
                    // Password was checked by the guard before this explicit opt-in callback.
                    if (! $user->roles()->whereIn('code', [
                        'STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT',
                    ])->exists()) {
                        return false;
                    }
                    $phone = BuyerIdentity::phoneOrNull($user->phone);
                    if (!$phone || BuyerIdentity::phoneQuery($phone)->whereKeyNot($user->id)->exists()) return false;
                    BuyerIdentity::assertSchemaReady();
                    $user->forceFill(['is_buyer' => true])->save();
                }

                return $user->canUseBuyerAccount();
            });
        } catch (UniqueConstraintViolationException) {
            $authenticated = false;
        }

        if (! $authenticated) {
            throw ValidationException::withMessages(['identifier' => __('buyer.credentials_invalid')]);
        }

        $request->session()->regenerate();

        // Never reuse a professional intended URL or a caller-provided redirect.
        return redirect()->route('buyer.account');
    }

    public function account(): View
    {
        return view('buyer.account', ['buyer' => Auth::guard('buyer')->user(),
            'phone' => BuyerIdentity::phoneOrNull(Auth::guard('buyer')->user()->phone)]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('buyer')->logoutCurrentDevice();
        // Destroy the old session ID and rotate CSRF, preserving the independent web login.
        $request->session()->regenerate(true);

        return redirect()->route('buyer.login');
    }
}
