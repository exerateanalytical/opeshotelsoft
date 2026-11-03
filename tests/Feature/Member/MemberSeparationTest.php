<?php

namespace Tests\Feature\Member;

use App\Models\User;
use Database\Seeders\DemoMembersSeeder;

class MemberSeparationTest extends MemberTestCase
{
    public function test_a_staff_session_cannot_use_the_member_area(): void
    {
        $this->actingAs($this->user, 'web');
        $this->get('/account', ['Accept' => 'text/html'])->assertRedirect('/login?tab=member');
        $this->getJson('/account/card')->assertUnauthorized();
        $this->postJson('/account/chat', ['text' => 'hi'])->assertUnauthorized();
        $this->assertGuest('member');
    }

    public function test_a_member_session_cannot_open_staff_pages_or_apis(): void
    {
        [$a] = $this->member('sep@test.cm');
        $this->loginAs($a);
        $this->getJson('/api/v1/loyalty/members')->assertUnauthorized();
        $this->getJson('/api/v1/messaging/conversations')->assertUnauthorized();
        $this->getJson('/api/v1/reservations')->assertUnauthorized();
        $this->get('/dashboard', ['Accept' => 'text/html'])->assertRedirect('/login');
        $this->get('/messaging', ['Accept' => 'text/html'])->assertRedirect('/login');
        $this->get('/loyalty', ['Accept' => 'text/html'])->assertRedirect('/login');
        $this->assertGuest('web');
        $this->getJson('/account/card')->assertOk();
    }

    public function test_member_pages_never_receive_staff_identity(): void
    {
        [$a] = $this->member('share@test.cm');
        $this->loginAs($a);
        $props = $this->inertia('/account')->assertOk()->json('props');
        $this->assertNull($props['auth']['user']);
        $this->assertSame([], $props['nav']);
    }

    public function test_member_login_ends_a_staff_session_in_the_same_browser(): void
    {
        [$a] = $this->member('both@test.cm');
        $this->actingAs($this->user, 'web');
        $this->assertAuthenticated('web');
        $this->postJson('/account/login', ['identifier' => 'both@test.cm', 'password' => self::PASSWORD])->assertOk();
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($a, 'member');
    }

    public function test_a_member_account_is_not_a_staff_user_and_vice_versa(): void
    {
        [$a] = $this->member('mix@test.cm');
        $this->assertNull(User::where('email', 'mix@test.cm')->first());
        $this->asVisitor();
        $this->postJson('/login', ['email' => 'mix@test.cm', 'password' => self::PASSWORD])->assertStatus(422);
        $this->assertGuest('web');

        $this->postJson('/account/login', ['identifier' => $this->user->email, 'password' => 'password-123'])->assertStatus(422);
        $this->assertGuest('member');
    }

    public function test_login_page_still_shows_the_demo_staff_accounts_and_the_member_tab(): void
    {
        config(['app.demo_logins' => true]);
        $this->asVisitor();
        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Login')
            ->where('demoAccounts.0.login', 'admin')->where('demoAccounts.0.section', 'Management')
            ->has('demoAccounts', 1 + count(\Database\Seeders\DemoUsersSeeder::USERS))
            ->where('tab', 'staff'));
        $this->get('/login?tab=member')->assertInertia(fn ($p) => $p->where('tab', 'member'));
    }

    public function test_staff_login_still_works_unchanged(): void
    {
        $u = User::create(['name' => 'S', 'email' => 'staffer@test.cm', 'password' => 'staff-pass-123', 'group_id' => $this->group->id]);
        $this->asVisitor();
        $this->post('/login', ['email' => 'staffer@test.cm', 'password' => 'staff-pass-123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u, 'web');
        $this->assertGuest('member');
    }

    public function test_demo_members_seeder_creates_up_to_twenty_accounts(): void
    {
        $this->group->update(['slug' => 'opes-demo']);
        foreach (range(1, 22) as $i) {
            $g = \App\Modules\Guests\Domain\Guest::create(['first_name' => "M$i", 'last_name' => 'Demo', 'email' => "m$i@demo.cm"]);
            app(\App\Modules\Loyalty\Application\LoyaltyService::class)->enrol($g, 'digital');
        }
        $this->seed(DemoMembersSeeder::class);
        $this->seed(DemoMembersSeeder::class); // idempotent
        $this->assertSame(20, \App\Modules\MemberPortal\Domain\MemberAccount::count());
        $this->asVisitor();
        $this->postJson('/account/login', ['identifier' => 'm1@demo.cm', 'password' => DemoMembersSeeder::PASSWORD])->assertOk();
    }
}
