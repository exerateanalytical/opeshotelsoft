<?php

namespace App\Modules\Admin\Http;

use App\Models\User;
use App\Modules\Admin\Application\ApiTokenIssuer;
use App\Modules\Admin\Application\TwoFactorRequired;
use Illuminate\Http\Request;

/**
 * API token issuance with a two-step challenge for two-factor users:
 *   POST /api/v1/auth/token            {email, password, code?, device_name?}
 *     200 token | 401 two-factor-required {challenge_token, challenge_expires_in}
 *   POST /api/v1/auth/token/two-factor {challenge_token, code, device_name?}
 *     200 token | 401 two-factor-invalid / challenge-expired
 */
class TokenController
{
    public function __construct(private ApiTokenIssuer $issuer) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        try {
            $user = $this->issuer->authenticate($data['email'], $data['password'], $data['code'] ?? null, $request->ip());
        } catch (TwoFactorRequired $e) {
            return $e->toProblem();
        }

        return $this->token($user, $data['device_name'] ?? null);
    }

    public function twoFactor(Request $request)
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'max:2000'],
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $this->issuer->completeChallenge($data['challenge_token'], $data['code'], $request->ip());

        return $this->token($user, $data['device_name'] ?? null);
    }

    private function token(User $user, ?string $device)
    {
        $token = $user->createToken($device ?: 'staff-app');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->accessToken,
            'expires_at' => $token->token->expires_at?->toIso8601String(),
        ]);
    }
}
