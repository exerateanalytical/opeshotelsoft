<?php

namespace Tests\Feature\Platform;

use Database\Seeders\DemoUsersSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * RBAC matrix: for every demo role, the exact sidebar it is shown and 200/403 on every staff page.
 */
class RbacMatrixTest extends TestCase
{
    private const ALL = ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/maintenance', '/guests', '/guests/privacy-requests', '/loyalty', '/channels', '/billing/folios', '/billing/invoices', '/billing/cashier', '/pos', '/payments', '/payments/gateways', '/finance', '/stock', '/hr', '/hr/org-chart', '/reports', '/documents/scheduled-reports', '/properties', '/settings/users', '/settings/hotels', '/admin/currencies', '/admin/audit', '/notifications/templates', '/notifications/outbox', '/developer', '/me', '/owner', '/owner/group', '/insights/alerts', '/insights/settings', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/payments/links', '/payments/inbound', '/payments/payouts', '/finance/budgets', '/finance/payables-workflow', '/stock/recipes', '/stock/rfq', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people', '/onboarding', '/onboarding/branding', '/edge', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/properties/fast-configure', '/reservations/experience-settings'];

    /** role => exact sidebar paths, in order. */
    private const SIDEBAR = [
        'group-owner' => ['/dashboard', '/tasks', '/owner', '/owner/group', '/insights/alerts', '/insights/settings', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/maintenance', '/guests', '/guests/privacy-requests', '/loyalty', '/channels', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/billing/folios', '/billing/invoices', '/billing/cashier', '/pos', '/payments', '/payments/gateways', '/payments/links', '/payments/inbound', '/payments/payouts', '/finance', '/finance/budgets', '/finance/payables-workflow', '/stock', '/stock/recipes', '/stock/rfq', '/hr', '/hr/org-chart', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people', '/reports', '/documents/scheduled-reports', '/properties', '/settings/users', '/settings/hotels', '/admin/currencies', '/admin/audit', '/notifications/templates', '/notifications/outbox', '/onboarding', '/onboarding/branding', '/properties/fast-configure', '/edge', '/developer'],
        'general-manager' => ['/dashboard', '/tasks', '/owner', '/owner/group', '/insights/alerts', '/insights/settings', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/maintenance', '/guests', '/guests/privacy-requests', '/loyalty', '/channels', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/billing/folios', '/billing/invoices', '/billing/cashier', '/pos', '/payments', '/payments/gateways', '/payments/links', '/payments/inbound', '/payments/payouts', '/finance', '/finance/budgets', '/finance/payables-workflow', '/stock', '/stock/recipes', '/stock/rfq', '/hr', '/hr/org-chart', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people', '/reports', '/documents/scheduled-reports', '/properties', '/settings/users', '/settings/hotels', '/admin/currencies', '/admin/audit', '/notifications/templates', '/notifications/outbox', '/onboarding', '/onboarding/branding', '/properties/fast-configure', '/edge', '/developer'],
        'finance-director' => ['/dashboard', '/tasks', '/owner', '/owner/group', '/insights/alerts', '/billing/folios', '/billing/invoices', '/billing/cashier', '/payments', '/payments/gateways', '/payments/links', '/payments/inbound', '/payments/payouts', '/finance', '/finance/budgets', '/finance/payables-workflow', '/stock', '/stock/recipes', '/hr/workforce', '/hr/talent', '/reports', '/documents/scheduled-reports', '/admin/currencies', '/admin/audit'],
        'accountant' => ['/dashboard', '/tasks', '/billing/folios', '/billing/invoices', '/billing/cashier', '/payments', '/payments/links', '/payments/inbound', '/finance', '/finance/budgets', '/finance/payables-workflow', '/stock', '/stock/recipes', '/reports', '/documents/scheduled-reports', '/admin/currencies'],
        'revenue-manager' => ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/channels', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/reports', '/documents/scheduled-reports', '/properties', '/notifications/templates', '/notifications/outbox'],
        'front-office-manager' => ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/guests', '/guests/privacy-requests', '/loyalty', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/billing/folios', '/billing/invoices', '/billing/cashier', '/payments', '/payments/links', '/payments/inbound', '/hr/workforce', '/hr/talent', '/reports', '/documents/scheduled-reports', '/properties', '/notifications/templates', '/notifications/outbox'],
        'receptionist' => ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/guests', '/guests/privacy-requests', '/loyalty', '/service-requests', '/messaging', '/billing/folios', '/billing/invoices', '/billing/cashier', '/payments', '/payments/links', '/properties', '/notifications/templates', '/notifications/outbox'],
        'night-auditor' => ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/channel-health', '/reservations/experience-settings', '/front-desk', '/front-desk/in-house', '/service-requests', '/billing/folios', '/billing/invoices', '/billing/cashier', '/payments', '/payments/links', '/reports', '/documents/scheduled-reports', '/properties'],
        'reservations-agent' => ['/dashboard', '/tasks', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/group-booking', '/reservations/rooming-import', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/guests', '/guests/privacy-requests', '/loyalty', '/messaging', '/payments', '/payments/links', '/properties', '/notifications/templates', '/notifications/outbox'],
        'housekeeping-manager' => ['/dashboard', '/tasks', '/housekeeping', '/maintenance', '/service-requests', '/stock', '/stock/rfq', '/hr/workforce', '/hr/talent', '/properties'],
        'room-attendant' => ['/dashboard', '/tasks', '/housekeeping', '/service-requests'],
        'maintenance-technician' => ['/dashboard', '/tasks', '/maintenance', '/service-requests', '/properties'],
        'pos-cashier' => ['/dashboard', '/tasks', '/guests', '/service-requests', '/pos', '/payments/links'],
        'fnb-manager' => ['/dashboard', '/tasks', '/service-requests', '/pos', '/payments/links', '/stock', '/stock/recipes', '/stock/rfq', '/hr/workforce', '/hr/talent', '/reports', '/documents/scheduled-reports'],
        'storekeeper' => ['/dashboard', '/tasks', '/stock', '/stock/recipes', '/stock/rfq'],
        'hr-officer' => ['/dashboard', '/tasks', '/stock/rfq', '/hr', '/hr/org-chart', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people'],
        'payroll-officer' => ['/dashboard', '/tasks', '/payments/payouts', '/finance/payables-workflow', '/hr', '/hr/org-chart', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people'],
        'auditor' => ['/dashboard', '/tasks', '/owner', '/insights/alerts', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/maintenance', '/guests', '/loyalty', '/channels', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/billing/folios', '/billing/invoices', '/billing/cashier', '/pos', '/payments', '/finance', '/finance/budgets', '/finance/payables-workflow', '/stock', '/stock/recipes', '/stock/rfq', '/hr', '/hr/org-chart', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people', '/reports', '/documents/scheduled-reports', '/properties', '/settings/users', '/settings/hotels', '/admin/currencies', '/admin/audit', '/notifications/templates', '/notifications/outbox', '/developer'],
        'hotel-owner' => ['/dashboard', '/tasks', '/owner', '/owner/group', '/insights/alerts', '/insights/settings', '/reservations', '/groups', '/reservations/tape-chart', '/reservations/waitlist', '/reservations/travel-agents', '/reservations/arrivals', '/reservations/channel-health', '/reservations/experience-settings', '/reservations/seasons', '/front-desk', '/front-desk/in-house', '/housekeeping', '/maintenance', '/guests', '/loyalty', '/channels', '/service-requests', '/messaging', '/revenue/suggestions', '/revenue/rules', '/revenue/offers', '/revenue/overbooking', '/billing/folios', '/billing/invoices', '/billing/cashier', '/pos', '/payments', '/finance', '/finance/budgets', '/stock', '/stock/recipes', '/stock/rfq', '/hr', '/hr/org-chart', '/hr/workforce', '/hr/talent', '/hr/lifecycle', '/people', '/reports', '/documents/scheduled-reports', '/properties', '/settings/users', '/settings/hotels', '/admin/currencies', '/admin/audit', '/notifications/templates', '/notifications/outbox', '/developer'],
    ];

    /** Extra page routes => the sidebar path whose access they follow (or a permission). */
    private const SUB_PAGES = [
        '/reservations/1' => '/reservations',
        '/groups/1' => '/groups',
        '/loyalty/members' => '/loyalty',
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

    public function test_manage_grants_imply_view(): void
    {
        $receptionist = $this->makeUser($this->group, 'receptionist');
        $this->assertTrue($receptionist->can('reservations.view'));
        $this->assertTrue($receptionist->can('frontdesk.view'));
    }
}
