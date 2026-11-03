<?php

namespace Tests\Feature\Member;

use App\Modules\Guests\Domain\Guest;
use App\Modules\MemberPortal\Application\MemberAuthService;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class MemberAuthTest extends MemberTestCase
{
    private function raw(string $email): ?MemberAccount
    {
        return app(TenantContext::class)->withoutScope(fn () => MemberAccount::where('email', $email)->first());
    }

    public function test_signup_needs_the_code_then_links_the_existing_guest_by_verified_contact(): void
    {
        $guest = Guest::create(['first_name' => 'Jean', 'last_name' => 'Mballa', 'email' => 'jean@test.cm']);
        $this->asVisitor();

        $this->postJson('/account/register', ['contact' => 'Jean@Test.cm', 'first_name' => 'Jean', 'last_name' => 'Mballa'])->assertOk()->assertJsonPath('status', 'code_sent');
        $a = $this->raw('jean@test.cm');
        $this->assertNull($a->guest_id, 'not linked before the code is proven');
        $this->assertNull($a->verified_at);
        $this->postJson('/account/login', ['identifier' => 'jean@test.cm', 'password' => 'anything-1'])->assertStatus(422);
        $this->getJson('/account/card')->assertUnauthorized();

        $code = $this->lastCode('jean@test.cm');
        $this->postJson('/account/register/verify', ['contact' => 'jean@test.cm', 'code' => $code, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk()->assertJsonPath('redirect', '/account');
        $a->refresh();
        $this->assertSame($guest->id, $a->guest_id);
        $this->assertNotNull($a->verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $a->password));
        $this->assertAuthenticatedAs($a, 'member');
        $this->assertSame(1, app(TenantContext::class)->withoutScope(fn () => Guest::count()), 'no duplicate guest profile');
    }

    public function test_signup_by_phone_matches_the_profile_whatever_the_formatting(): void
    {
        $guest = Guest::create(['first_name' => 'Paul', 'last_name' => 'Eto', 'phone' => '690 11 22 33']);
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => '+237 690112233', 'first_name' => 'Paul', 'last_name' => 'Eto'])->assertOk();
        $code = $this->lastCode('237690112233');
        $this->postJson('/account/register/verify', ['contact' => '+237690112233', 'code' => $code, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->assertSame($guest->id, app(TenantContext::class)->withoutScope(fn () => MemberAccount::where('phone', '237690112233')->value('guest_id')));
    }

    public function test_new_contact_creates_a_fresh_profile_after_verification(): void
    {
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => 'new@test.cm', 'first_name' => 'Nina', 'last_name' => 'Bell', 'locale' => 'en'])->assertOk();
        $this->postJson('/account/register/verify', ['contact' => 'new@test.cm', 'code' => $this->lastCode('new@test.cm'), 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $g = app(TenantContext::class)->withoutScope(fn () => Guest::where('email', 'new@test.cm')->first());
        $this->assertSame('Nina', $g->first_name);
        $this->assertSame('en', $g->language);
    }

    public function test_no_takeover_by_typing_someone_elses_email(): void
    {
        $guest = Guest::create(['first_name' => 'Vic', 'last_name' => 'Tim', 'email' => 'victim@test.cm']); // the victim has a profile but no account
        $this->asVisitor();

        $this->postJson('/account/register', ['contact' => 'victim@test.cm', 'first_name' => 'Evil', 'last_name' => 'Attacker'])->assertOk();
        $this->assertNull($this->raw('victim@test.cm')->guest_id);
        // The attacker never sees the code: guesses fail, and after five misses even the right code is dead.
        $real = $this->lastCode('victim@test.cm');
        $guess = $real === '123456' ? '654321' : '123456';
        foreach (range(1, 5) as $i) {
            $this->postJson('/account/register/verify', ['contact' => 'victim@test.cm', 'code' => $guess, 'password' => 'Hack-2026xx', 'password_confirmation' => 'Hack-2026xx'])->assertStatus(422);
        }
        $this->postJson('/account/register/verify', ['contact' => 'victim@test.cm', 'code' => $real, 'password' => 'Hack-2026xx', 'password_confirmation' => 'Hack-2026xx'])->assertStatus(422);
        $this->assertNull($this->raw('victim@test.cm')->guest_id);
        $this->assertNull($this->raw('victim@test.cm')->verified_at);
        $this->getJson('/account/card')->assertUnauthorized();
        $this->assertNotNull($guest->fresh());
    }

    public function test_pending_signup_by_a_stranger_cannot_set_the_owners_password(): void
    {
        $guest = Guest::create(['first_name' => 'Owner', 'last_name' => 'Real', 'email' => 'owner@test.cm']);
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => 'owner@test.cm', 'first_name' => 'Mallory', 'last_name' => 'X'])->assertOk(); // a stranger starts it
        $this->postJson('/account/register', ['contact' => 'owner@test.cm', 'first_name' => 'Owner', 'last_name' => 'Real'])->assertOk(); // the owner starts it
        $this->postJson('/account/register/verify', ['contact' => 'owner@test.cm', 'code' => $this->lastCode('owner@test.cm'), 'password' => 'Owner-2026xx', 'password_confirmation' => 'Owner-2026xx'])->assertOk();
        $a = $this->raw('owner@test.cm');
        $this->assertSame($guest->id, $a->guest_id);
        $this->assertTrue(Hash::check('Owner-2026xx', $a->password));
        $this->assertFalse(Hash::check('Hack-2026xx', $a->password));
    }

    public function test_a_profile_with_an_account_is_not_linked_twice(): void
    {
        [$a, $guest] = $this->member('twice@test.cm');
        $this->asVisitor();
        $this->postJson('/account/register', ['contact' => 'twice@test.cm', 'first_name' => 'A', 'last_name' => 'B'])->assertOk()->assertJsonPath('status', 'code_sent');
        $this->assertSame(0, $this->codesSent(), 'no code is sent for an existing account, but the answer is the same');
        $this->assertSame(1, app(TenantContext::class)->withoutScope(fn () => MemberAccount::where('guest_id', $guest->id)->count()));
    }

    public function test_password_sign_in_and_sign_out(): void
    {
        [$a] = $this->member('amina@test.cm');
        $this->loginAs($a);
        $this->assertAuthenticatedAs($a, 'member');
        $this->getJson('/account/card')->assertOk();
        $this->post('/account/logout')->assertRedirect('/login?tab=member');
        $this->assertGuest('member');
        $this->getJson('/account/card')->assertUnauthorized();
    }

    public function test_one_time_code_sign_in_and_password_reset(): void
    {
        [$a] = $this->member('otp@test.cm');
        $this->asVisitor();
        $this->postJson('/account/login/code', ['identifier' => 'otp@test.cm'])->assertOk();
        $this->postJson('/account/login/code/verify', ['identifier' => 'otp@test.cm', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/account/login/code/verify', ['identifier' => 'otp@test.cm', 'code' => $this->lastCode('otp@test.cm')])->assertOk();
        $this->assertAuthenticatedAs($a->fresh(), 'member');

        $this->asVisitor();
        $a->forceFill(['locked_until' => now()->addMinutes(10), 'otp_sent_at' => null])->save();
        $this->postJson('/account/forgot', ['identifier' => 'otp@test.cm'])->assertOk();
        $this->postJson('/account/reset', ['identifier' => 'otp@test.cm', 'code' => $this->lastCode('otp@test.cm'), 'password' => 'Brand-new-2026', 'password_confirmation' => 'Brand-new-2026'])->assertOk();
        $this->assertNull($a->fresh()->locked_until, 'a reset proves the contact and lifts the lock');
        $this->postJson('/account/login', ['identifier' => 'otp@test.cm', 'password' => 'Brand-new-2026'])->assertOk();
        $this->asVisitor();
        $this->postJson('/account/login', ['identifier' => 'otp@test.cm', 'password' => self::PASSWORD])->assertStatus(422);
    }

    public function test_a_login_code_cannot_be_used_to_reset_the_password(): void
    {
        $this->member('purpose@test.cm');
        $this->asVisitor();
        $this->postJson('/account/login/code', ['identifier' => 'purpose@test.cm'])->assertOk();
        $this->postJson('/account/reset', ['identifier' => 'purpose@test.cm', 'code' => $this->lastCode('purpose@test.cm'), 'password' => 'Brand-new-2026', 'password_confirmation' => 'Brand-new-2026'])->assertStatus(422);
    }

    public function test_throttle_and_lockout(): void
    {
        [$a] = $this->member('lock@test.cm');
        $this->asVisitor();
        foreach (range(1, MemberAuthService::THROTTLE_ATTEMPTS) as $i) {
            $this->postJson('/account/login', ['identifier' => 'lock@test.cm', 'password' => 'wrong-pass-1'])->assertStatus(422);
        }
        $this->postJson('/account/login', ['identifier' => 'lock@test.cm', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest('member');

        // Lockout: after the threshold the right password is refused too, with the same answer as a wrong one.
        RateLimiter::clear('member-login:lock@test.cm|127.0.0.1');
        $a->forceFill(['failed_attempts' => MemberAuthService::LOCK_THRESHOLD - 1])->save();
        $this->postJson('/account/login', ['identifier' => 'lock@test.cm', 'password' => 'wrong-pass-1'])->assertStatus(422);
        $this->assertTrue($a->fresh()->isLocked());
        RateLimiter::clear('member-login:lock@test.cm|127.0.0.1');
        $locked = $this->postJson('/account/login', ['identifier' => 'lock@test.cm', 'password' => self::PASSWORD])->assertStatus(422);
        $wrong = $this->postJson('/account/login', ['identifier' => 'nobody@test.cm', 'password' => 'whatever-1'])->assertStatus(422);
        $this->assertSame($wrong->json('errors.identifier'), $locked->json('errors.identifier'));
        $this->assertGuest('member');
    }

    public function test_no_account_enumeration(): void
    {
        $this->member('known@test.cm');
        $this->asVisitor();
        $known = $this->postJson('/account/login', ['identifier' => 'known@test.cm', 'password' => 'wrong-pass-1']);
        $unknown = $this->postJson('/account/login', ['identifier' => 'unknown@test.cm', 'password' => 'wrong-pass-1']);
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('errors'), $unknown->json('errors'));

        foreach (['/account/login/code', '/account/forgot'] as $url) {
            $k = $this->postJson($url, ['identifier' => 'known@test.cm']);
            $u = $this->postJson($url, ['identifier' => 'unknown@test.cm']);
            $this->assertSame([$k->status(), $k->json()], [$u->status(), $u->json()], $url);
        }
        $this->assertSame([], app(TenantContext::class)->withoutScope(fn () => OutboxMessage::where('recipient', 'unknown@test.cm')->pluck('id')->all()));
        $r1 = $this->postJson('/account/register', ['contact' => 'known@test.cm', 'first_name' => 'A', 'last_name' => 'B']);
        $r2 = $this->postJson('/account/register', ['contact' => 'fresh@test.cm', 'first_name' => 'A', 'last_name' => 'B']);
        $this->assertSame([$r1->status(), $r1->json()], [$r2->status(), $r2->json()]);
    }
}
