<?php

namespace Tests\Feature\Security;

use App\Modules\Guests\Domain\Guest;
use App\Modules\MemberPortal\Application\MemberAuthService;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Platform\Tenancy\TenantContext;
use Tests\Feature\Member\MemberTestCase;

/** SEC2 regressions on the member portal sign-up (low findings). */
class Fix2MemberTest extends MemberTestCase
{
    private function account(string $col, string $value): ?MemberAccount
    {
        return app(TenantContext::class)->withoutScope(fn () => MemberAccount::where($col, $value)->first());
    }

    private function verify(string $contact, string $recipient)
    {
        return $this->postJson('/account/register/verify', ['contact' => $contact, 'code' => $this->lastCode($recipient),
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    /** tenancy:memberportal:phone-suffix-match-links-other-guest */
    public function test_phone_signup_links_only_a_full_number_match(): void
    {
        // Same last nine digits, other country: no longer linked.
        $gabon = Guest::create(['first_name' => 'Ali', 'last_name' => 'Ba', 'phone' => '+241 690 11 22 33']);
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => '+237 690112233', 'first_name' => 'Paul', 'last_name' => 'Eto'])->assertOk();
        $this->verify('+237690112233', '237690112233')->assertOk();
        $linked = $this->account('phone', '237690112233')->guest_id;
        $this->assertNotSame($gabon->id, $linked);
        $this->assertSame('Paul', app(TenantContext::class)->withoutScope(fn () => Guest::find($linked))->first_name);
    }

    public function test_phone_signup_never_auto_links_when_several_profiles_share_the_number(): void
    {
        $a = Guest::create(['first_name' => 'Rose', 'last_name' => 'A', 'phone' => '677 00 11 22']);
        $b = Guest::create(['first_name' => 'Rose', 'last_name' => 'B', 'phone' => '+237677001122']);
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => '237677001122', 'first_name' => 'Rose', 'last_name' => 'C'])->assertOk();
        $this->verify('237677001122', '237677001122')->assertOk();
        $this->assertNotContains($this->account('phone', '237677001122')->guest_id, [$a->id, $b->id]);
    }

    /** authn:member:register-overwrites-pending-account */
    public function test_a_repeated_signup_does_not_rename_the_pending_account_and_codes_are_capped_per_day(): void
    {
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => 'nina@test.cm', 'first_name' => 'Nina', 'last_name' => 'Bell'])->assertOk();
        $this->travel(2)->minutes();
        $this->postJson('/account/register', ['contact' => 'nina@test.cm', 'first_name' => 'Evil', 'last_name' => 'Rename'])->assertOk();
        $acc = $this->account('email', 'nina@test.cm');
        $this->assertSame('Nina', $acc->first_name);
        $this->assertSame('Bell', $acc->last_name);

        for ($i = 0; $i < MemberAuthService::OTP_DAILY_CAP + 3; $i++) {
            $this->travel(2)->minutes();
            $this->postJson('/account/register', ['contact' => 'nina@test.cm', 'first_name' => 'Nina', 'last_name' => 'Bell'])->assertOk();
        }
        $sent = app(TenantContext::class)->withoutScope(fn () => OutboxMessage::where('recipient', 'nina@test.cm')->count());
        $this->assertSame(MemberAuthService::OTP_DAILY_CAP, $sent);
    }
}
