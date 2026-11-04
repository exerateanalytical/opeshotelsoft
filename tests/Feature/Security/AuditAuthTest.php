<?php

namespace Tests\Feature\Security;

use App\Modules\Admin\Application\TwoFactor;
use App\Modules\Admin\Domain\Totp;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * SEC1 audit regressions: authn:web-login:suspended-status-ignored,
 * session:suspension-does-not-invalidate-web-sessions, auth:mfa:required-roles-not-enforced-on-api,
 * secrets:seeder:demo-admin-fixed-password-in-default-seeder.
 */
class AuditAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test personal', 'users');
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        TwoFactor::$enforceInTests = false;
        parent::tearDown();
    }

    public function test_suspended_user_cannot_sign_in_on_the_web(): void
    {
        $this->user->forceFill(['status' => 'suspended'])->save();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password-123'])->assertInvalid('email');
        $this->assertGuest('web');

        // Nor through the 2FA step when the account was suspended between the password and the code.
        $this->user->forceFill(['status' => 'active'])->save();
        $secret = app(TwoFactor::class)->begin($this->user);
        app(TwoFactor::class)->confirm($this->user, Totp::at($secret, time()));
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password-123'])->assertRedirect(route('two-factor.challenge'));
        $this->user->forceFill(['status' => 'suspended'])->save();
        $this->post('/two-factor-challenge', ['code' => Totp::at($secret, time())])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_suspension_ends_a_live_web_session_and_revokes_tokens(): void
    {
        $this->user->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->user, 'web')->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');

        // Suspending through the API rotates the remember token (remember-me cookies die too).
        $this->user->forceFill(['status' => 'active', 'remember_token' => 'old-remember-token'])->save();
        $admin = $this->makeUser($this->group, 'group-owner', allHotels: true);
        $this->actAs($admin, $this->hotel);
        $this->patchJson("/api/v1/users/{$this->user->id}", ['status' => 'suspended'])->assertOk();
        $this->assertNotSame('old-remember-token', $this->user->fresh()->remember_token);
    }

    public function test_required_role_without_2fa_enrolment_gets_no_api_access(): void
    {
        TwoFactor::$enforceInTests = true;
        $this->app['auth']->forgetGuards();
        // Password grant: refused until enrolled (general-manager is a required role).
        $this->postJson('/api/v1/auth/token', ['email' => $this->user->email, 'password' => 'password-123'])
            ->assertStatus(403)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/mfa-enrolment-required')
            ->assertJsonMissingPath('access_token');
        // Any already-authenticated API call (laravel_token cookie or old bearer token) is blocked, except what the profile page needs.
        $this->actAs($this->user, $this->hotel);
        $this->getJson('/api/v1/hotels')->assertForbidden();
        $this->getJson('/api/v1/me')->assertOk();
        // A role outside the required list is unaffected.
        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel);
        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/hotels')->assertOk();
        // Once enrolled the manager is back in.
        $secret = app(TwoFactor::class)->begin($this->user);
        app(TwoFactor::class)->confirm($this->user, Totp::at($secret, time()));
        $this->actAs($this->user, $this->hotel);
        $this->getJson('/api/v1/hotels')->assertOk();
    }

    public function test_default_seeder_skips_demo_accounts_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertFalse(DB::table('users')->where('email', 'admin@opeshotelsoft.test')->exists());
        $this->assertTrue(DB::table('roles')->where('name', 'group-owner')->exists());
    }
}
