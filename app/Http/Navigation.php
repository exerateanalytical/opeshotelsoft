<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The staff sidebar, as one config array. Each entry: label (i18n key), path, icon (lucide name),
 * section (i18n key, null = top), and permission: any one of these grants the entry (null = all staff).
 * The page route behind each path must carry the matching can: guard.
 * Other workstreams: ask the lead to append entries here.
 *
 * Order (docs/SIDEBAR-ORDER.md): permissions decide WHAT a user sees (the security boundary, applied first);
 * ROLE_PROFILES only decide the ORDER of what is already visible, and can never add an entry.
 */
class Navigation
{
    /** Entries only shown to logins linked to an employee record (their data calls refuse everyone else). */
    private const EMPLOYEE_ONLY = ['/me', '/hr/workforce/clock', '/hr/workforce/requests'];

    public const ITEMS = [
        ['label' => 'nav.dashboard', 'path' => '/dashboard', 'icon' => 'LayoutDashboard', 'section' => null, 'permission' => null],
        ['label' => 'nav.tasks', 'path' => '/tasks', 'icon' => 'ClipboardCheck', 'section' => null, 'permission' => null],
        ['label' => 'nav.my_space', 'path' => '/me', 'icon' => 'UserCircle', 'section' => null, 'permission' => null],
        ['label' => 'nav.clock', 'path' => '/hr/workforce/clock', 'icon' => 'Clock', 'section' => null, 'permission' => null],
        ['label' => 'nav.my_requests', 'path' => '/hr/workforce/requests', 'icon' => 'Inbox', 'section' => null, 'permission' => null],

        ['label' => 'nav.owner', 'path' => '/owner', 'icon' => 'Gauge', 'section' => 'nav.section.owner', 'permission' => ['insights.view']],
        ['label' => 'nav.owner_group', 'path' => '/owner/group', 'icon' => 'Building', 'section' => 'nav.section.owner', 'permission' => ['insights.group']],
        ['label' => 'nav.insights_alerts', 'path' => '/insights/alerts', 'icon' => 'ShieldAlert', 'section' => 'nav.section.owner', 'permission' => ['insights.view']],
        ['label' => 'nav.insights_settings', 'path' => '/insights/settings', 'icon' => 'SlidersHorizontal', 'section' => 'nav.section.owner', 'permission' => ['insights.manage']],

        ['label' => 'nav.front_desk', 'path' => '/front-desk', 'icon' => 'DoorOpen', 'section' => 'nav.section.front_office', 'permission' => ['frontdesk.view']],
        ['label' => 'nav.arrivals', 'path' => '/reservations/arrivals', 'icon' => 'DoorOpen', 'section' => 'nav.section.front_office', 'permission' => ['experience.view']],
        ['label' => 'nav.in_house', 'path' => '/front-desk/in-house', 'icon' => 'BedDouble', 'section' => 'nav.section.front_office', 'permission' => ['frontdesk.view']],
        ['label' => 'nav.reservations', 'path' => '/reservations', 'icon' => 'CalendarDays', 'section' => 'nav.section.front_office', 'permission' => ['reservations.view']],
        ['label' => 'nav.tape_chart', 'path' => '/reservations/tape-chart', 'icon' => 'ChartGantt', 'section' => 'nav.section.front_office', 'permission' => ['reservations.view']],
        ['label' => 'nav.groups', 'path' => '/groups', 'icon' => 'UsersRound', 'section' => 'nav.section.front_office', 'permission' => ['reservations.view']],
        ['label' => 'nav.group_booking', 'path' => '/reservations/group-booking', 'icon' => 'UsersRound', 'section' => 'nav.section.front_office', 'permission' => ['experience.manage']],
        ['label' => 'nav.rooming_import', 'path' => '/reservations/rooming-import', 'icon' => 'ListOrdered', 'section' => 'nav.section.front_office', 'permission' => ['experience.manage']],
        ['label' => 'nav.waitlist', 'path' => '/reservations/waitlist', 'icon' => 'ListOrdered', 'section' => 'nav.section.front_office', 'permission' => ['reservations.view']],
        ['label' => 'nav.travel_agents', 'path' => '/reservations/travel-agents', 'icon' => 'Briefcase', 'section' => 'nav.section.front_office', 'permission' => ['reservations.view']],

        ['label' => 'nav.guests', 'path' => '/guests', 'icon' => 'Users', 'section' => 'nav.section.guests', 'permission' => ['guests.view']],
        ['label' => 'nav.service_requests', 'path' => '/service-requests', 'icon' => 'BellRing', 'section' => 'nav.section.guests', 'permission' => ['servicerequests.view']],
        ['label' => 'nav.messaging', 'path' => '/messaging', 'icon' => 'MessageCircle', 'section' => 'nav.section.guests', 'permission' => ['messaging.view']],
        ['label' => 'nav.loyalty', 'path' => '/loyalty', 'icon' => 'Sparkles', 'section' => 'nav.section.guests', 'permission' => ['loyalty.view']],
        ['label' => 'nav.loyalty_members', 'path' => '/loyalty/members', 'icon' => 'Users', 'section' => 'nav.section.guests', 'permission' => ['loyalty.view']],
        ['label' => 'nav.loyalty_promotions', 'path' => '/loyalty/promotions', 'icon' => 'Tag', 'section' => 'nav.section.guests', 'permission' => ['loyalty.view', 'loyalty.manage', 'pos.manage', 'frontdesk.manage']],
        ['label' => 'nav.loyalty_counter', 'path' => '/loyalty/counter', 'icon' => 'Award', 'section' => 'nav.section.guests', 'permission' => ['pos.manage', 'loyalty.manage']],
        ['label' => 'nav.privacy_requests', 'path' => '/guests/privacy-requests', 'icon' => 'ShieldCheck', 'section' => 'nav.section.guests', 'permission' => ['guests.manage']],

        ['label' => 'nav.housekeeping', 'path' => '/housekeeping', 'icon' => 'Layers', 'section' => 'nav.section.rooms', 'permission' => ['housekeeping.view']],
        ['label' => 'nav.maintenance', 'path' => '/maintenance', 'icon' => 'Wrench', 'section' => 'nav.section.rooms', 'permission' => ['maintenance.view']],

        ['label' => 'nav.revenue_suggestions', 'path' => '/revenue/suggestions', 'icon' => 'TrendingUp', 'section' => 'nav.section.revenue', 'permission' => ['revenue.view']],
        ['label' => 'nav.revenue_rules', 'path' => '/revenue/rules', 'icon' => 'SlidersHorizontal', 'section' => 'nav.section.revenue', 'permission' => ['revenue.view']],
        ['label' => 'nav.revenue_offers', 'path' => '/revenue/offers', 'icon' => 'Tag', 'section' => 'nav.section.revenue', 'permission' => ['revenue.view']],
        ['label' => 'nav.revenue_overbooking', 'path' => '/revenue/overbooking', 'icon' => 'ArrowUpCircle', 'section' => 'nav.section.revenue', 'permission' => ['revenue.view']],
        ['label' => 'nav.seasons', 'path' => '/reservations/seasons', 'icon' => 'Sun', 'section' => 'nav.section.revenue', 'permission' => ['rates.view']],
        ['label' => 'nav.channels', 'path' => '/channels', 'icon' => 'Network', 'section' => 'nav.section.revenue', 'permission' => ['channels.view']],
        ['label' => 'nav.channel_health', 'path' => '/reservations/channel-health', 'icon' => 'Network', 'section' => 'nav.section.revenue', 'permission' => ['experience.view']],
        ['label' => 'nav.experience_settings', 'path' => '/reservations/experience-settings', 'icon' => 'Sparkles', 'section' => 'nav.section.revenue', 'permission' => ['experience.view']],

        ['label' => 'nav.pos', 'path' => '/pos', 'icon' => 'ShoppingCart', 'section' => 'nav.section.billing', 'permission' => ['pos.view']],
        ['label' => 'nav.cashier', 'path' => '/billing/cashier', 'icon' => 'Wallet', 'section' => 'nav.section.billing', 'permission' => ['cashier.view']],
        ['label' => 'nav.folios', 'path' => '/billing/folios', 'icon' => 'Receipt', 'section' => 'nav.section.billing', 'permission' => ['folios.view']],
        ['label' => 'nav.invoices', 'path' => '/billing/invoices', 'icon' => 'FileText', 'section' => 'nav.section.billing', 'permission' => ['folios.view']],
        ['label' => 'nav.payments', 'path' => '/payments', 'icon' => 'Smartphone', 'section' => 'nav.section.billing', 'permission' => ['payments.view']],
        ['label' => 'nav.payment_links', 'path' => '/payments/links', 'icon' => 'QrCode', 'section' => 'nav.section.billing', 'permission' => ['payments.links']],
        ['label' => 'nav.payment_matching', 'path' => '/payments/inbound', 'icon' => 'GitMerge', 'section' => 'nav.section.billing', 'permission' => ['payments.reconcile']],
        ['label' => 'nav.salary_payouts', 'path' => '/payments/payouts', 'icon' => 'Banknote', 'section' => 'nav.section.billing', 'permission' => ['payments.payouts']],
        ['label' => 'nav.payment_gateways', 'path' => '/payments/gateways', 'icon' => 'Settings2', 'section' => 'nav.section.billing', 'permission' => ['payments.settings']],

        ['label' => 'nav.finance', 'path' => '/finance', 'icon' => 'CreditCard', 'section' => 'nav.section.finance', 'permission' => ['accounting.view']],
        ['label' => 'nav.payables_workflow', 'path' => '/finance/payables-workflow', 'icon' => 'ClipboardCheck', 'section' => 'nav.section.finance', 'permission' => ['payables.approve', 'payables.capture', 'accounting.tax', 'accounting.close']],
        ['label' => 'nav.budgets', 'path' => '/finance/budgets', 'icon' => 'PiggyBank', 'section' => 'nav.section.finance', 'permission' => ['accounting.view']],
        ['label' => 'nav.month_close', 'path' => '/finance/month-close', 'icon' => 'ClipboardCheck', 'section' => 'nav.section.finance', 'permission' => ['accounting.close']],
        ['label' => 'nav.accruals', 'path' => '/finance/accruals', 'icon' => 'CalendarClock', 'section' => 'nav.section.finance', 'permission' => ['accounting.view']],
        ['label' => 'nav.bank_feed', 'path' => '/finance/bank-feed', 'icon' => 'Banknote', 'section' => 'nav.section.finance', 'permission' => ['accounting.bank_feed']],
        ['label' => 'nav.tax_vat', 'path' => '/finance/tax', 'icon' => 'Receipt', 'section' => 'nav.section.finance', 'permission' => ['accounting.tax']],
        ['label' => 'nav.dunning', 'path' => '/finance/dunning', 'icon' => 'BellRing', 'section' => 'nav.section.finance', 'permission' => ['accounting.dunning']],
        ['label' => 'nav.cash_forecast', 'path' => '/finance/cash-forecast', 'icon' => 'TrendingUp', 'section' => 'nav.section.finance', 'permission' => ['accounting.forecast']],
        ['label' => 'nav.statements_pack', 'path' => '/finance/statements-pack', 'icon' => 'FileText', 'section' => 'nav.section.finance', 'permission' => ['accounting.statements']],
        ['label' => 'nav.payroll_filings', 'path' => '/finance/payroll-filings', 'icon' => 'FileSignature', 'section' => 'nav.section.finance', 'permission' => ['payroll.filings']],
        ['label' => 'nav.consolidation', 'path' => '/finance/consolidation', 'icon' => 'Building', 'section' => 'nav.section.finance', 'permission' => ['accounting.consolidate']],
        ['label' => 'nav.dimensions', 'path' => '/finance/dimensions', 'icon' => 'Layers', 'section' => 'nav.section.finance', 'permission' => ['accounting.view']],

        ['label' => 'nav.stock', 'path' => '/stock', 'icon' => 'Package', 'section' => 'nav.section.stock', 'permission' => ['stock.view']],
        ['label' => 'nav.stock_ops', 'path' => '/stock/rfq', 'icon' => 'ScanLine', 'section' => 'nav.section.stock', 'permission' => ['stockops.view']],
        ['label' => 'nav.stock_control', 'path' => '/stock/recipes', 'icon' => 'ChefHat', 'section' => 'nav.section.stock', 'permission' => ['stockcontrol.view']],
        ['label' => 'nav.stocktake', 'path' => '/stock/stocktake', 'icon' => 'ClipboardList', 'section' => 'nav.section.stock', 'permission' => ['stocktake.count']],
        ['label' => 'nav.stock_scan', 'path' => '/stock/scan', 'icon' => 'ScanLine', 'section' => 'nav.section.stock', 'permission' => ['stockops.scan']],
        ['label' => 'nav.stock_reorder', 'path' => '/stock/reorder', 'icon' => 'ArrowUpCircle', 'section' => 'nav.section.stock', 'permission' => ['stockcontrol.view']],
        ['label' => 'nav.stock_issues', 'path' => '/stock/issues', 'icon' => 'Inbox', 'section' => 'nav.section.stock', 'permission' => ['stockops.view']],
        ['label' => 'nav.minibar', 'path' => '/stock/minibar', 'icon' => 'Package', 'section' => 'nav.section.stock', 'permission' => ['stockops.minibar']],
        ['label' => 'nav.stock_variance', 'path' => '/stock/variance', 'icon' => 'ShieldAlert', 'section' => 'nav.section.stock', 'permission' => ['stockcontrol.view']],
        ['label' => 'nav.stock_valuation', 'path' => '/stock/valuation', 'icon' => 'Coins', 'section' => 'nav.section.stock', 'permission' => ['stockcontrol.valuation']],
        ['label' => 'nav.stock_batches', 'path' => '/stock/batches', 'icon' => 'Layers', 'section' => 'nav.section.stock', 'permission' => ['stockcontrol.view']],
        ['label' => 'nav.menu_engineering', 'path' => '/stock/menu-engineering', 'icon' => 'ChefHat', 'section' => 'nav.section.stock', 'permission' => ['stockops.view']],

        ['label' => 'nav.hr', 'path' => '/hr', 'icon' => 'User', 'section' => 'nav.section.hr', 'permission' => ['hr.view']],
        ['label' => 'nav.workforce', 'path' => '/hr/workforce', 'icon' => 'CalendarRange', 'section' => 'nav.section.hr', 'permission' => ['workforce.view']],
        ['label' => 'nav.time_attendance', 'path' => '/hr/workforce/time', 'icon' => 'Clock', 'section' => 'nav.section.hr', 'permission' => ['workforce.view']],
        ['label' => 'nav.leave', 'path' => '/hr/workforce/leave', 'icon' => 'Sun', 'section' => 'nav.section.hr', 'permission' => ['workforce.view']],
        ['label' => 'nav.workforce_cost', 'path' => '/hr/workforce/cost', 'icon' => 'Coins', 'section' => 'nav.section.hr', 'permission' => ['workforce.view']],
        ['label' => 'nav.people', 'path' => '/people', 'icon' => 'ClipboardList', 'section' => 'nav.section.hr', 'permission' => ['people.view', 'people.manage']],
        ['label' => 'nav.reviews', 'path' => '/people/reviews', 'icon' => 'ClipboardCheck', 'section' => 'nav.section.hr', 'permission' => ['people.view', 'people.manage']],
        ['label' => 'nav.feedback', 'path' => '/people/feedback', 'icon' => 'MessageCircle', 'section' => 'nav.section.hr', 'permission' => ['people.view', 'people.manage']],
        ['label' => 'nav.hr_tasks', 'path' => '/people/tasks', 'icon' => 'ClipboardList', 'section' => 'nav.section.hr', 'permission' => ['people.view', 'people.tasks', 'people.manage']],
        ['label' => 'nav.discipline', 'path' => '/people/discipline', 'icon' => 'ShieldAlert', 'section' => 'nav.section.hr', 'permission' => ['people.discipline']],
        ['label' => 'nav.exits', 'path' => '/people/exits', 'icon' => 'DoorOpen', 'section' => 'nav.section.hr', 'permission' => ['people.exit']],
        ['label' => 'nav.hr_lifecycle', 'path' => '/hr/lifecycle', 'icon' => 'FileSignature', 'section' => 'nav.section.hr', 'permission' => ['hr.view']],
        ['label' => 'nav.talent', 'path' => '/hr/talent', 'icon' => 'GraduationCap', 'section' => 'nav.section.hr', 'permission' => ['talent.view']],
        ['label' => 'nav.talent_skills', 'path' => '/hr/talent/skills', 'icon' => 'GraduationCap', 'section' => 'nav.section.hr', 'permission' => ['talent.view']],
        ['label' => 'nav.training', 'path' => '/hr/talent/training', 'icon' => 'GraduationCap', 'section' => 'nav.section.hr', 'permission' => ['talent.view']],
        ['label' => 'nav.succession_planning', 'path' => '/hr/talent/planning', 'icon' => 'GitFork', 'section' => 'nav.section.hr', 'permission' => ['talent.view']],
        ['label' => 'nav.compliance', 'path' => '/hr/talent/compliance', 'icon' => 'ShieldCheck', 'section' => 'nav.section.hr', 'permission' => ['talent.view']],
        ['label' => 'nav.org_chart', 'path' => '/hr/org-chart', 'icon' => 'GitFork', 'section' => 'nav.section.hr', 'permission' => ['hr.view']],

        ['label' => 'nav.reports', 'path' => '/reports', 'icon' => 'BarChart3', 'section' => 'nav.section.reports', 'permission' => ['reports.view']],
        ['label' => 'nav.scheduled_reports', 'path' => '/documents/scheduled-reports', 'icon' => 'CalendarClock', 'section' => 'nav.section.reports', 'permission' => ['reports.view']],
        ['label' => 'nav.issued_documents', 'path' => '/documents/issued', 'icon' => 'FileText', 'section' => 'nav.section.reports', 'permission' => ['documents.verify.log']],

        ['label' => 'nav.properties', 'path' => '/properties', 'icon' => 'Building2', 'section' => 'nav.section.settings', 'permission' => ['property.view']],
        ['label' => 'nav.users', 'path' => '/settings/users', 'icon' => 'ShieldCheck', 'section' => 'nav.section.settings', 'permission' => ['users.view']],
        ['label' => 'nav.hotels', 'path' => '/settings/hotels', 'icon' => 'Hotel', 'section' => 'nav.section.settings', 'permission' => ['hotels.view']],
        ['label' => 'nav.message_templates', 'path' => '/notifications/templates', 'icon' => 'Mail', 'section' => 'nav.section.settings', 'permission' => ['notifications.view']],
        ['label' => 'nav.message_outbox', 'path' => '/notifications/outbox', 'icon' => 'Inbox', 'section' => 'nav.section.settings', 'permission' => ['notifications.view']],
        ['label' => 'nav.sr_settings', 'path' => '/service-requests/settings', 'icon' => 'Settings2', 'section' => 'nav.section.settings', 'permission' => ['servicerequests.settings']],
        ['label' => 'nav.messaging_templates', 'path' => '/messaging/templates', 'icon' => 'Mail', 'section' => 'nav.section.settings', 'permission' => ['messaging.manage']],
        ['label' => 'nav.currencies', 'path' => '/admin/currencies', 'icon' => 'Coins', 'section' => 'nav.section.settings', 'permission' => ['currencies.view']],
        ['label' => 'nav.audit', 'path' => '/admin/audit', 'icon' => 'History', 'section' => 'nav.section.settings', 'permission' => ['audit.view']],
        ['label' => 'nav.onboarding', 'path' => '/onboarding', 'icon' => 'Rocket', 'section' => 'nav.section.settings', 'permission' => ['onboarding.manage']],
        ['label' => 'nav.letterhead', 'path' => '/onboarding/branding', 'icon' => 'Stamp', 'section' => 'nav.section.settings', 'permission' => ['branding.manage']],
        ['label' => 'nav.onboarding_import', 'path' => '/onboarding/import', 'icon' => 'Inbox', 'section' => 'nav.section.settings', 'permission' => ['onboarding.manage']],
        ['label' => 'nav.onboarding_templates', 'path' => '/onboarding/templates', 'icon' => 'Stamp', 'section' => 'nav.section.settings', 'permission' => ['branding.manage']],
        ['label' => 'nav.fastconfigure', 'path' => '/properties/fast-configure', 'icon' => 'Sparkles', 'section' => 'nav.section.settings', 'permission' => ['property.fast_configure']],
        ['label' => 'nav.edge', 'path' => '/edge', 'icon' => 'ServerCog', 'section' => 'nav.section.settings', 'permission' => ['edge.manage']],
        ['label' => 'nav.developer', 'path' => '/developer', 'icon' => 'Code2', 'section' => 'nav.section.settings', 'permission' => ['integrations.view']],
    ];

    /** Section order for users without a profile (custom roles). */
    public const SECTIONS = ['owner', 'front_office', 'guests', 'rooms', 'revenue', 'billing', 'finance', 'stock', 'hr', 'reports', 'settings'];

    /**
     * Order of need per role. The list order is also the multi-role priority: a user holding several roles gets the
     * profile of the first listed role they hold (broadest scope first). Custom roles fall back to ITEMS order.
     *  - primary: the pinned top group, in order (what the person opens hundreds of times a shift). Dashboard and
     *    Tasks are always in this group: listed where the role needs them, appended if not listed. Entries the user
     *    cannot see are skipped. The first three visible entries are the role's documented primary workspace.
     *  - sections: section order below the top group; sections not listed follow in SECTIONS order (setup last).
     */
    public const ROLE_PROFILES = [
        'group-owner' => [
            'primary' => ['/owner/group', '/owner', '/dashboard', '/insights/alerts', '/tasks'],
            'sections' => ['owner', 'revenue', 'reports', 'finance', 'billing', 'front_office', 'guests', 'rooms', 'stock', 'hr', 'settings'],
        ],
        'general-manager' => [
            'primary' => ['/owner', '/dashboard', '/insights/alerts', '/tasks', '/front-desk'],
            'sections' => ['owner', 'front_office', 'revenue', 'guests', 'rooms', 'billing', 'finance', 'reports', 'hr', 'stock', 'settings'],
        ],
        'hotel-owner' => [
            'primary' => ['/owner', '/dashboard', '/insights/alerts', '/reports', '/tasks'],
            'sections' => ['owner', 'revenue', 'reports', 'finance', 'billing', 'front_office', 'guests', 'rooms', 'stock', 'hr', 'settings'],
        ],
        'finance-director' => [
            'primary' => ['/finance', '/dashboard', '/owner', '/finance/payables-workflow', '/tasks'],
            'sections' => ['finance', 'billing', 'reports', 'owner', 'stock', 'hr', 'settings'],
        ],
        'auditor' => [
            'primary' => ['/admin/audit', '/reports', '/finance', '/dashboard', '/tasks'],
            'sections' => ['finance', 'billing', 'reports', 'owner', 'front_office', 'guests', 'rooms', 'revenue', 'stock', 'hr', 'settings'],
        ],
        'revenue-manager' => [
            'primary' => ['/revenue/suggestions', '/reservations/tape-chart', '/channels', '/dashboard', '/tasks'],
            'sections' => ['revenue', 'reports', 'front_office', 'guests', 'settings'],
        ],
        'front-office-manager' => [
            'primary' => ['/front-desk', '/reservations/arrivals', '/reservations', '/front-desk/in-house', '/dashboard', '/tasks'],
            'sections' => ['front_office', 'guests', 'billing', 'rooms', 'revenue', 'reports', 'hr', 'settings'],
        ],
        'accountant' => [
            'primary' => ['/finance', '/billing/folios', '/billing/invoices', '/billing/cashier', '/dashboard', '/tasks'],
            'sections' => ['finance', 'billing', 'reports', 'stock', 'settings'],
        ],
        'payroll-officer' => [
            'primary' => ['/hr', '/payments/payouts', '/hr/workforce', '/finance/payables-workflow', '/dashboard', '/tasks'],
            'sections' => ['hr', 'billing', 'finance'],
        ],
        'hr-officer' => [
            'primary' => ['/hr', '/hr/workforce', '/people', '/hr/lifecycle', '/dashboard', '/tasks'],
            'sections' => ['hr', 'stock'],
        ],
        'night-auditor' => [
            'primary' => ['/front-desk', '/billing/cashier', '/billing/folios', '/reports', '/dashboard', '/tasks'],
            'sections' => ['billing', 'front_office', 'reports', 'guests', 'revenue', 'settings'],
        ],
        'receptionist' => [
            'primary' => ['/reservations/arrivals', '/front-desk', '/reservations', '/front-desk/in-house', '/guests', '/billing/cashier', '/dashboard', '/tasks'],
            'sections' => ['front_office', 'billing', 'guests', 'rooms', 'revenue', 'settings'],
        ],
        'reservations-agent' => [
            'primary' => ['/reservations', '/reservations/tape-chart', '/groups', '/guests', '/dashboard', '/tasks'],
            'sections' => ['front_office', 'guests', 'revenue', 'billing', 'settings'],
        ],
        'fnb-manager' => [
            'primary' => ['/pos', '/stock', '/stock/recipes', '/reports', '/dashboard', '/tasks'],
            'sections' => ['stock', 'billing', 'guests', 'reports', 'hr'],
        ],
        'housekeeping-manager' => [
            'primary' => ['/housekeeping', '/maintenance', '/service-requests', '/tasks', '/dashboard'],
            'sections' => ['rooms', 'guests', 'stock', 'hr', 'settings'],
        ],
        'storekeeper' => [
            'primary' => ['/stock', '/stock/rfq', '/stock/recipes', '/tasks', '/dashboard'],
            'sections' => ['stock'],
        ],
        'pos-cashier' => [
            'primary' => ['/pos', '/payments/links', '/service-requests', '/loyalty/counter', '/tasks', '/dashboard'],
            'sections' => ['billing', 'guests'],
        ],
        'maintenance-technician' => [
            'primary' => ['/maintenance', '/tasks', '/service-requests', '/dashboard'],
            'sections' => ['rooms', 'guests', 'settings'],
        ],
        'room-attendant' => [
            'primary' => ['/housekeeping', '/tasks', '/service-requests', '/dashboard'],
            'sections' => ['rooms', 'guests'],
        ],
    ];

    /** Entries the user may see, ordered by the need of their (highest-priority) role. */
    public static function for(User $user): array
    {
        return self::order(self::visible($user), self::profileFor($user));
    }

    /** Permission filter: the security boundary. Order-independent. */
    public static function visible(User $user): array
    {
        $isEmployee = null;

        return array_values(array_filter(self::ITEMS, function ($item) use ($user, &$isEmployee) {
            if (in_array($item['path'], self::EMPLOYEE_ONLY, true)) {
                return $isEmployee ??= DB::table('employees')->where('user_id', $user->id)->exists();
            }
            if ($item['permission'] === null) {
                return true;
            }
            foreach ($item['permission'] as $p) {
                if ($user->can($p)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** The profile of the first ROLE_PROFILES role the user holds, or null (global order). */
    public static function profileFor(User $user): ?array
    {
        $roles = $user->getRoleNames()->all();
        foreach (self::ROLE_PROFILES as $role => $profile) {
            if (in_array($role, $roles, true)) {
                return $profile;
            }
        }

        return null;
    }

    /** Reorders visible entries only; never adds one. */
    public static function order(array $visible, ?array $profile): array
    {
        $byPath = [];
        foreach ($visible as $item) {
            $byPath[$item['path']] = $item;
        }

        $top = [];
        foreach (array_merge($profile['primary'] ?? [], ['/dashboard', '/tasks', '/me', '/hr/workforce/clock', '/hr/workforce/requests']) as $path) {
            if (isset($byPath[$path]) && ! isset($top[$path])) {
                $top[$path] = $byPath[$path];
                $top[$path]['section'] = null;
            }
        }

        $sectionOrder = array_values(array_unique(array_merge($profile['sections'] ?? [], self::SECTIONS)));
        $groups = [];
        foreach ($visible as $item) {
            if (! isset($top[$item['path']]) && $item['section'] !== null) {
                $groups[substr($item['section'], strlen('nav.section.'))][] = $item;
            }
        }

        // A section left with one entry joins a shared "More" group when there are at least two such sections.
        $singles = array_filter($groups, fn ($g) => count($g) === 1);
        $more = [];
        if (count($singles) >= 2) {
            foreach ($sectionOrder as $key) {
                if (isset($singles[$key])) {
                    $more[] = ['section' => 'nav.section.more'] + $singles[$key][0];
                    unset($groups[$key]);
                }
            }
        }

        $out = array_values($top);
        foreach ($sectionOrder as $key) {
            foreach ($groups[$key] ?? [] as $item) {
                $out[] = $item;
            }
        }

        return array_merge($out, $more);
    }
}
