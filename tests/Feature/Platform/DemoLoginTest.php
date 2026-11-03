<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    public function test_disabled_demo_login_is_not_offered_and_refuses(): void
    {
        config(['app.demo_logins' => false]);
        $this->app['auth']->forgetGuards();
        User::create(['name' => 'R', 'email' => 'reception@demo.opeshotelsoft.test', 'password' => 'x-123456', 'group_id' => $this->group->id]);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('demoAccounts', []));
        $this->post('/demo-login/reception')->assertNotFound();
        $this->assertGuest('web');
    }

    public function test_enabled_demo_login_signs_in_only_known_demo_accounts(): void
    {
        config(['app.demo_logins' => true]);
        $this->app['auth']->forgetGuards();
        $u = User::create(['name' => 'R', 'email' => 'reception@demo.opeshotelsoft.test', 'password' => 'x-123456', 'group_id' => $this->group->id]);

        $this->post('/demo-login/someone-else')->assertNotFound();
        $this->post('/demo-login/reception')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u, 'web');
    }
}
