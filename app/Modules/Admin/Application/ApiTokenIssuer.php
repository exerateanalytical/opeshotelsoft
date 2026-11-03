<?php

namespace App\Modules\Admin\Application;

use App\Models\User;
use App\Platform\Domain\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First-party API sign-in. Users with two-factor enabled never get a token
 * from the password alone: they send the code with the credentials, or
 * exchange the short-lived, single-use challenge token from the first step
 * together with a TOTP or recovery code.
 */
class ApiTokenIssuer
{
    public const CHALLENGE_TTL = 300;

    public const CHALLENGE_ATTEMPTS = 5;

    public function __construct(private LoginGuard $guard, private TwoFactor $twoFactor) {}

    /**
     * @throws TwoFactorRequired enrolled user, no code sent
     * @throws DomainException 401 invalid credentials/code, 423 locked, 429 throttled
     */
    public function authenticate(string $email, string $password, ?string $code, ?string $ip): User
    {
        if ($this->guard->throttled($email, $ip)) {
            throw new DomainException('Too many login attempts. Try again in '.$this->guard->retryAfter($email, $ip).' seconds.', 'too-many-attempts', 429);
        }
        $user = User::where('email', $email)->first();
        if ($this->guard->locked($user)) {
            $this->guard->blocked($user, $ip);
            throw new DomainException('This account is temporarily locked after repeated failed sign-ins.', 'account-locked', 423);
        }
        if (! $user || ! Hash::check($password, $user->password) || $user->status !== 'active') {
            $this->guard->failed($email, $ip, $user);
            throw new DomainException('These credentials do not match our records.', 'invalid-credentials', 401);
        }
        if ($this->twoFactor->enabled($user)) {
            if ($code === null || trim($code) === '') {
                throw new TwoFactorRequired($this->challengeFor($user), self::CHALLENGE_TTL);
            }
            if (! $this->twoFactor->check($user, $code)) {
                $this->guard->failed($email, $ip, $user, 'two-factor');
                throw new DomainException('The authentication code is invalid.', 'two-factor-invalid', 401);
            }
        }
        $this->guard->succeeded($email, $ip, $user, 'api');

        return $user;
    }

    /** Second step: the challenge token plus a TOTP or recovery code. */
    public function completeChallenge(string $challenge, string $code, ?string $ip): User
    {
        try {
            $payload = json_decode(Crypt::decryptString($challenge), true);
        } catch (DecryptException) {
            $payload = null;
        }
        $nonce = $payload['n'] ?? null;
        $user = isset($payload['u']) ? User::find($payload['u']) : null;
        if (! $user || ! $nonce || ($payload['e'] ?? 0) < time() || ! Cache::has("2fa-challenge:$nonce")) {
            throw new DomainException('The sign-in challenge has expired. Sign in again.', 'challenge-expired', 401);
        }
        if ($this->guard->locked($user)) {
            $this->guard->blocked($user, $ip);
            throw new DomainException('This account is temporarily locked after repeated failed sign-ins.', 'account-locked', 423);
        }
        if (! $this->twoFactor->check($user, $code)) {
            $this->guard->failed($user->email, $ip, $user, 'two-factor');
            if (Cache::increment("2fa-challenge:$nonce") > self::CHALLENGE_ATTEMPTS) {
                Cache::forget("2fa-challenge:$nonce");
            }
            throw new DomainException('The authentication code is invalid.', 'two-factor-invalid', 401);
        }
        Cache::forget("2fa-challenge:$nonce");
        $this->guard->succeeded($user->email, $ip, $user, 'api-2fa');

        return $user;
    }

    private function challengeFor(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put("2fa-challenge:$nonce", 1, self::CHALLENGE_TTL);

        return Crypt::encryptString(json_encode(['u' => $user->id, 'n' => $nonce, 'e' => time() + self::CHALLENGE_TTL]));
    }
}
