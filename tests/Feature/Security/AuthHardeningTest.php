<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Admin\Application\LoginGuard;
use App\Modules\Admin\Application\TwoFactor;
use App\Modules\Admin\Domain\Totp;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test personal', 'users');
        $this->app['auth']->forgetGuards();
    }

    private function enrol(User $user): string
    {
        $mfa = app(TwoFactor::class);
        $secret = $mfa->begin($user);
        $this->codes = $mfa->confirm($user, Totp::at($secret, time()));

        return $secret;
    }

    private function login(array $body)
    {
        return $this->postJson('/api/v1/auth/token', $body + ['email' => $this->user->email, 'password' => 'password-123']);
    }

    public function test_token_issued_without_2fa(): void
    {
        $this->login([])->assertOk()->assertJsonStructure(['access_token', 'expires_at']);
        $this->assertTrue(DB::table('activity_log')->where('log_name', 'security')->where('event', 'login')->exists());
    }

    public function test_token_refused_without_otp_and_challenge_accepts_totp(): void
    {
        $secret = $this->enrol($this->user);
        $res = $this->login([])->assertStatus(401)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/two-factor-required')
            ->assertJsonMissingPath('access_token');
        $challenge = $res->json('challenge_token');
        $this->assertNotEmpty($challenge);

        $this->postJson('/api/v1/auth/token/two-factor', ['challenge_token' => $challenge, 'code' => '000000'])->assertStatus(401);
        $this->postJson('/api/v1/auth/token/two-factor', ['challenge_token' => $challenge, 'code' => Totp::at($secret, time())])
            ->assertOk()->assertJsonStructure(['access_token']);
        // Single use.
        $this->postJson('/api/v1/auth/token/two-factor', ['challenge_token' => $challenge, 'code' => Totp::at($secret, time())])
            ->assertStatus(401)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/challenge-expired');
        $this->postJson('/api/v1/auth/token/two-factor', ['challenge_token' => 'forged', 'code' => '123456'])->assertStatus(401);
    }

    public function test_inline_code_and_recovery_code_accepted(): void
    {
        $secret = $this->enrol($this->user);
        $this->login(['code' => Totp::at($secret, time())])->assertOk()->assertJsonStructure(['access_token']);
        $this->login(['code' => $this->codes[0]])->assertOk();
        // Recovery code is consumed.
        $this->login(['code' => $this->codes[0]])->assertStatus(401);
        $this->assertSame(7, app(TwoFactor::class)->remainingCodes($this->user->fresh()));
    }

    public function test_legacy_login_endpoint_enforces_2fa_with_recovery_codes(): void
    {
        $this->enrol($this->user);
        $body = ['email' => $this->user->email, 'password' => 'password-123'];
        $this->postJson('/api/v1/auth/login', $body)->assertStatus(401)->assertJsonStructure(['challenge_token'])->assertJsonMissingPath('access_token');
        $this->postJson('/api/v1/auth/login', $body + ['mfa_code' => $this->codes[1]])->assertOk()->assertJsonStructure(['access_token', 'user']);
    }

    public function test_throttle_returns_429(): void
    {
        for ($i = 0; $i < LoginGuard::THROTTLE_ATTEMPTS; $i++) {
            $this->login(['password' => 'wrong'])->assertStatus(401);
        }
        $this->login([])->assertStatus(429);
    }

    public function test_route_limiter_returns_429(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/token/two-factor', ['challenge_token' => 'x', 'code' => '1']);
        }
        $this->postJson('/api/v1/auth/token/two-factor', ['challenge_token' => 'x', 'code' => '1'])->assertStatus(429);
    }

    public function test_account_lockout_is_logged_and_blocks_correct_password(): void
    {
        for ($i = 0; $i < LoginGuard::LOCK_THRESHOLD; $i++) {
            Cache::flush();
            $this->login(['password' => 'wrong'])->assertStatus(401);
        }
        $this->assertNotNull($this->user->fresh()->locked_until);
        $this->assertTrue(DB::table('activity_log')->where('log_name', 'security')->where('event', 'account-locked')->where('subject_id', $this->user->id)->exists());
        $this->assertSame(LoginGuard::LOCK_THRESHOLD, DB::table('activity_log')->where('event', 'login-failed')->where('subject_id', $this->user->id)->count());

        Cache::flush();
        $this->login([])->assertStatus(423);
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password-123'])->assertInvalid('email');
        $this->assertGuest('web');

        $this->travel(LoginGuard::LOCK_MINUTES + 1)->minutes();
        $this->login([])->assertOk();
        $this->assertSame(0, (int) $this->user->fresh()->failed_logins);
    }

    public function test_web_login_failures_are_audited(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'nope'])->assertInvalid('email');
        $this->assertSame(1, (int) $this->user->fresh()->failed_logins);
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password-123'])->assertRedirect();
        $this->assertAuthenticated('web');
        $this->assertSame(0, (int) $this->user->fresh()->failed_logins);
    }

    public function test_security_headers_present_and_hsts_only_in_production(): void
    {
        $res = $this->get('/login');
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->assertNotEmpty($res->headers->get('Permissions-Policy'));
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
        $this->getJson('/api/v1/me')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->app['env'] = 'production';
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_password_policy_and_session_hardening(): void
    {
        $this->assertTrue(validator(['p' => 'short1'], ['p' => Password::defaults()])->fails());
        $this->assertTrue(validator(['p' => 'onlyletterslong'], ['p' => Password::defaults()])->fails());
        $this->assertFalse(validator(['p' => 'letters-and-123'], ['p' => Password::defaults()])->fails());
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_demo_logins_still_work_for_2fa_users(): void
    {
        config(['app.demo_logins' => true]);
        $u = User::create(['name' => 'R', 'email' => 'reception@demo.opeshotelsoft.test', 'password' => 'x-1234567', 'group_id' => $this->group->id]);
        $this->assertArrayHasKey('reception', DemoUsersSeeder::USERS);
        $this->enrol($u);
        $this->post('/demo-login/reception')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u, 'web');
    }
}
