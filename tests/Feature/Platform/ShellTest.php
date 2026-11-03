<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShellTest extends TestCase
{
    private const PAGES = [
        '/settings/users', '/settings/hotels', '/channels', '/billing/invoices', '/billing/invoices/1',
        '/billing/cashier', '/billing/folios', '/groups', '/groups/1', '/developer', '/profile',
    ];

    public function test_new_pages_render_for_admin(): void
    {
        $this->actingAs($this->user, 'web');
        foreach (self::PAGES as $url) {
            $this->get($url, ['Accept' => 'text/html'])->assertOk();
        }
    }

    public function test_new_pages_redirect_guests_to_login(): void
    {
        $this->app['auth']->forgetGuards();
        foreach (self::PAGES as $url) {
            $this->get($url, ['Accept' => 'text/html'])->assertRedirect(route('login'));
        }
    }

    public function test_unknown_url_renders_not_found_for_staff(): void
    {
        $this->actingAs($this->user, 'web');
        $this->get('/no-such-page', ['Accept' => 'text/html'])->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Errors/NotFound', false));
    }

    public function test_profile_name_update(): void
    {
        $this->actingAs($this->user, 'web');
        $this->post('/profile', ['name' => 'New Name'], ['Accept' => 'text/html'])->assertRedirect();
        $this->assertSame('New Name', $this->user->fresh()->name);
    }

    public function test_password_change_requires_current_password(): void
    {
        $this->actingAs($this->user, 'web');

        $this->post('/profile/password', [
            'current_password' => 'wrong-password', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
        ], ['Accept' => 'text/html'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password-123', $this->user->fresh()->password));

        $this->post('/profile/password', [
            'current_password' => 'password-123', 'password' => 'short', 'password_confirmation' => 'short',
        ], ['Accept' => 'text/html'])->assertSessionHasErrors('password');

        $this->post('/profile/password', [
            'current_password' => 'password-123', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
        ], ['Accept' => 'text/html'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-pass-1', $this->user->fresh()->password));
    }
}
