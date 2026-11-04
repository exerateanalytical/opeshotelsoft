<?php

namespace App\Http;

use App\Models\User;

/**
 * The staff sidebar, as one config array. Each entry: label (i18n key), path, icon (lucide name),
 * section (i18n key, null = top), and permission: any one of these grants the entry (null = all staff).
 * The page route behind each path must carry the matching can: guard.
 * Other workstreams: ask the lead to append entries here.
 */
class Navigation
{
    public const ITEMS = [
        ['label' => 'nav.dashboard', 'path' => '/dashboard', 'icon' => 'LayoutDashboard', 'section' => null, 'permission' => null],
        ['label' => 'nav.tasks', 'path' => '/tasks', 'icon' => 'ClipboardCheck', 'section' => null, 'permission' => null],
        ['label' => 'nav.my_space', 'path' => '/me', 'icon' => 'UserCircle', 'section' => null, 'permission' => null],

        ['label' => 'nav.owner', 'path' => '/owner', 'icon' => 'Gauge', 'section' => 'nav.section.owner', 'permission' => ['insights.view']],
        ['label' => 'nav.owner_group', 'path' => '/owner/group', 'icon' => 'Building', 'section' => 'nav.section.owner', 'permission' => ['insights.group']],
        ['label' => 'nav.insights_alerts', 'path' => '/insights/alerts', 'icon' => 'ShieldAlert', 'section' => 'nav.section.owner', 'permission' => ['insights.view']],
        ['label' => 'nav.insights_settings', 'path' => '/insights/settings', 'icon' => 'SlidersHorizontal', 'section' => 'nav.section.owner', 'permission' => ['insights.manage']],


        ['label' => 'nav.reservations', 'path' => '/reservations', 'icon' => 'CalendarDays', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.groups', 'path' => '/groups', 'icon' => 'UsersRound', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.tape_chart', 'path' => '/reservations/tape-chart', 'icon' => 'ChartGantt', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.waitlist', 'path' => '/reservations/waitlist', 'icon' => 'ListOrdered', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.travel_agents', 'path' => '/reservations/travel-agents', 'icon' => 'Briefcase', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.arrivals', 'path' => '/reservations/arrivals', 'icon' => 'DoorOpen', 'section' => 'nav.section.operations', 'permission' => ['experience.view']],
        ['label' => 'nav.group_booking', 'path' => '/reservations/group-booking', 'icon' => 'UsersRound', 'section' => 'nav.section.operations', 'permission' => ['experience.manage']],
        ['label' => 'nav.rooming_import', 'path' => '/reservations/rooming-import', 'icon' => 'ListOrdered', 'section' => 'nav.section.operations', 'permission' => ['experience.manage']],
        ['label' => 'nav.channel_health', 'path' => '/reservations/channel-health', 'icon' => 'Network', 'section' => 'nav.section.operations', 'permission' => ['experience.view']],
        ['label' => 'nav.experience_settings', 'path' => '/reservations/experience-settings', 'icon' => 'Sparkles', 'section' => 'nav.section.operations', 'permission' => ['experience.view']],
        ['label' => 'nav.seasons', 'path' => '/reservations/seasons', 'icon' => 'Sun', 'section' => 'nav.section.operations', 'permission' => ['rates.view']],
        ['label' => 'nav.front_desk', 'path' => '/front-desk', 'icon' => 'DoorOpen', 'section' => 'nav.section.operations', 'permission' => ['frontdesk.view']],
        ['label' => 'nav.in_house', 'path' => '/front-desk/in-house', 'icon' => 'BedDouble', 'section' => 'nav.section.operations', 'permission' => ['frontdesk.view']],
        ['label' => 'nav.housekeeping', 'path' => '/housekeeping', 'icon' => 'Layers', 'section' => 'nav.section.operations', 'permission' => ['housekeeping.view']],
        ['label' => 'nav.maintenance', 'path' => '/maintenance', 'icon' => 'Wrench', 'section' => 'nav.section.operations', 'permission' => ['maintenance.view']],
        ['label' => 'nav.guests', 'path' => '/guests', 'icon' => 'Users', 'section' => 'nav.section.operations', 'permission' => ['guests.view']],
        ['label' => 'nav.privacy_requests', 'path' => '/guests/privacy-requests', 'icon' => 'ShieldCheck', 'section' => 'nav.section.operations', 'permission' => ['guests.manage']],
        ['label' => 'nav.loyalty', 'path' => '/loyalty', 'icon' => 'Sparkles', 'section' => 'nav.section.operations', 'permission' => ['loyalty.view']],
        ['label' => 'nav.channels', 'path' => '/channels', 'icon' => 'Network', 'section' => 'nav.section.operations', 'permission' => ['channels.view']],
        ['label' => 'nav.service_requests', 'path' => '/service-requests', 'icon' => 'BellRing', 'section' => 'nav.section.operations', 'permission' => ['servicerequests.view']],
        ['label' => 'nav.messaging', 'path' => '/messaging', 'icon' => 'MessageCircle', 'section' => 'nav.section.operations', 'permission' => ['messaging.view']],
        ['label' => 'nav.revenue_suggestions', 'path' => '/revenue/suggestions', 'icon' => 'TrendingUp', 'section' => 'nav.section.operations', 'permission' => ['revenue.view']],
        ['label' => 'nav.revenue_rules', 'path' => '/revenue/rules', 'icon' => 'SlidersHorizontal', 'section' => 'nav.section.operations', 'permission' => ['revenue.view']],
        ['label' => 'nav.revenue_offers', 'path' => '/revenue/offers', 'icon' => 'Tag', 'section' => 'nav.section.operations', 'permission' => ['revenue.view']],
        ['label' => 'nav.revenue_overbooking', 'path' => '/revenue/overbooking', 'icon' => 'ArrowUpCircle', 'section' => 'nav.section.operations', 'permission' => ['revenue.view']],


        ['label' => 'nav.folios', 'path' => '/billing/folios', 'icon' => 'Receipt', 'section' => 'nav.section.billing', 'permission' => ['folios.view']],
        ['label' => 'nav.invoices', 'path' => '/billing/invoices', 'icon' => 'FileText', 'section' => 'nav.section.billing', 'permission' => ['folios.view']],
        ['label' => 'nav.cashier', 'path' => '/billing/cashier', 'icon' => 'Wallet', 'section' => 'nav.section.billing', 'permission' => ['cashier.view']],
        ['label' => 'nav.pos', 'path' => '/pos', 'icon' => 'ShoppingCart', 'section' => 'nav.section.billing', 'permission' => ['pos.view']],
        ['label' => 'nav.payments', 'path' => '/payments', 'icon' => 'Smartphone', 'section' => 'nav.section.billing', 'permission' => ['payments.view']],
        ['label' => 'nav.payment_gateways', 'path' => '/payments/gateways', 'icon' => 'Settings2', 'section' => 'nav.section.billing', 'permission' => ['payments.settings']],
        ['label' => 'nav.payment_links', 'path' => '/payments/links', 'icon' => 'QrCode', 'section' => 'nav.section.billing', 'permission' => ['payments.links']],
        ['label' => 'nav.payment_matching', 'path' => '/payments/inbound', 'icon' => 'GitMerge', 'section' => 'nav.section.billing', 'permission' => ['payments.reconcile']],
        ['label' => 'nav.salary_payouts', 'path' => '/payments/payouts', 'icon' => 'Banknote', 'section' => 'nav.section.billing', 'permission' => ['payments.payouts']],


        ['label' => 'nav.finance', 'path' => '/finance', 'icon' => 'CreditCard', 'section' => 'nav.section.back_office', 'permission' => ['accounting.view']],
        ['label' => 'nav.budgets', 'path' => '/finance/budgets', 'icon' => 'PiggyBank', 'section' => 'nav.section.back_office', 'permission' => ['accounting.view']],
        ['label' => 'nav.payables_workflow', 'path' => '/finance/payables-workflow', 'icon' => 'ClipboardCheck', 'section' => 'nav.section.back_office', 'permission' => ['payables.approve', 'payables.capture', 'accounting.tax', 'accounting.close']],

        ['label' => 'nav.stock', 'path' => '/stock', 'icon' => 'Package', 'section' => 'nav.section.back_office', 'permission' => ['stock.view']],
        ['label' => 'nav.stock_control', 'path' => '/stock/recipes', 'icon' => 'ChefHat', 'section' => 'nav.section.back_office', 'permission' => ['stockcontrol.view']],
        ['label' => 'nav.stock_ops', 'path' => '/stock/rfq', 'icon' => 'ScanLine', 'section' => 'nav.section.back_office', 'permission' => ['stockops.view']],

        ['label' => 'nav.hr', 'path' => '/hr', 'icon' => 'User', 'section' => 'nav.section.back_office', 'permission' => ['hr.view']],
        ['label' => 'nav.org_chart', 'path' => '/hr/org-chart', 'icon' => 'GitFork', 'section' => 'nav.section.back_office', 'permission' => ['hr.view']],
        ['label' => 'nav.workforce', 'path' => '/hr/workforce', 'icon' => 'CalendarRange', 'section' => 'nav.section.back_office', 'permission' => ['workforce.view']],
        ['label' => 'nav.talent', 'path' => '/hr/talent', 'icon' => 'GraduationCap', 'section' => 'nav.section.back_office', 'permission' => ['talent.view']],
        ['label' => 'nav.hr_lifecycle', 'path' => '/hr/lifecycle', 'icon' => 'FileSignature', 'section' => 'nav.section.back_office', 'permission' => ['hr.view']],
        ['label' => 'nav.people', 'path' => '/people', 'icon' => 'ClipboardList', 'section' => 'nav.section.back_office', 'permission' => ['people.view', 'people.manage']],

        ['label' => 'nav.reports', 'path' => '/reports', 'icon' => 'BarChart3', 'section' => 'nav.section.back_office', 'permission' => ['reports.view']],
        ['label' => 'nav.scheduled_reports', 'path' => '/documents/scheduled-reports', 'icon' => 'CalendarClock', 'section' => 'nav.section.back_office', 'permission' => ['reports.view']],
        ['label' => 'nav.properties', 'path' => '/properties', 'icon' => 'Building2', 'section' => 'nav.section.back_office', 'permission' => ['property.view']],

        ['label' => 'nav.users', 'path' => '/settings/users', 'icon' => 'ShieldCheck', 'section' => 'nav.section.settings', 'permission' => ['users.view']],
        ['label' => 'nav.hotels', 'path' => '/settings/hotels', 'icon' => 'Hotel', 'section' => 'nav.section.settings', 'permission' => ['hotels.view']],
        ['label' => 'nav.currencies', 'path' => '/admin/currencies', 'icon' => 'Coins', 'section' => 'nav.section.settings', 'permission' => ['currencies.view']],
        ['label' => 'nav.audit', 'path' => '/admin/audit', 'icon' => 'History', 'section' => 'nav.section.settings', 'permission' => ['audit.view']],
        ['label' => 'nav.message_templates', 'path' => '/notifications/templates', 'icon' => 'Mail', 'section' => 'nav.section.settings', 'permission' => ['notifications.view']],
        ['label' => 'nav.message_outbox', 'path' => '/notifications/outbox', 'icon' => 'Inbox', 'section' => 'nav.section.settings', 'permission' => ['notifications.view']],
        ['label' => 'nav.onboarding', 'path' => '/onboarding', 'icon' => 'Rocket', 'section' => 'nav.section.settings', 'permission' => ['onboarding.manage']],
        ['label' => 'nav.letterhead', 'path' => '/onboarding/branding', 'icon' => 'Stamp', 'section' => 'nav.section.settings', 'permission' => ['branding.manage']],
        ['label' => 'nav.fastconfigure', 'path' => '/properties/fast-configure', 'icon' => 'Sparkles', 'section' => 'nav.section.settings', 'permission' => ['property.fast_configure']],
        ['label' => 'nav.edge', 'path' => '/edge', 'icon' => 'ServerCog', 'section' => 'nav.section.settings', 'permission' => ['edge.manage']],


        ['label' => 'nav.developer', 'path' => '/developer', 'icon' => 'Code2', 'section' => 'nav.section.developer', 'permission' => ['integrations.view']],
    ];

    /** Entries the user may see, in config order. */
    public static function for(User $user): array
    {
        return array_values(array_filter(self::ITEMS, function ($item) use ($user) {
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
}
