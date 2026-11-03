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

        ['label' => 'nav.reservations', 'path' => '/reservations', 'icon' => 'CalendarDays', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.groups', 'path' => '/groups', 'icon' => 'UsersRound', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.tape_chart', 'path' => '/reservations/tape-chart', 'icon' => 'ChartGantt', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.waitlist', 'path' => '/reservations/waitlist', 'icon' => 'ListOrdered', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.travel_agents', 'path' => '/reservations/travel-agents', 'icon' => 'Briefcase', 'section' => 'nav.section.operations', 'permission' => ['reservations.view']],
        ['label' => 'nav.seasons', 'path' => '/reservations/seasons', 'icon' => 'Sun', 'section' => 'nav.section.operations', 'permission' => ['rates.view']],
        ['label' => 'nav.front_desk', 'path' => '/front-desk', 'icon' => 'DoorOpen', 'section' => 'nav.section.operations', 'permission' => ['frontdesk.view']],
        ['label' => 'nav.in_house', 'path' => '/front-desk/in-house', 'icon' => 'BedDouble', 'section' => 'nav.section.operations', 'permission' => ['frontdesk.view']],
        ['label' => 'nav.housekeeping', 'path' => '/housekeeping', 'icon' => 'Layers', 'section' => 'nav.section.operations', 'permission' => ['housekeeping.view']],
        ['label' => 'nav.maintenance', 'path' => '/maintenance', 'icon' => 'Wrench', 'section' => 'nav.section.operations', 'permission' => ['maintenance.view']],
        ['label' => 'nav.guests', 'path' => '/guests', 'icon' => 'Users', 'section' => 'nav.section.operations', 'permission' => ['guests.view']],
        ['label' => 'nav.privacy_requests', 'path' => '/guests/privacy-requests', 'icon' => 'ShieldCheck', 'section' => 'nav.section.operations', 'permission' => ['guests.manage']],
        ['label' => 'nav.loyalty', 'path' => '/loyalty', 'icon' => 'Sparkles', 'section' => 'nav.section.operations', 'permission' => ['loyalty.view']],
        ['label' => 'nav.channels', 'path' => '/channels', 'icon' => 'Network', 'section' => 'nav.section.operations', 'permission' => ['channels.view']],

        ['label' => 'nav.folios', 'path' => '/billing/folios', 'icon' => 'Receipt', 'section' => 'nav.section.billing', 'permission' => ['folios.view']],
        ['label' => 'nav.invoices', 'path' => '/billing/invoices', 'icon' => 'FileText', 'section' => 'nav.section.billing', 'permission' => ['folios.view']],
        ['label' => 'nav.cashier', 'path' => '/billing/cashier', 'icon' => 'Wallet', 'section' => 'nav.section.billing', 'permission' => ['cashier.view']],
        ['label' => 'nav.pos', 'path' => '/pos', 'icon' => 'ShoppingCart', 'section' => 'nav.section.billing', 'permission' => ['pos.view']],
        ['label' => 'nav.payments', 'path' => '/payments', 'icon' => 'Smartphone', 'section' => 'nav.section.billing', 'permission' => ['payments.view']],
        ['label' => 'nav.payment_gateways', 'path' => '/payments/gateways', 'icon' => 'Settings2', 'section' => 'nav.section.billing', 'permission' => ['payments.settings']],

        ['label' => 'nav.finance', 'path' => '/finance', 'icon' => 'CreditCard', 'section' => 'nav.section.back_office', 'permission' => ['accounting.view']],
        ['label' => 'nav.stock', 'path' => '/stock', 'icon' => 'Package', 'section' => 'nav.section.back_office', 'permission' => ['stock.view']],
        ['label' => 'nav.hr', 'path' => '/hr', 'icon' => 'User', 'section' => 'nav.section.back_office', 'permission' => ['hr.view']],
        ['label' => 'nav.org_chart', 'path' => '/hr/org-chart', 'icon' => 'GitFork', 'section' => 'nav.section.back_office', 'permission' => ['hr.view']],
        ['label' => 'nav.reports', 'path' => '/reports', 'icon' => 'BarChart3', 'section' => 'nav.section.back_office', 'permission' => ['reports.view']],
        ['label' => 'nav.scheduled_reports', 'path' => '/documents/scheduled-reports', 'icon' => 'CalendarClock', 'section' => 'nav.section.back_office', 'permission' => ['reports.view']],
        ['label' => 'nav.properties', 'path' => '/properties', 'icon' => 'Building2', 'section' => 'nav.section.back_office', 'permission' => ['property.view']],

        ['label' => 'nav.users', 'path' => '/settings/users', 'icon' => 'ShieldCheck', 'section' => 'nav.section.settings', 'permission' => ['users.view']],
        ['label' => 'nav.hotels', 'path' => '/settings/hotels', 'icon' => 'Hotel', 'section' => 'nav.section.settings', 'permission' => ['hotels.view']],
        ['label' => 'nav.currencies', 'path' => '/admin/currencies', 'icon' => 'Coins', 'section' => 'nav.section.settings', 'permission' => ['currencies.view']],
        ['label' => 'nav.audit', 'path' => '/admin/audit', 'icon' => 'History', 'section' => 'nav.section.settings', 'permission' => ['audit.view']],
        ['label' => 'nav.message_templates', 'path' => '/notifications/templates', 'icon' => 'Mail', 'section' => 'nav.section.settings', 'permission' => ['notifications.view']],
        ['label' => 'nav.message_outbox', 'path' => '/notifications/outbox', 'icon' => 'Inbox', 'section' => 'nav.section.settings', 'permission' => ['notifications.view']],

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
