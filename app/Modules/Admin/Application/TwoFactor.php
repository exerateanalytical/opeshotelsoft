<?php

namespace App\Modules\Admin\Application;

use App\Models\User;
use App\Modules\Admin\Domain\Totp;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** TOTP enrolment, verification and recovery codes for staff users. */
class TwoFactor
{
    /** Roles that must enrol before using the application. */
    public const REQUIRED_ROLES = ['group-owner', 'general-manager', 'finance-director', 'accountant'];

    /** Tests run without enforcement (TestCase users are general managers) unless a test turns it on. */
    public static bool $enforceInTests = false;

    public function required(User $user): bool
    {
        return $user->hasAnyRole(self::REQUIRED_ROLES);
    }

    public function enabled(User $user): bool
    {
        return $user->mfa_confirmed_at !== null && $user->mfa_secret;
    }

    /**
     * Required role, not yet enrolled. The seeded demo accounts are exempt while DEMO_LOGINS is on
     * (shared, non-production accounts); tests are exempt unless a test opts in (SEC1 audit).
     */
    public function mustEnrol(User $user): bool
    {
        if (app()->runningUnitTests() && ! self::$enforceInTests) {
            return false;
        }
        if (config('app.demo_logins') && ($user->email === 'admin@opeshotelsoft.test' || str_ends_with((string) $user->email, '@demo.opeshotelsoft.test'))) {
            return false;
        }

        return $this->required($user) && ! $this->enabled($user);
    }

    /** Start (or restart) enrolment: a new unconfirmed secret. */
    public function begin(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null])->save();

        return $secret;
    }

    /** Confirm enrolment with a first code; returns plain recovery codes (shown once). */
    public function confirm(User $user, string $code): array
    {
        if (! $user->mfa_secret || ! Totp::verify($user->mfa_secret, $code)) {
            throw new DomainException(__('auth.2fa.invalid_code'), 'invalid-code');
        }
        $user->forceFill(['mfa_confirmed_at' => now()])->save();

        return $this->regenerateCodes($user);
    }

    public function regenerateCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['mfa_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes))])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null])->save();
    }

    /** A TOTP code or an unused recovery code (consumed on use). */
    public function check(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }
        $step = Totp::matchStep($user->mfa_secret, $code);
        if ($step !== null) {
            // Replay protection: a sign-in code is accepted once; a step at or before the last accepted one is refused.
            // Atomic update so two concurrent sign-ins with the same code cannot both pass.
            $won = User::whereKey($user->id)
                ->where(fn ($q) => $q->whereNull('mfa_last_step')->orWhere('mfa_last_step', '<', $step))
                ->update(['mfa_last_step' => $step]);
            if ($won === 1) {
                $user->forceFill(['mfa_last_step' => $step])->syncOriginalAttribute('mfa_last_step');

                return true;
            }

            return false;
        }
        $code = Str::lower(trim($code));
        $hashes = json_decode((string) $user->mfa_recovery_codes, true) ?: [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['mfa_recovery_codes' => json_encode(array_values($hashes))])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingCodes(User $user): int
    {
        return count(json_decode((string) $user->mfa_recovery_codes, true) ?: []);
    }
}
