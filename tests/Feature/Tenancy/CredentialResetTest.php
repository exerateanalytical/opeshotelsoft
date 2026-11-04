<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** GAPH: administrator password / two-factor resets and hotel status. */
class CredentialResetTest extends TestCase
{
    private const REASON = ['reason' => 'Lost phone, ticket 42'];

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('ca|u|'.$this->user->id);
    }

    private function token(User $u): void
    {
        DB::table('oauth_access_tokens')->insert(['id' => str_pad(uniqid(), 80, 'x'), 'user_id' => $u->id, 'client_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 't', 'revoked' => false, 'created_at' => now(), 'expires_at' => now()->addDay()]);
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('user_id', $u->id)->where('revoked', false)->count());
    }

    private function enrolled(User $u): User
    {
        $u->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now(), 'mfa_recovery_codes' => json_encode([Hash::make('a')])])->save();

        return $u;
    }

    public function test_password_reset_returns_temporary_password_revokes_tokens_and_audits(): void
    {
        $target = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->token($target);
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $res = $this->postJson("/api/v1/users/{$target->id}/password-reset", self::REASON)->assertOk();
        $temp = $res->json('temporary_password');
        $this->assertSame(16, strlen($temp));

        $target->refresh();
        $this->assertTrue(Hash::check($temp, $target->password));
        $this->assertTrue($target->must_change_password);
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $target->id)->where('revoked', false)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());

        $log = Activity::where('event', 'password_reset')->latest('id')->first();
        $this->assertSame($this->user->id, $log->causer_id);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame('Lost phone, ticket 42', $log->properties['reason']);
        $this->assertStringNotContainsString($temp, json_encode($log->toArray()));
        $this->assertDatabaseHas('domain_events', ['type' => 'identity.user.password_reset', 'aggregate_id' => $target->id]);

        // Temporary password: the API stays closed until it is changed.
        $this->actAs($target, $this->hotel)->getJson('/api/v1/hotels')->assertForbidden();
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_two_factor_reset_clears_secret_requires_reenrolment_and_audits(): void
    {
        $target = $this->enrolled($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]));
        $this->token($target);

        $this->postJson("/api/v1/users/{$target->id}/two-factor-reset", self::REASON)->assertOk();
        $target->refresh();
        $this->assertNull($target->mfa_secret);
        $this->assertNull($target->mfa_confirmed_at);
        $this->assertNull($target->mfa_recovery_codes);
        $this->assertTrue($target->must_enrol_mfa);
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $target->id)->where('revoked', false)->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_reset', 'causer_id' => $this->user->id, 'subject_id' => $target->id]);

        // Even a non-required role must re-enrol before using the API.
        $this->actAs($target, $this->hotel)->getJson('/api/v1/hotels')->assertForbidden();
    }

    public function test_self_two_factor_reset_and_self_password_reset_are_refused(): void
    {
        $this->postJson("/api/v1/users/{$this->user->id}/two-factor-reset", self::REASON)->assertForbidden();
        $this->postJson("/api/v1/users/{$this->user->id}/password-reset", self::REASON)->assertForbidden();
    }

    public function test_reason_is_required(): void
    {
        $target = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->postJson("/api/v1/users/{$target->id}/password-reset", [])->assertUnprocessable();
    }

    public function test_without_users_manage_is_forbidden(): void
    {
        $target = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($this->makeUser($this->group, 'front-office-manager', hotels: [$this->hotel]), $this->hotel);
        $this->postJson("/api/v1/users/{$target->id}/password-reset", self::REASON)->assertForbidden();
        $this->postJson("/api/v1/users/{$target->id}/two-factor-reset", self::REASON)->assertForbidden();
        $this->assertFalse($target->fresh()->must_change_password);
    }

    public function test_privilege_escalation_is_refused(): void
    {
        $owner = $this->makeUser($this->group, 'group-owner', allHotels: true);
        $this->postJson("/api/v1/users/{$owner->id}/password-reset", self::REASON)->assertForbidden();
        $this->postJson("/api/v1/users/{$owner->id}/two-factor-reset", self::REASON)->assertForbidden();
        $this->assertFalse($owner->fresh()->must_change_password);

        // Owner may reset a general manager (same or lower level).
        $gm = $this->makeUser($this->group, 'general-manager', allHotels: true);
        $this->actAs($owner, $this->hotel)->postJson("/api/v1/users/{$gm->id}/password-reset", self::REASON)->assertOk();

        // Platform administrators are out of reach of group administrators.
        $platform = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $platform->forceFill(['is_platform_admin' => true])->save();
        $this->postJson("/api/v1/users/{$platform->id}/password-reset", self::REASON)->assertForbidden();
    }

    public function test_hotel_scoped_admin_cannot_reach_users_outside_scope_or_other_groups(): void
    {
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $scoped = $this->makeUser($this->group, 'general-manager', hotels: [$this->hotel]);
        $there = $this->makeUser($this->group, 'receptionist', hotels: [$other]);
        $wide = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($scoped, $this->hotel);
        $this->postJson("/api/v1/users/{$there->id}/password-reset", self::REASON)->assertForbidden();
        $this->postJson("/api/v1/users/{$wide->id}/password-reset", self::REASON)->assertForbidden();

        [$otherGroup] = $this->makeHotel('Other Group', 'OTH');
        $foreign = $this->makeUser($otherGroup, 'receptionist', allHotels: true);
        $this->actAs($this->user, $this->hotel)->postJson("/api/v1/users/{$foreign->id}/password-reset", self::REASON)->assertForbidden();
    }

    public function test_resets_are_rate_limited(): void
    {
        $target = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        foreach (range(1, 5) as $i) {
            $this->postJson("/api/v1/users/{$target->id}/two-factor-reset", self::REASON)->assertOk();
        }
        $this->postJson("/api/v1/users/{$target->id}/two-factor-reset", self::REASON)->assertStatus(429);
    }

    public function test_hotel_status_toggle_with_guards(): void
    {
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);

        // Current tenant hotel cannot be deactivated.
        $this->patchJson("/api/v1/hotels/{$this->hotel->id}/status", ['status' => 'inactive'] + self::REASON)->assertUnprocessable();

        $this->patchJson("/api/v1/hotels/{$other->id}/status", ['status' => 'inactive'] + self::REASON)->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertDatabaseHas('activity_log', ['event' => 'hotel_status_changed', 'causer_id' => $this->user->id, 'subject_id' => $other->id]);

        // Now the only active hotel is the tenant one; working from the other hotel, the last-hotel guard refuses.
        $this->actAs($this->user, $other);
        $this->patchJson("/api/v1/hotels/{$this->hotel->id}/status", ['status' => 'inactive'] + self::REASON)->assertUnprocessable();
        $this->assertSame('active', $this->hotel->fresh()->status);

        $this->patchJson("/api/v1/hotels/{$other->id}/status", ['status' => 'active'] + self::REASON)->assertOk();

        // Without hotels.manage.
        $this->actAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]), $this->hotel);
        $this->patchJson("/api/v1/hotels/{$this->hotel->id}/status", ['status' => 'active'] + self::REASON)->assertForbidden();
    }
}
