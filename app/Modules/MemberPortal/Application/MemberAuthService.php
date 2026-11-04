<?php

namespace App\Modules\MemberPortal\Application;

use App\Modules\Guests\Domain\Guest;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Tenancy\Domain\Group;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Customer sign-up, sign-in and password reset.
 *
 * - A new account is never linked to a guest profile until the contact (email or phone) is proven by a one-time code,
 *   and the profile is matched on that proven contact only (no takeover by typing someone else's email).
 * - The password is chosen together with the code, so a pending sign-up started by someone else cannot set it.
 * - Every public answer is identical whether or not the contact belongs to an account (no enumeration); failed
 *   password or code attempts are throttled per contact + IP and lock the account after repeated failures.
 */
class MemberAuthService
{
    public const OTP_MINUTES = 10;

    public const OTP_MAX_ATTEMPTS = 5;

    public const OTP_RESEND_SECONDS = 60;

    /** One-time codes sent to one contact per day, all purposes together (SEC2). */
    public const OTP_DAILY_CAP = 10;

    public const THROTTLE_ATTEMPTS = 5;

    public const THROTTLE_DECAY = 300;

    public const LOCK_THRESHOLD = 10;

    public const LOCK_MINUTES = 15;

    public function __construct(private TenantContext $ctx, private Notifier $notifier, private EventBus $bus) {}

    /** @return array{type:string,value:string}|null email (lower case) or phone (digits only) */
    public static function parseContact(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, '@')) {
            return filter_var($raw, FILTER_VALIDATE_EMAIL) ? ['type' => 'email', 'value' => mb_strtolower($raw)] : null;
        }
        $digits = preg_replace('/\D+/', '', $raw);

        return strlen($digits) >= 8 && strlen($digits) <= 20 ? ['type' => 'phone', 'value' => $digits] : null;
    }

    /** The group named by its slug, or the only active group when the deployment has one. */
    public function group(?string $slug): ?Group
    {
        return $this->ctx->withoutScope(function () use ($slug) {
            if ($slug) {
                return Group::where('slug', $slug)->where('status', 'active')->first();
            }
            $all = Group::where('status', 'active')->limit(2)->get();

            return $all->count() === 1 ? $all->first() : null;
        });
    }

    // ------------------------------------------------------------------ sign-up

    /** Start a sign-up: stores a pending account and sends a code. Silent when the contact already has an account. */
    public function register(array $d, ?string $slug): void
    {
        $contact = self::parseContact($d['contact'] ?? null) ?? throw ValidationException::withMessages(['contact' => __('member.auth.contact_invalid')]);
        $group = $this->group($slug) ?? throw ValidationException::withMessages(['contact' => __('member.auth.group_required')]);
        $this->ctx->set($group->id, null, null);
        $col = $contact['type'] === 'email' ? 'email' : 'phone';

        $existing = $this->ctx->withoutScope(fn () => MemberAccount::where('group_id', $group->id)->where($col, $contact['value'])->first());
        if ($existing && $existing->verified_at) {
            return; // same answer as a fresh sign-up; the owner can sign in or reset the password
        }
        $account = $existing ?? new MemberAccount([$col => $contact['value']]);
        $account->group_id = $group->id;
        // Names, language and consent are only a request: they are applied to the profile after the code is proven.
        // SEC2: a repeated sign-up only re-sends the code; it never replaces the names of a pending account
        // (someone else could otherwise rename the profile the real owner is about to verify).
        if (! $existing) {
            $account->fill([
                'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'locale' => ($d['locale'] ?? null) === 'en' ? 'en' : 'fr',
                'marketing_consent' => (bool) ($d['marketing_consent'] ?? false),
            ]);
            $account->save();
        }
        $this->issueOtp($account, 'signup', $contact);
    }

    /** Finish a sign-up with the code and the chosen password: links (or creates) the guest profile. */
    public function verifySignup(string $rawContact, string $code, string $password, ?string $slug): MemberAccount
    {
        $contact = self::parseContact($rawContact);
        $group = $this->group($slug);
        $fail = fn () => ValidationException::withMessages(['code' => __('member.auth.code_invalid')]);
        if (! $contact || ! $group) {
            throw $fail();
        }
        $col = $contact['type'] === 'email' ? 'email' : 'phone';
        $account = $this->ctx->withoutScope(fn () => MemberAccount::where('group_id', $group->id)->where($col, $contact['value'])->whereNull('verified_at')->first());
        if (! $account || ! $this->checkOtp($account, 'signup', $code)) {
            throw $fail();
        }
        $this->ctx->set($group->id, null, null);

        return DB::transaction(function () use ($account, $contact, $password) {
            $guest = $this->matchGuest($contact);
            if ($guest && MemberAccount::where('guest_id', $guest->id)->where('id', '!=', $account->id)->whereNotNull('verified_at')->exists()) {
                throw ValidationException::withMessages(['code' => __('member.auth.profile_taken')]);
            }
            $guest ??= Guest::create([
                'first_name' => $account->first_name, 'last_name' => $account->last_name, 'language' => $account->locale,
                $contact['type'] => $contact['value'], 'marketing_consent' => $account->marketing_consent, 'consent_at' => $account->marketing_consent ? now() : null,
            ]);
            $account->forceFill([
                'guest_id' => $guest->id, 'verified_contact' => $contact['type'], 'verified_at' => now(), 'password' => $password,
                'otp_hash' => null, 'otp_purpose' => null, 'otp_expires_at' => null, 'otp_attempts' => 0, 'failed_attempts' => 0, 'locked_until' => null,
            ])->save();
            $this->bus->record('memberportal.account_created', ['account_id' => $account->id, 'guest_id' => $guest->id, 'contact' => $contact['type']], 'member_account', $account->id);

            return $account->refresh();
        });
    }

    // ------------------------------------------------------------------ sign-in

    public function attemptPassword(string $identifier, string $password, ?string $ip): MemberAccount
    {
        $contact = self::parseContact($identifier);
        $this->assertNotThrottled($identifier, $ip);
        $accounts = $contact ? $this->verifiedAccounts($contact) : collect();
        $winner = null;
        foreach ($accounts as $a) {
            $ok = $a->password && Hash::check($password, $a->password);
            if ($ok && ! $a->isLocked() && ! $winner) {
                $winner = $a;
            }
        }
        if ($accounts->isEmpty()) {
            Hash::make($password); // comparable cost to a real check
        }
        if (! $winner) {
            $this->failed($identifier, $ip, $accounts);
            throw $this->genericFailure();
        }

        return $this->succeeded($winner, $identifier, $ip, 'password');
    }

    /** Send a sign-in code. Always silent about whether the contact has an account. */
    public function sendLoginCode(string $identifier): void
    {
        $this->sendCode($identifier, 'login');
    }

    public function attemptCode(string $identifier, string $code, ?string $ip): MemberAccount
    {
        $contact = self::parseContact($identifier);
        $this->assertNotThrottled($identifier, $ip);
        $accounts = $contact ? $this->verifiedAccounts($contact) : collect();
        $winner = null;
        foreach ($accounts as $a) {
            if ($this->checkOtp($a, 'login', $code) && ! $a->isLocked() && ! $winner) {
                $winner = $a;
            }
        }
        if (! $winner) {
            $this->failed($identifier, $ip, $accounts, 'code');
            throw $this->genericFailure();
        }

        return $this->succeeded($winner, $identifier, $ip, 'code');
    }

    // ------------------------------------------------------------------ password reset

    public function sendResetCode(string $identifier): void
    {
        $this->sendCode($identifier, 'reset');
    }

    public function reset(string $identifier, string $code, string $password, ?string $ip): MemberAccount
    {
        $contact = self::parseContact($identifier);
        $this->assertNotThrottled($identifier, $ip);
        $accounts = $contact ? $this->verifiedAccounts($contact) : collect();
        foreach ($accounts as $a) {
            if ($this->checkOtp($a, 'reset', $code)) {
                $a->forceFill(['password' => $password, 'failed_attempts' => 0, 'locked_until' => null])->save();
                RateLimiter::clear($this->limiterKey($identifier, $ip));
                activity('security')->performedOn($a)->event('member-password-reset')->withProperties(['ip' => $ip])->log('Member password reset');

                return $a;
            }
        }
        RateLimiter::hit($this->limiterKey($identifier, $ip), self::THROTTLE_DECAY);
        throw ValidationException::withMessages(['code' => __('member.auth.code_invalid')]);
    }

    // ------------------------------------------------------------------ internals

    private function sendCode(string $identifier, string $purpose): void
    {
        $contact = self::parseContact($identifier);
        if (! $contact) {
            return;
        }
        foreach ($this->verifiedAccounts($contact) as $a) {
            $this->issueOtp($a, $purpose, $contact);
        }
    }

    /** @return \Illuminate\Support\Collection<int, MemberAccount> */
    private function verifiedAccounts(array $contact)
    {
        $col = $contact['type'] === 'email' ? 'email' : 'phone';

        return $this->ctx->withoutScope(fn () => MemberAccount::where($col, $contact['value'])->whereNotNull('verified_at')->whereNotNull('guest_id')->orderBy('id')->get());
    }

    private function issueOtp(MemberAccount $a, string $purpose, array $contact): void
    {
        if ($a->otp_sent_at && $a->otp_sent_at->gt(now()->subSeconds(self::OTP_RESEND_SECONDS)) && $a->otp_purpose === $purpose) {
            return; // resend cool-down: the previous code is still valid
        }
        // Daily cap per contact (SMS and e-mail cost, flooding): silent beyond it, like every other refusal here.
        $daily = 'member-otp-day:'.sha1($contact['type'].'|'.$contact['value']);
        if (RateLimiter::tooManyAttempts($daily, self::OTP_DAILY_CAP)) {
            return;
        }
        RateLimiter::hit($daily, 86400);
        $code = (string) random_int(100000, 999999);
        $a->forceFill(['otp_hash' => $this->hash($a, $purpose, $code), 'otp_purpose' => $purpose, 'otp_expires_at' => now()->addMinutes(self::OTP_MINUTES),
            'otp_attempts' => 0, 'otp_sent_at' => now()])->save();
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($a->group_id, null, null);
        try {
            $this->notifier->notify('guest_portal.otp', [$contact['type'] => $contact['value'], 'locale' => $a->locale, 'name' => trim("$a->first_name $a->last_name")],
                ['code' => $code, 'minutes' => self::OTP_MINUTES], ['type' => 'member_account', 'id' => $a->id]);
        } finally {
            $this->ctx->set(...$saved);
        }
    }

    /** Constant-time check of a one-time code; wrong codes count and the code dies after OTP_MAX_ATTEMPTS. */
    private function checkOtp(MemberAccount $a, string $purpose, string $code): bool
    {
        if (! $a->otp_hash || $a->otp_purpose !== $purpose || ! $a->otp_expires_at || $a->otp_expires_at->isPast() || $a->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            return false;
        }
        if (hash_equals($a->otp_hash, $this->hash($a, $purpose, trim($code)))) {
            $a->forceFill(['otp_hash' => null, 'otp_purpose' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();

            return true;
        }
        $a->forceFill(['otp_attempts' => $a->otp_attempts + 1])->save();

        return false;
    }

    private function hash(MemberAccount $a, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $a->id.'|'.$purpose.'|'.$code, (string) config('app.key'));
    }

    /** The guest profile for a proven contact (a profile with a loyalty membership first). */
    private function matchGuest(array $contact): ?Guest
    {
        $q = Guest::query();
        if ($contact['type'] === 'email') {
            $q->whereRaw('lower(email) = ?', [$contact['value']]);
        } else {
            // Full E.164 comparison (SEC2): a profile is linked only when its whole normalised number equals the proven
            // one, and never when several profiles carry that number (staff merge them first).
            $cc = $this->callingCode();
            $want = self::e164($contact['value'], $cc);
            $digits = "regexp_replace(coalesce(phone, ''), '\\D', '', 'g')";
            $matches = $q->whereRaw("right($digits, 8) = ?", [substr($want, -8)])->get()
                ->filter(fn (Guest $g) => self::e164((string) $g->phone, $cc) === $want);

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return $q->orderByRaw('exists (select 1 from loyalty_memberships m where m.guest_id = guests.id) desc')->orderBy('id')->first();
    }

    /** Digits of an E.164 number: international prefix 00 dropped, local numbers get the calling code (trunk 0 dropped). */
    public static function e164(string $raw, string $callingCode): string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '00')) {
            return substr($d, 2);
        }
        if (strlen($d) <= 9) {
            return $callingCode.ltrim($d, '0');
        }

        return $d;
    }

    /** Calling code of the group's country (CEMAC), Cameroon by default. */
    private function callingCode(): string
    {
        $codes = ['CM' => '237', 'GA' => '241', 'CG' => '242', 'CF' => '236', 'TD' => '235', 'GQ' => '240'];
        $country = $this->ctx->withoutScope(fn () => \App\Modules\Tenancy\Domain\Hotel::where('group_id', $this->ctx->groupId())->orderBy('id')->value('country'));

        return $codes[strtoupper((string) $country)] ?? '237';
    }

    private function limiterKey(string $identifier, ?string $ip): string
    {
        return 'member-login:'.mb_strtolower(trim($identifier)).'|'.$ip;
    }

    private function assertNotThrottled(string $identifier, ?string $ip): void
    {
        $key = $this->limiterKey($identifier, $ip);
        if (RateLimiter::tooManyAttempts($key, self::THROTTLE_ATTEMPTS)) {
            throw ValidationException::withMessages(['identifier' => __('member.auth.throttle', ['seconds' => RateLimiter::availableIn($key)])])->status(429);
        }
    }

    private function genericFailure(): ValidationException
    {
        return ValidationException::withMessages(['identifier' => __('member.auth.failed')]);
    }

    private function failed(string $identifier, ?string $ip, $accounts, string $reason = 'password'): void
    {
        RateLimiter::hit($this->limiterKey($identifier, $ip), self::THROTTLE_DECAY);
        foreach ($accounts as $a) {
            $count = $a->failed_attempts + 1;
            $lock = $count >= self::LOCK_THRESHOLD;
            $a->forceFill($lock ? ['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)] : ['failed_attempts' => $count])->save();
            activity('security')->performedOn($a)->event('member-login-failed')->withProperties(['ip' => $ip, 'reason' => $reason, 'failures' => $count])->log('Failed member sign-in');
            if ($lock) {
                activity('security')->performedOn($a)->event('member-account-locked')->withProperties(['ip' => $ip])->log('Member account locked after repeated failures');
            }
        }
        if ($accounts->isEmpty()) {
            activity('security')->event('member-login-failed')->withProperties(['ip' => $ip, 'reason' => 'unknown'])->log('Failed member sign-in');
        }
    }

    private function succeeded(MemberAccount $a, string $identifier, ?string $ip, string $channel): MemberAccount
    {
        RateLimiter::clear($this->limiterKey($identifier, $ip));
        $a->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        activity('security')->performedOn($a)->event('member-login')->withProperties(['ip' => $ip, 'channel' => $channel])->log('Member signed in');

        return $a;
    }
}
