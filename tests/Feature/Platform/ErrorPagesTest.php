<?php

namespace Tests\Feature\Platform;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * UI directive 27: errors are understandable and never leak exception text. Web errors render Inertia pages,
 * API errors stay RFC 9457 problem+json with a request id.
 */
class ErrorPagesTest extends TestCase
{
    private const SECRET = 'SQLSTATE[08006] password=hunter2 in /var/www/app/Secret.php';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Route::middleware('web')->group(function () {
            Route::get('/_e1/403', fn () => abort(403, self::SECRET));
            Route::get('/_e1/419', fn () => throw new TokenMismatchException(self::SECRET));
            Route::get('/_e1/429', fn () => abort(429, self::SECRET, ['Retry-After' => 42]));
            Route::get('/_e1/500', fn () => throw new \RuntimeException(self::SECRET));
            Route::get('/_e1/503', fn () => abort(503, self::SECRET));
        });
        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('_e1/500', fn () => throw new \RuntimeException(self::SECRET));
            Route::get('_e1/403', fn () => abort(403, self::SECRET));
            Route::get('_e1/409', fn () => abort(409, 'The room was just booked by someone else.'));
            Route::get('_e1/404', fn () => abort(404, self::SECRET));
        });
    }

    public function test_web_errors_render_inertia_pages_without_leaking(): void
    {
        $this->actingAs($this->user, 'web');
        foreach ([403 => 'Errors/Show', 419 => 'Errors/Show', 429 => 'Errors/Show', 500 => 'Errors/Show', 503 => 'Errors/Show'] as $status => $component) {
            $res = $this->get("/_e1/$status", ['Accept' => 'text/html']);
            $res->assertStatus($status)->assertInertia(fn ($page) => $page->component($component, false)->where('status', $status)->has('requestId'));
            $this->assertStringNotContainsString('hunter2', $res->getContent());
            $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
            $this->assertStringNotContainsString('Secret.php', $res->getContent());
            $this->assertNotEmpty($res->headers->get('X-Request-Id'));
        }
    }

    public function test_not_found_renders_the_not_found_page(): void
    {
        $this->actingAs($this->user, 'web');
        $this->get('/no-such-page-e1', ['Accept' => 'text/html'])->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Errors/NotFound', false));
    }

    public function test_inertia_requests_get_an_inertia_error_page_too(): void
    {
        $this->actingAs($this->user, 'web');
        $version = $this->get('/profile', ['Accept' => 'text/html'])->original->getData()['page']['version'] ?? '';
        $res = $this->get('/_e1/500', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']);
        $res->assertStatus(500);
        $this->assertSame('true', $res->headers->get('X-Inertia'));
        $this->assertStringNotContainsString('hunter2', $res->getContent());
    }

    public function test_throttle_page_carries_the_wait_hint(): void
    {
        $this->actingAs($this->user, 'web');
        $this->get('/_e1/429', ['Accept' => 'text/html'])->assertStatus(429)
            ->assertInertia(fn ($page) => $page->where('retryAfter', 42));
    }

    public function test_guests_see_translated_error_pages(): void
    {
        $this->app['auth']->forgetGuards();
        $res = $this->get('/_e1/500', ['Accept' => 'text/html']);
        $res->assertStatus(500)->assertInertia(fn ($page) => $page->component('Errors/Show', false)->has('translations')->where('auth.user', null));
    }

    public function test_api_errors_stay_problem_json_and_do_not_leak(): void
    {
        $this->actingAs($this->user, 'web');
        $h = ['Accept' => 'application/json'];
        $r500 = $this->get('/api/v1/_e1/500', $h);
        $r500->assertStatus(500)->assertHeader('Content-Type', 'application/problem+json')->assertJsonStructure(['type', 'title', 'status', 'detail', 'request_id']);
        $this->assertStringNotContainsString('hunter2', $r500->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $r500->getContent());

        $r403 = $this->get('/api/v1/_e1/403', $h);
        $r403->assertStatus(403)->assertHeader('Content-Type', 'application/problem+json');
        $this->assertStringNotContainsString('hunter2', $r403->getContent());

        $r404 = $this->get('/api/v1/_e1/404', $h);
        $r404->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
        $this->assertStringNotContainsString('hunter2', $r404->getContent());

        // A domain message written for people is kept.
        $this->get('/api/v1/_e1/409', $h)->assertStatus(409)->assertJsonPath('detail', 'The room was just booked by someone else.');
    }

    public function test_api_unauthenticated_is_problem_json(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reservations')->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_states_language_files_have_identical_keys(): void
    {
        $en = json_decode(file_get_contents(lang_path('en/states.json')), true);
        $fr = json_decode(file_get_contents(lang_path('fr/states.json')), true);
        $this->assertIsArray($en);
        $this->assertIsArray($fr);
        $this->assertSame([], array_diff(array_keys($en), array_keys($fr)), 'Keys missing in fr');
        $this->assertSame([], array_diff(array_keys($fr), array_keys($en)), 'Keys missing in en');
        foreach (array_keys($en) as $k) {
            $this->assertStringStartsWith('states.', $k);
            $this->assertNotSame('', trim($fr[$k]));
            preg_match_all('/:\w+/', $en[$k], $a);
            preg_match_all('/:\w+/', $fr[$k], $b);
            $this->assertEqualsCanonicalizing($a[0], $b[0], "Placeholders differ for $k");
        }
    }
}
