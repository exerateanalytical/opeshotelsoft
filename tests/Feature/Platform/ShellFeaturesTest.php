<?php

namespace Tests\Feature\Platform;

use App\Modules\Admin\Application\TwoFactor;
use App\Modules\Admin\Domain\Totp;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ShellFeaturesTest extends TestCase
{
    private const H = ['Accept' => 'text/html'];

    protected function tearDown(): void
    {
        TwoFactor::$enforceInTests = false;
        parent::tearDown();
    }

    public function test_totp_matches_rfc6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::at($secret, $time));
        }
        $this->assertTrue(Totp::verify($secret, '287082', 89));
        $this->assertFalse(Totp::verify($secret, '287082', 200));
    }

    public function test_locale_defaults_to_french_and_switch_is_saved_on_user(): void
    {
        $this->withHeaders(self::H)->actingAs($this->user, 'web');
        $this->get('/dashboard', self::H)->assertInertia(fn (AssertableInertia $p) => $p
            ->where('locale', 'fr')->where('translations', fn ($t) => $t['nav.dashboard'] === 'Tableau de bord')->etc());
        $this->post('/locale', ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', $this->user->fresh()->locale);
        $this->get('/dashboard', self::H)->assertInertia(fn (AssertableInertia $p) => $p
            ->where('locale', 'en')->where('translations', fn ($t) => $t['nav.dashboard'] === 'Dashboard')->etc());
        $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
    }

    public function test_fr_and_en_have_the_same_keys(): void
    {
        $fr = json_decode(file_get_contents(lang_path('fr.json')), true);
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $this->assertEqualsCanonicalizing(array_keys($fr), array_keys($en));

        foreach (glob(lang_path('fr/*.json')) as $file) {
            $name = basename($file);
            $frPart = json_decode(file_get_contents($file), true);
            $enPart = json_decode((string) @file_get_contents(lang_path("en/$name")), true);
            $this->assertIsArray($frPart, "lang/fr/$name is not valid JSON");
            $this->assertIsArray($enPart, "lang/en/$name is missing or invalid");
            $this->assertEqualsCanonicalizing(array_keys($frPart), array_keys($enPart), "lang/*/$name keys differ");
        }
    }

    public function test_enrolment_recovery_codes_and_login_challenge(): void
    {
        $this->withHeaders(self::H)->actingAs($this->user, 'web');
        $this->post('/profile/two-factor')->assertRedirect();
        $secret = $this->user->fresh()->mfa_secret;
        $this->assertNotEmpty($secret);
        $this->get('/profile', self::H)->assertInertia(fn (AssertableInertia $p) => $p->where('twoFactor.pending', true)->where('twoFactor.secret', $secret)->etc());

        $this->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $res = $this->post('/profile/two-factor/confirm', ['code' => Totp::at($secret, time())]);
        $codes = $res->getSession()->get('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotNull($this->user->fresh()->mfa_confirmed_at);

        // Login now requires the challenge.
        $this->post('/logout');
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password-123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest('web');
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated('web');
        // Recovery code is single use.
        $this->assertSame(7, app(TwoFactor::class)->remainingCodes($this->user->fresh()));
    }

    public function test_required_roles_are_forced_to_enrol_unless_demo(): void
    {
        TwoFactor::$enforceInTests = true;
        $accountant = $this->makeUser($this->group, 'accountant', hotels: [$this->hotel]);
        $this->actingAs($accountant, 'web');
        $this->get('/finance', self::H)->assertRedirect(route('profile'));
        $this->get('/profile', self::H)->assertOk();

        config(['app.demo_logins' => true]);
        $this->withSession(['demo_login' => true])->get('/finance', self::H)->assertOk();
        config(['app.demo_logins' => false]);
        $this->withSession(['demo_login' => true])->get('/finance', self::H)->assertRedirect(route('profile'));

        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actingAs($receptionist, 'web')->get('/front-desk', self::H)->assertOk();
    }

    public function test_required_role_cannot_disable(): void
    {
        $this->withHeaders(self::H)->actingAs($this->user, 'web');
        $mfa = app(TwoFactor::class);
        $mfa->confirm($this->user, Totp::at($mfa->begin($this->user), time()));
        $this->delete('/profile/two-factor', ['current_password' => 'password-123'])->assertSessionHas('error');
        $this->assertNotNull($this->user->fresh()->mfa_confirmed_at);
    }
}
