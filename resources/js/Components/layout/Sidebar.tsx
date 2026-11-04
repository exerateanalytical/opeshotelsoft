import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import BrandLogo from '@/Components/ui/BrandLogo';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { activePath, groupNav, navIcon, type NavEntry } from './navModel';

export type { NavEntry } from './navModel';

/**
 * Desktop and tablet navigation. Fixed on the left; `collapsed` shows an icon rail
 * (the tablet layout forces it). Hidden on phones, where BottomNav takes over.
 */
export default function Sidebar({ collapsed = false }: { collapsed?: boolean }) {
    const { url, props } = usePage<PageProps & { nav?: NavEntry[] }>();
    const { auth, property, network } = props;
    const t = useT();
    const nav = props.nav ?? [];
    const current = activePath(url, nav);
    const sections = groupNav(nav);

    return (
        <aside
            data-surface="dark"
            className={cn(
                'hidden md:flex flex-col h-dvh flex-shrink-0 transition-[width] duration-200',
                'bg-[var(--c-chrome)] border-r border-[var(--c-chrome-raised)] text-[var(--c-on-chrome)]',
                collapsed ? 'w-[72px]' : 'w-60',
            )}
        >
            <div className="flex items-center gap-3 px-4 py-4 border-b border-[var(--c-chrome-raised)] min-h-[60px]">
                <BrandLogo variant="emblem" size={32} />
                {!collapsed && (
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-bold text-[var(--c-accent)] truncate leading-tight">OPES Hôtels Soft</p>
                        <p className="text-xs font-semibold text-white truncate leading-tight mt-0.5">
                            {network?.name ?? t('shell.network')}
                        </p>
                        <p className="text-xs text-[var(--c-on-chrome-muted)] truncate mt-0.5">
                            {property?.name ?? t('shell.select_property')}
                        </p>
                    </div>
                )}
            </div>

            <nav aria-label={t('ui.nav.main')} className="flex-1 scroll-pane py-3 px-2 space-y-0.5">
                {sections.map((section, si) => (
                    <div key={section.title ?? si} className={cn(si > 0 && 'pt-3')}>
                        {section.title && !collapsed && (
                            <p className="px-3 pb-1 text-xs font-semibold text-[var(--c-on-chrome-muted)]">{t(section.title)}</p>
                        )}
                        {section.items.map((item) => {
                            const Icon = navIcon(item.icon);
                            const label = t(item.label);
                            const active = item.path === current;
                            return (
                                <Link key={item.path} href={item.path} title={collapsed ? label : undefined} aria-label={collapsed ? label : undefined}
                                    aria-current={active ? 'page' : undefined} className={cn('nav-item', active && 'active', collapsed && 'justify-center px-0')}>
                                    <Icon className="w-5 h-5" aria-hidden="true" />
                                    {!collapsed && <span className="flex-1">{label}</span>}
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </nav>

            <div className="border-t border-[var(--c-chrome-raised)] p-3">
                <div className={cn('flex items-center gap-3', collapsed && 'justify-center')}>
                    <div className="w-8 h-8 rounded-full bg-[var(--c-chrome-raised)] border border-[var(--c-accent)] flex items-center justify-center text-white text-xs font-bold flex-shrink-0" aria-hidden="true">
                        {auth?.user?.name?.slice(0, 2).toUpperCase() ?? 'U'}
                    </div>
                    {!collapsed && (
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-white truncate">{auth?.user?.name}</p>
                            <p className="text-xs text-[var(--c-on-chrome-muted)] truncate">{auth?.user?.roles?.[0] ?? t('shell.staff')}</p>
                        </div>
                    )}
                    {!collapsed && (
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            aria-label={t('shell.logout')}
                            className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-[var(--c-on-chrome-muted)] hover:bg-[var(--c-chrome-raised)] hover:text-white"
                        >
                            <LogOut className="w-4 h-4" aria-hidden="true" />
                        </Link>
                    )}
                </div>
            </div>
        </aside>
    );
}
