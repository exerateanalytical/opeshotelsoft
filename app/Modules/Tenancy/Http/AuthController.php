<?php

namespace App\Modules\Tenancy\Http;

use App\Models\User;
use App\Modules\Admin\Application\ApiTokenIssuer;
use App\Modules\Admin\Application\TwoFactorRequired;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;

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

        // Throttle, lockout, audit and two-factor (TOTP or recovery code) are enforced by the Admin issuer.
        try {
            $user = app(ApiTokenIssuer::class)->authenticate($data['email'], $data['password'], $data['mfa_code'] ?? null, $request->ip());
        } catch (TwoFactorRequired $e) {
            return $e->toProblem();
        }
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
