<?php

namespace Tests\Feature\Platform;

use App\Http\Navigation;
use Database\Seeders\DemoUsersSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * RBAC matrix: for every demo role, the exact sidebar it is shown and 200/403 on every staff page.
 */
class RbacMatrixTest extends TestCase
{
    private const ALL = ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/maintenance', '/guests', '/guests/privacy-requests', '/loyalty', '/channels', '/billing/folios', '/billing/invoices', '/billing/cashier', '/pos', '/payments', '/payments/gateways', '/finance', '/stock', '/hr', '/hr/org-chart', '/reports', '/documents/scheduled-reports', '/properties', '/settings/users', '/settings/hotels', '/admin/currencies', '/admin/audit', '/notifications/templates', '/notifications/outbox', '/developer', '/me', '/owner', '/owner/group', '/insights/alerts', '/insights/settings', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/payments/links', '/payments/inbound', '/payments/payouts', '/finance/budgets', '/finance/payables-workflow', '/stock/recipes', '/stock/rfq', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people', '/onboarding', '/onboarding/branding', '/edge', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/properties/fast-configure', '/reservations/experience-settings', '/loyalty/counter', '/loyalty/members', '/loyalty/promotions', '/finance/month-close', '/finance/accruals', '/finance/bank-feed', '/finance/tax', '/finance/dunning', '/finance/cash-forecast', '/finance/statements-pack', '/finance/payroll-filings', '/finance/consolidation', '/finance/dimensions', '/stock/stocktake', '/stock/scan', '/stock/reorder', '/stock/issues', '/stock/minibar', '/stock/variance', '/stock/valuation', '/stock/batches', '/stock/menu-engineering', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people/reviews', '/people/feedback', '/people/tasks', '/people/discipline', '/people/exits', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/messaging/templates', '/onboarding/import', '/onboarding/templates', '/documents/issued'];

    /** role => exact sidebar paths, in order of need (app/Http/Navigation.php ROLE_PROFILES, docs/SIDEBAR-ORDER.md). */
    private const SIDEBAR = [
        'group-owner' => ['/owner/group', '/owner', '/dashboard', '/insights/alerts', '/tasks', '/insights/settings', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reservations/seasons', '/channels', '/reservations/channel-health', '/reservations/experience-settings', '/reports', '/documents/scheduled-reports', '/documents/issued', '/finance', '/finance/payables-workflow', '/finance/budgets', '/finance/month-close', '/finance/accruals', '/finance/bank-feed', '/finance/tax', '/finance/dunning', '/finance/cash-forecast', '/finance/statements-pack', '/finance/payroll-filings', '/finance/consolidation', '/finance/dimensions', '/pos', '/billing/cashier', '/billing/folios', '/billing/invoices', '/payments', '/payments/links', '/payments/inbound', '/payments/payouts', '/payments/gateways', '/front-desk', '/reservations/arrivals', '/front-desk/in-house', '/reservations', '/reservations/tape-chart', '/groups', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/waitlist', '/reservations/travel-agents', '/guests', '/service-requests', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/loyalty/counter', '/guests/privacy-requests', '/housekeeping', '/maintenance', '/stock', '/stock/rfq', '/stock/recipes', '/stock/stocktake', '/stock/scan', '/stock/reorder', '/stock/issues', '/stock/minibar', '/stock/variance', '/stock/valuation', '/stock/batches', '/stock/menu-engineering', '/hr', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people', '/people/reviews', '/people/feedback', '/people/tasks', '/people/discipline', '/people/exits', '/hr/lifecycle', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/hr/org-chart', '/properties', '/settings/users', '/settings/hotels', '/notifications/templates', '/notifications/outbox', '/service-requests/settings', '/messaging/templates', '/admin/currencies', '/admin/audit', '/onboarding', '/onboarding/branding', '/onboarding/import', '/onboarding/templates', '/properties/fast-configure', '/edge', '/developer'],
        'hotel-owner' => ['/owner', '/dashboard', '/insights/alerts', '/reports', '/tasks', '/owner/group', '/insights/settings', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reservations/seasons', '/channels', '/reservations/channel-health', '/reservations/experience-settings', '/documents/scheduled-reports', '/documents/issued', '/finance', '/finance/budgets', '/finance/accruals', '/finance/cash-forecast', '/finance/statements-pack', '/finance/consolidation', '/finance/dimensions', '/pos', '/billing/cashier', '/billing/folios', '/billing/invoices', '/payments', '/front-desk', '/reservations/arrivals', '/front-desk/in-house', '/reservations', '/reservations/tape-chart', '/groups', '/reservations/waitlist', '/reservations/travel-agents', '/guests', '/service-requests', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/housekeeping', '/maintenance', '/stock', '/stock/rfq', '/stock/recipes', '/stock/reorder', '/stock/issues', '/stock/variance', '/stock/valuation', '/stock/batches', '/stock/menu-engineering', '/hr', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people', '/people/reviews', '/people/feedback', '/people/tasks', '/hr/lifecycle', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/hr/org-chart', '/properties', '/settings/users', '/settings/hotels', '/notifications/templates', '/notifications/outbox', '/admin/currencies', '/admin/audit', '/developer'],
        'general-manager' => ['/owner', '/dashboard', '/insights/alerts', '/tasks', '/front-desk', '/owner/group', '/insights/settings', '/reservations/arrivals', '/front-desk/in-house', '/reservations', '/reservations/tape-chart', '/groups', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/waitlist', '/reservations/travel-agents', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reservations/seasons', '/channels', '/reservations/channel-health', '/reservations/experience-settings', '/guests', '/service-requests', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/loyalty/counter', '/guests/privacy-requests', '/housekeeping', '/maintenance', '/pos', '/billing/cashier', '/billing/folios', '/billing/invoices', '/payments', '/payments/links', '/payments/inbound', '/payments/payouts', '/payments/gateways', '/finance', '/finance/payables-workflow', '/finance/budgets', '/finance/month-close', '/finance/accruals', '/finance/bank-feed', '/finance/tax', '/finance/dunning', '/finance/cash-forecast', '/finance/statements-pack', '/finance/payroll-filings', '/finance/consolidation', '/finance/dimensions', '/reports', '/documents/scheduled-reports', '/documents/issued', '/hr', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people', '/people/reviews', '/people/feedback', '/people/tasks', '/people/discipline', '/people/exits', '/hr/lifecycle', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/hr/org-chart', '/stock', '/stock/rfq', '/stock/recipes', '/stock/stocktake', '/stock/scan', '/stock/reorder', '/stock/issues', '/stock/minibar', '/stock/variance', '/stock/valuation', '/stock/batches', '/stock/menu-engineering', '/properties', '/settings/users', '/settings/hotels', '/notifications/templates', '/notifications/outbox', '/service-requests/settings', '/messaging/templates', '/admin/currencies', '/admin/audit', '/onboarding', '/onboarding/branding', '/onboarding/import', '/onboarding/templates', '/properties/fast-configure', '/edge', '/developer'],
        'revenue-manager' => ['/revenue/suggestions', '/reservations/tape-chart', '/channels', '/dashboard', '/tasks', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reservations/seasons', '/reservations/channel-health', '/reservations/experience-settings', '/reports', '/documents/scheduled-reports', '/reservations/arrivals', '/reservations', '/groups', '/reservations/waitlist', '/reservations/travel-agents', '/properties', '/notifications/templates', '/notifications/outbox'],
        'front-office-manager' => ['/front-desk', '/reservations/arrivals', '/reservations', '/front-desk/in-house', '/dashboard', '/tasks', '/reservations/tape-chart', '/groups', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/waitlist', '/reservations/travel-agents', '/guests', '/service-requests', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/loyalty/counter', '/guests/privacy-requests', '/billing/cashier', '/billing/folios', '/billing/invoices', '/payments', '/payments/links', '/payments/inbound', '/housekeeping', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reservations/seasons', '/reservations/channel-health', '/reservations/experience-settings', '/reports', '/documents/scheduled-reports', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people/tasks', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/properties', '/notifications/templates', '/notifications/outbox', '/service-requests/settings', '/messaging/templates'],
        'receptionist' => ['/reservations/arrivals', '/front-desk', '/reservations', '/front-desk/in-house', '/guests', '/billing/cashier', '/dashboard', '/tasks', '/reservations/tape-chart', '/groups', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/waitlist', '/reservations/travel-agents', '/billing/folios', '/billing/invoices', '/payments', '/payments/links', '/service-requests', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/guests/privacy-requests', '/housekeeping', '/reservations/seasons', '/reservations/channel-health', '/reservations/experience-settings', '/properties', '/notifications/templates', '/notifications/outbox'],
        'reservations-agent' => ['/reservations', '/reservations/tape-chart', '/groups', '/guests', '/dashboard', '/tasks', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/waitlist', '/reservations/travel-agents', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/guests/privacy-requests', '/reservations/seasons', '/reservations/channel-health', '/reservations/experience-settings', '/payments', '/payments/links', '/properties', '/notifications/templates', '/notifications/outbox'],
        'night-auditor' => ['/front-desk', '/billing/cashier', '/billing/folios', '/reports', '/dashboard', '/tasks', '/billing/invoices', '/payments', '/payments/links', '/reservations/arrivals', '/front-desk/in-house', '/reservations', '/reservations/tape-chart', '/groups', '/reservations/waitlist', '/reservations/travel-agents', '/service-requests', '/loyalty/promotions', '/reservations/channel-health', '/reservations/experience-settings', '/documents/scheduled-reports', '/properties'],
        'fnb-manager' => ['/pos', '/stock', '/stock/recipes', '/reports', '/dashboard', '/tasks', '/stock/rfq', '/stock/reorder', '/stock/issues', '/stock/variance', '/stock/batches', '/stock/menu-engineering', '/service-requests', '/loyalty/promotions', '/loyalty/counter', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people/tasks', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/payments/links', '/documents/scheduled-reports', '/service-requests/settings'],
        'pos-cashier' => ['/pos', '/payments/links', '/service-requests', '/loyalty/counter', '/tasks', '/dashboard', '/guests', '/loyalty/promotions'],
        'storekeeper' => ['/stock', '/stock/rfq', '/stock/recipes', '/tasks', '/dashboard', '/stock/stocktake', '/stock/scan', '/stock/reorder', '/stock/issues', '/stock/minibar', '/stock/variance', '/stock/batches', '/stock/menu-engineering'],
        'housekeeping-manager' => ['/housekeeping', '/maintenance', '/service-requests', '/tasks', '/dashboard', '/stock', '/stock/rfq', '/stock/stocktake', '/stock/issues', '/stock/minibar', '/stock/menu-engineering', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people/tasks', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/properties', '/service-requests/settings'],
        'room-attendant' => ['/housekeeping', '/tasks', '/service-requests', '/dashboard', '/stock/minibar'],
        'maintenance-technician' => ['/maintenance', '/tasks', '/service-requests', '/dashboard', '/properties'],
        'finance-director' => ['/finance', '/dashboard', '/owner', '/finance/payables-workflow', '/tasks', '/finance/budgets', '/finance/month-close', '/finance/accruals', '/finance/bank-feed', '/finance/tax', '/finance/dunning', '/finance/cash-forecast', '/finance/statements-pack', '/finance/payroll-filings', '/finance/consolidation', '/finance/dimensions', '/billing/cashier', '/billing/folios', '/billing/invoices', '/payments', '/payments/links', '/payments/inbound', '/payments/payouts', '/payments/gateways', '/reports', '/documents/scheduled-reports', '/owner/group', '/insights/alerts', '/stock', '/stock/recipes', '/stock/reorder', '/stock/variance', '/stock/valuation', '/stock/batches', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/admin/currencies', '/admin/audit'],
        'accountant' => ['/finance', '/billing/folios', '/billing/invoices', '/billing/cashier', '/dashboard', '/tasks', '/finance/payables-workflow', '/finance/budgets', '/finance/month-close', '/finance/accruals', '/finance/bank-feed', '/finance/tax', '/finance/dunning', '/finance/cash-forecast', '/finance/statements-pack', '/finance/payroll-filings', '/finance/dimensions', '/payments', '/payments/links', '/payments/inbound', '/reports', '/documents/scheduled-reports', '/stock', '/stock/recipes', '/stock/reorder', '/stock/variance', '/stock/valuation', '/stock/batches', '/admin/currencies'],
        'hr-officer' => ['/hr', '/hr/workforce', '/people', '/hr/lifecycle', '/dashboard', '/tasks', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people/reviews', '/people/feedback', '/people/tasks', '/people/discipline', '/people/exits', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/hr/org-chart', '/stock/rfq', '/stock/issues', '/stock/menu-engineering'],
        'payroll-officer' => ['/hr', '/payments/payouts', '/hr/workforce', '/finance/payables-workflow', '/dashboard', '/tasks', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people', '/people/reviews', '/people/feedback', '/people/tasks', '/people/exits', '/hr/lifecycle', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/hr/org-chart', '/finance/month-close', '/finance/payroll-filings'],
        'auditor' => ['/admin/audit', '/reports', '/finance', '/dashboard', '/tasks', '/finance/payables-workflow', '/finance/budgets', '/finance/accruals', '/finance/tax', '/finance/cash-forecast', '/finance/statements-pack', '/finance/dimensions', '/pos', '/billing/cashier', '/billing/folios', '/billing/invoices', '/payments', '/documents/scheduled-reports', '/documents/issued', '/owner', '/insights/alerts', '/front-desk', '/reservations/arrivals', '/front-desk/in-house', '/reservations', '/reservations/tape-chart', '/groups', '/reservations/waitlist', '/reservations/travel-agents', '/guests', '/service-requests', '/messaging', '/loyalty', '/loyalty/members', '/loyalty/promotions', '/housekeeping', '/maintenance', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reservations/seasons', '/channels', '/reservations/channel-health', '/reservations/experience-settings', '/stock', '/stock/rfq', '/stock/recipes', '/stock/reorder', '/stock/issues', '/stock/variance', '/stock/valuation', '/stock/batches', '/stock/menu-engineering', '/hr', '/hr/workforce', '/hr/workforce/time', '/hr/workforce/leave', '/hr/workforce/cost', '/people', '/people/reviews', '/people/feedback', '/people/tasks', '/hr/lifecycle', '/hr/talent', '/hr/talent/skills', '/hr/talent/training', '/hr/talent/planning', '/hr/talent/compliance', '/hr/org-chart', '/properties', '/settings/users', '/settings/hotels', '/notifications/templates', '/notifications/outbox', '/admin/currencies', '/developer'],
    ];

    /** Extra page routes => the sidebar path whose access they follow (or a permission). */
    private const SUB_PAGES = [
        '/reservations/1' => '/reservations',
        '/groups/1' => '/groups',
        '/service-requests/settings' => 'servicerequests.view',
        '/hr/workforce/requests' => '*',
        '/hr/workforce/clock' => '*',
        '/billing/invoices/1' => '/billing/invoices',
        '/reservations/create' => 'reservations.manage',
        '/profile' => '*',
    ];

    public function test_every_demo_role_is_covered(): void
    {
        $demoRoles = collect(DemoUsersSeeder::USERS)->map(fn ($u) => $u[1])->unique()->sort()->values()->all();
        $this->assertSame($demoRoles, collect(array_keys(self::SIDEBAR))->sort()->values()->all());
    }

    public function test_sidebar_and_page_access_per_role(): void
    {
        foreach (self::SIDEBAR as $role => $expected) {
            $user = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
            $this->actingAs($user, 'web');

            $this->get('/dashboard', ['Accept' => 'text/html'])->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('nav', fn ($nav) => collect($nav)->pluck('path')->all() === $expected
                        ?: $this->fail("$role sidebar: ".json_encode(collect($nav)->pluck('path'))))
                    ->etc());

            foreach (array_slice(self::ALL, 1) as $path) {
                $this->get($path, ['Accept' => 'text/html'])->assertStatus(in_array($path, $expected, true) || $path === '/me' ? 200 : 403);
            }
            foreach (self::SUB_PAGES as $path => $rule) {
                $allowed = match (true) {
                    $rule === '*' => true,
                    str_starts_with($rule, '/') => in_array($rule, $expected, true),
                    default => $user->can($rule),
                };
                $status = $this->get($path, ['Accept' => 'text/html'])->status();
                $this->assertSame($allowed ? 200 : 403, $status, "$role $path");
            }
        }
    }

    public function test_separation_of_duties(): void
    {
        $sidebar = fn (string $role) => self::SIDEBAR[$role];
        // Receptionist: no Finance, no HR.
        $this->assertEmpty(array_intersect(['/finance', '/hr', '/admin/currencies'], $sidebar('receptionist')));
        // Accountant: no POS terminal, no HR personal data.
        $this->assertEmpty(array_intersect(['/pos', '/hr'], $sidebar('accountant')));
        $accountant = $this->makeUser($this->group, 'accountant');
        $this->assertFalse($accountant->can('hr.view'));
        $this->assertFalse($accountant->can('pos.view'));
        // HR: no Finance.
        foreach (['hr-officer', 'payroll-officer'] as $role) {
            $this->assertNotContains('/finance', $sidebar($role));
        }
        // Owner: reports and read-only views, no write access.
        $owner = $this->makeUser($this->group, 'hotel-owner');
        $this->assertTrue($owner->can('reports.view'));
        $this->assertFalse($owner->can('reservations.manage'));
        $this->assertFalse($owner->can('accounting.manage'));
        $this->assertFalse($owner->can('currencies.manage'));
    }

    /** role => its documented primary workspace (docs/SIDEBAR-ORDER.md): the first three sidebar entries and the first phone tabs. */
    private const PRIMARY = [
        'group-owner' => ['/owner/group', '/owner', '/dashboard'],
        'hotel-owner' => ['/owner', '/dashboard', '/insights/alerts'],
        'general-manager' => ['/owner', '/dashboard', '/insights/alerts'],
        'revenue-manager' => ['/revenue/suggestions', '/reservations/tape-chart', '/channels'],
        'front-office-manager' => ['/front-desk', '/reservations/arrivals', '/reservations'],
        'receptionist' => ['/reservations/arrivals', '/front-desk', '/reservations'],
        'reservations-agent' => ['/reservations', '/reservations/tape-chart', '/groups'],
        'night-auditor' => ['/front-desk', '/billing/cashier', '/billing/folios'],
        'fnb-manager' => ['/pos', '/stock', '/stock/recipes'],
        'pos-cashier' => ['/pos', '/payments/links', '/service-requests'],
        'storekeeper' => ['/stock', '/stock/rfq', '/stock/recipes'],
        'housekeeping-manager' => ['/housekeeping', '/maintenance', '/service-requests'],
        'room-attendant' => ['/housekeeping', '/tasks', '/service-requests'],
        'maintenance-technician' => ['/maintenance', '/tasks', '/service-requests'],
        'finance-director' => ['/finance', '/dashboard', '/owner'],
        'accountant' => ['/finance', '/billing/folios', '/billing/invoices'],
        'hr-officer' => ['/hr', '/hr/workforce', '/people'],
        'payroll-officer' => ['/hr', '/payments/payouts', '/hr/workforce'],
        'auditor' => ['/admin/audit', '/reports', '/finance'],
    ];

    public function test_each_role_leads_with_its_primary_workspace(): void
    {
        $this->assertSame(array_keys(self::SIDEBAR), array_keys(array_intersect_key(self::SIDEBAR, self::PRIMARY)));
        foreach (self::SIDEBAR as $role => $paths) {
            $this->assertSame(self::PRIMARY[$role], array_slice($paths, 0, 3), $role);
            $user = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
            $this->assertSame(self::PRIMARY[$role], array_slice(array_column(Navigation::for($user), 'path'), 0, 3), $role);
        }
    }

    public function test_order_never_changes_visibility(): void
    {
        foreach (array_keys(self::SIDEBAR) as $role) {
            $user = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
            $visible = array_column(Navigation::visible($user), 'path');
            $ordered = array_column(Navigation::for($user), 'path');
            sort($visible);
            sort($ordered);
            $this->assertSame($visible, $ordered, $role);
        }
        // Ordering alone, even with every profile, can never add an entry that the permission filter removed.
        $only = [Navigation::ITEMS[0]];
        foreach (Navigation::ROLE_PROFILES as $role => $profile) {
            $this->assertSame(['/dashboard'], array_column(Navigation::order($only, $profile), 'path'), $role);
        }
    }

    public function test_multi_role_user_gets_the_highest_priority_profile(): void
    {
        $user = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $user->assignRole('front-office-manager');
        $this->assertSame(Navigation::ROLE_PROFILES['front-office-manager'], Navigation::profileFor($user->fresh()));
    }

    public function test_manage_grants_imply_view(): void
    {
        $receptionist = $this->makeUser($this->group, 'receptionist');
        $this->assertTrue($receptionist->can('reservations.view'));
        $this->assertTrue($receptionist->can('frontdesk.view'));
    }
}
