import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import {
    LayoutDashboard, CalendarDays, DoorOpen, Sparkles, CreditCard,
    ShoppingCart, BarChart3, Building2, Users, Wrench,
    Hotel, LogOut, User, Layers, Package, Network, UsersRound,
    FileText, Wallet, Receipt, ShieldCheck, Code2, Coins, History, Circle,
    ChartGantt, ListOrdered, Briefcase, Sun, BedDouble, Smartphone, Settings2, Mail, Inbox, GitFork, CalendarClock,
} from 'lucide-react';
import type { PageProps } from '@/types';

/** One sidebar entry, filtered server-side by the user's permissions (app/Http/Navigation.php). */
export interface NavEntry {
    label: string;
    path: string;
    icon: string;
    section: string | null;
    permission: string[] | null;
}

/** Icon names usable in app/Http/Navigation.php. Import and add a lucide icon here to use a new one. */
const ICONS: Record<string, React.ElementType> = {
    LayoutDashboard, CalendarDays, DoorOpen, Sparkles, CreditCard, ShoppingCart, BarChart3, Building2, Users, Wrench,
    Hotel, User, Layers, Package, Network, UsersRound, FileText, Wallet, Receipt, ShieldCheck, Code2, Coins, History,
    ChartGantt, ListOrdered, Briefcase, Sun, BedDouble, Smartphone, Settings2, Mail, Inbox, GitFork, CalendarClock,
};

export default function Sidebar({ collapsed = false }: { collapsed?: boolean }) {
    const { url, props } = usePage<PageProps & { nav?: NavEntry[] }>();
    const { auth, property, network } = props;
    const t = useT();
    const isActive = (href: string) => url === href || url.startsWith(href + '/') || url.startsWith(href + '?');

    const sections: { title: string | null; items: NavEntry[] }[] = [];
    for (const item of props.nav ?? []) {
        const last = sections[sections.length - 1];
        if (last && last.title === item.section) last.items.push(item);
        else sections.push({ title: item.section, items: [item] });
    }

    return (
        <aside
            className={cn(
                'flex flex-col h-screen transition-all duration-200',
                'bg-[#0f2044] border-r border-[#1a3a6b]',
                collapsed ? 'w-16' : 'w-60',
            )}
        >
            {/* Logo / Network header */}
            <div className="flex items-center gap-3 px-4 py-4 border-b border-slate-800 min-h-[60px]">
                <div className="flex-shrink-0 w-8 h-8 rounded-lg bg-[#c9a84c] flex items-center justify-center">
                    <Hotel className="w-4 h-4 text-white" />
                </div>
                {!collapsed && (
                    <div className="flex-1 min-w-0">
                        <p className="text-xs font-bold text-white truncate leading-tight">
                            {network?.name ?? t('shell.network')}
                        </p>
                        <p className="text-xs text-slate-400 truncate mt-0.5">
                            {property?.name ?? t('shell.select_property')}
                        </p>
                    </div>
                )}
            </div>

            {/* Navigation */}
            <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-0.5">
                {sections.map((section, si) => (
                    <div key={section.title ?? si} className={cn(si > 0 && 'pt-3')}>
                        {section.title && !collapsed && (
                            <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-slate-500">{t(section.title)}</p>
                        )}
                        {section.items.map((item) => {
                            const Icon = ICONS[item.icon] ?? Circle;
                            const label = t(item.label);
                            return (
                                <Link key={item.path} href={item.path} title={collapsed ? label : undefined} className={cn('nav-item', isActive(item.path) && 'active')}>
                                    <Icon className="w-4 h-4 flex-shrink-0" />
                                    {!collapsed && <span className="flex-1">{label}</span>}
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </nav>

            {/* User footer */}
            <div className="border-t border-[#1a3a6b] p-3">
                <div className={cn('flex items-center gap-3', collapsed && 'justify-center')}>
                    <div className="w-8 h-8 rounded-full bg-[#c9a84c]/20 border border-[#c9a84c]/40 flex items-center justify-center text-[#c9a84c] text-xs font-bold flex-shrink-0">
                        {auth?.user?.name?.slice(0, 2).toUpperCase() ?? 'U'}
                    </div>
                    {!collapsed && (
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-white truncate">{auth?.user?.name}</p>
                            <p className="text-xs text-slate-400 truncate">{auth?.user?.roles?.[0] ?? t('shell.staff')}</p>
                        </div>
                    )}
                    {!collapsed && (
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            aria-label={t('shell.logout')}
                            className="text-slate-500 hover:text-red-400 transition-colors"
                        >
                            <LogOut className="w-3.5 h-3.5" />
                        </Link>
                    )}
                </div>
            </div>
        </aside>
    );
}
