<?php

namespace App\Modules\Tenancy\Http;

use App\Models\User;
use App\Platform\Domain\DomainException;
use App\Platform\Http\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/**
 * First-party login for staff apps (web SPA, mobile). Partners use the
 * standard OAuth 2.1 endpoints under /oauth (client credentials or
 * authorization code + PKCE).
 */
class AuthController
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'mfa_code' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ProblemDetails::make(429, 'too-many-attempts', 'Too many login attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
            RateLimiter::hit($key, 300);

            return ProblemDetails::make(401, 'invalid-credentials', 'These credentials do not match our records.');
        }

        if ($user->mfa_confirmed_at) {
            if (empty($data['mfa_code'])) {
                return ProblemDetails::make(401, 'mfa-required', 'Enter the code from your authenticator app.');
            }
            if (! class_exists(Google2FA::class) || ! (new Google2FA)->verifyKey($user->mfa_secret, $data['mfa_code'])) {
                RateLimiter::hit($key, 300);

                return ProblemDetails::make(401, 'mfa-invalid', 'The authentication code is invalid.');
            }
        }

        RateLimiter::clear($key);
        $token = $user->createToken($data['device_name'] ?? 'staff-app');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->accessToken,
            'expires_at' => $token->token->expires_at?->toIso8601String(),
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->profile($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->token()?->revoke();

        return response()->noContent();
    }

    private function profile(User $user): array
    {
        $hotels = $user->all_hotels
            ? $user->group?->hotels()->get(['id', 'code', 'name', 'city'])
            : $user->hotels()->withoutGlobalScopes()->get(['hotels.id', 'code', 'name', 'city']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'group' => $user->group?->only(['id', 'name', 'slug']),
            'all_hotels' => $user->all_hotels,
            'hotels' => $hotels,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'mfa_enabled' => (bool) $user->mfa_confirmed_at,
        ];
    }

    /** Ensures sensitive roles have MFA (SPEC §7.4). */
    public static function assertMfaFor(User $user): void
    {
        if ($user->hasAnyRole(['group-owner', 'finance-director', 'accountant', 'payroll-officer']) && ! $user->mfa_confirmed_at && app()->isProduction()) {
            throw new DomainException('Multi-factor authentication is required for this role.', 'mfa-required', 403);
        }
    }
}
