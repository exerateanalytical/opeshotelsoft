<?php

namespace App\Modules\Admin\Application;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Brute-force protection shared by web login and API token issuance:
 * a short per email+IP throttle (429) and a per-account lockout after
 * repeated failures. Failures, lockouts and successes are audited (log "security").
 */
class LoginGuard
{
    /** Failed attempts per email+IP before the throttle answers 429. */
    public const THROTTLE_ATTEMPTS = 5;

    public const THROTTLE_DECAY = 300;

    /** Consecutive failures (any IP) before the account is locked. */
    public const LOCK_THRESHOLD = 10;

    public const LOCK_MINUTES = 15;

    public function key(string $email, ?string $ip): string
    {
        return 'login:'.strtolower(trim($email)).'|'.$ip;
    }

    public function throttled(string $email, ?string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->key($email, $ip), self::THROTTLE_ATTEMPTS);
    }

    public function retryAfter(string $email, ?string $ip): int
    {
        return RateLimiter::availableIn($this->key($email, $ip));
    }

    public function locked(?User $user): bool
    {
        return $user && $user->locked_until && now()->lt(Carbon::parse($user->locked_until));
    }

    public function blocked(User $user, ?string $ip): void
    {
        activity('security')->performedOn($user)->event('login-blocked')->withProperties(['ip' => $ip])->log('Sign-in refused: account locked');
    }

    public function failed(string $email, ?string $ip, ?User $user, string $reason = 'password'): void
    {
        RateLimiter::hit($this->key($email, $ip), self::THROTTLE_DECAY);
        if (! $user) {
            activity('security')->event('login-failed')
                ->withProperties(['email' => strtolower($email), 'ip' => $ip, 'reason' => 'unknown-user'])->log('Failed sign-in attempt');

            return;
        }
        $count = (int) $user->failed_logins + 1;
        $lock = $count >= self::LOCK_THRESHOLD;
        $user->forceFill($lock
            ? ['failed_logins' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
            : ['failed_logins' => $count])->saveQuietly();
        activity('security')->performedOn($user)->event('login-failed')
            ->withProperties(['ip' => $ip, 'reason' => $reason, 'failures' => $count])->log('Failed sign-in attempt');
        if ($lock) {
            activity('security')->performedOn($user)->event('account-locked')
                ->withProperties(['ip' => $ip, 'until' => Carbon::parse($user->locked_until)->toIso8601String()])
                ->log('Account locked after repeated failed sign-ins');
        }
    }

    public function succeeded(string $email, ?string $ip, User $user, string $channel): void
    {
        RateLimiter::clear($this->key($email, $ip));
        if ($user->failed_logins || $user->locked_until) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->saveQuietly();
        }
        activity('security')->causedBy($user)->performedOn($user)->event('login')
            ->withProperties(['ip' => $ip, 'channel' => $channel])->log('Signed in');
    }
}
