<?php

namespace Tests\Feature\Platform;

use App\Modules\Reports\Dashboard\DashboardComposer;
use Database\Seeders\DemoUsersSeeder;
use Tests\TestCase;

/** Role-fitted dashboard: each demo role gets only the widgets its permissions allow, in the order of its work. */
class DashboardRolesTest extends TestCase
{
    private function compose(string $role): array
    {
        $user = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
        $this->actAs($user, $this->hotel);

        return $this->getJson('/api/v1/reports/dashboard/compose')->assertOk()->json();
    }

    public function test_every_demo_role_gets_only_widgets_it_may_see(): void
    {
        $roles = collect(DemoUsersSeeder::USERS)->map(fn ($u) => $u[1])->unique();
        foreach ($roles as $role) {
            $c = $this->compose($role);
            $user = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
            foreach ($c['order'] as $id) {
                $perms = DashboardComposer::WIDGET_PERMISSIONS[$id];
                $this->assertTrue(collect($perms)->contains(fn ($p) => $user->can($p)), "$role must not see widget $id");
            }
            foreach (array_keys($c['data']) as $id) {
                $this->assertContains($id, $c['order'], "$role has data for $id but it is not in its layout");
            }
        }
    }

    public function test_role_layouts_match_the_work(): void
    {
        $this->assertSame(['housekeeping'], $this->compose('room-attendant')['order']);
        $this->assertSame(['work_orders'], $this->compose('maintenance-technician')['order']);
        $this->assertSame('pos', $this->compose('pos-cashier')['order'][0]);
        $this->assertSame(['stock'], $this->compose('storekeeper')['order']);
        $this->assertSame('rate_suggestions', $this->compose('revenue-manager')['order'][0]);
        $this->assertSame('finance', $this->compose('accountant')['order'][0]);
        $this->assertSame('arrivals', $this->compose('receptionist')['order'][0]);
        $this->assertSame('kpis', $this->compose('general-manager')['order'][0]);
        $this->assertSame('hr', $this->compose('hr-officer')['order'][0]);
    }

    public function test_owner_level_analytics_are_not_shown_to_everyone(): void
    {
        foreach (['room-attendant', 'maintenance-technician', 'storekeeper', 'pos-cashier', 'receptionist', 'hr-officer'] as $role) {
            $order = $this->compose($role)['order'];
            $this->assertNotContains('alerts', $order, $role);
            $this->assertNotContains('cash_by_method', $order, $role);
            $this->assertNotContains('revenue', $order, $role);
        }
        $this->assertContains('alerts', $this->compose('general-manager')['order']);
        $this->assertContains('alerts', $this->compose('hotel-owner')['order']);
    }

    public function test_hr_widget_hides_leave_approvals_from_read_only_roles(): void
    {
        $this->assertArrayNotHasKey('leave_pending', $this->compose('hotel-owner')['data']['hr'] ?? []);
        $this->assertArrayHasKey('leave_pending', $this->compose('hr-officer')['data']['hr']);
    }
}
