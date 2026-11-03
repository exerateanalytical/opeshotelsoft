<?php

namespace Tests\Feature\Brand;

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BrandTest extends TestCase
{
    private const H = ['Accept' => 'text/html'];

    public function test_brand_assets_exist_with_expected_sizes(): void
    {
        foreach (['emblem' => 512, 'emblem-128' => 128, 'emblem-64' => 64] as $f => $px) {
            $this->assertSame([$px, $px], array_slice(getimagesize(public_path("brand/$f.png")), 0, 2), $f);
        }
        $this->assertSame(1200, getimagesize(public_path('brand/logo-wordmark.png'))[0]);
        $this->assertSame(480, getimagesize(public_path('brand/logo-wordmark-small.png'))[0]);
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('brand/og-image.png')), 0, 2));
        $this->assertSame(180, getimagesize(public_path('apple-touch-icon.png'))[0]);
        $this->assertSame(192, getimagesize(public_path('icon-192.png'))[0]);
        $this->assertSame(512, getimagesize(public_path('icon-512.png'))[0]);
        $ico = file_get_contents(public_path('favicon.ico'));
        $this->assertSame(3, unpack('v', substr($ico, 4, 2))[1]);
    }

    public function test_favicon_and_manifest_are_served(): void
    {
        $this->assertFileExists(public_path('favicon.ico'));
        $m = json_decode(file_get_contents(public_path('site.webmanifest')), true);
        $this->assertSame('OPES Hôtels Soft', $m['name']);
        $this->assertSame('#0b1a33', $m['theme_color']);
        foreach ($m['icons'] as $i) {
            $this->assertFileExists(public_path(ltrim($i['src'], '/')));
        }
    }

    public function test_landing_and_login_carry_brand_meta_and_no_old_name(): void
    {
        $this->app['auth']->forgetGuards();
        foreach (['/', '/login'] as $url) {
            $html = $this->get($url, self::H)->assertOk()->getContent();
            $this->assertStringContainsString('rel="apple-touch-icon"', $html);
            $this->assertStringContainsString('rel="manifest"', $html);
            $this->assertStringContainsString('og:image', $html);
            $this->assertStringContainsString('twitter:image', $html);
            $this->assertStringContainsString('name="theme-color" content="#0b1a33"', $html);
            $this->assertStringNotContainsString('OPes Hotel Soft', $html);
        }
    }

    public function test_login_props_unchanged_and_demo_endpoint_works(): void
    {
        config(['app.demo_logins' => true]);
        $this->app['auth']->forgetGuards();
        $u = User::create(['name' => 'R', 'email' => 'reception@demo.opeshotelsoft.test', 'password' => 'x-123456', 'group_id' => $this->group->id]);

        $this->get('/login', self::H)->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Auth/Login')
            ->has('demoAccounts', fn ($a) => $a->etc()));
        $this->post('/demo-login/reception')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u, 'web');
    }

    public function test_brand_lang_files_have_identical_keys_and_name_is_current(): void
    {
        $fr = json_decode(file_get_contents(lang_path('fr/brand.json')), true);
        $en = json_decode(file_get_contents(lang_path('en/brand.json')), true);
        $this->assertEqualsCanonicalizing(array_keys($fr), array_keys($en));
        $this->assertStringNotContainsString('OPes Hotel', file_get_contents(lang_path('fr.json')).file_get_contents(lang_path('en.json')));
    }
}
