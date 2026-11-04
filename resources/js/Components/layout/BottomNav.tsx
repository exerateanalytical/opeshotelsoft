import { Link, usePage } from '@inertiajs/react';
import { LogOut, Menu } from 'lucide-react';
import { useState } from 'react';
import { Sheet } from '@/Components/ui/Modal';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { LanguageSwitch } from './Topbar';
import { activePath, groupNav, navIcon, pickTabs, type NavEntry } from './navModel';

interface HotelOption { id: number; code: string; name: string }

/** Phone navigation: four main destinations for the user's role plus a More tab that opens the full grouped menu. Hidden from 768px. */
export default function BottomNav() {
    const t = useT();
    const { url, props } = usePage<PageProps & { nav?: NavEntry[] }>();
    const nav = props.nav ?? [];
    const [open, setOpen] = useState(false);
    const current = activePath(url, nav);
    const tabs = pickTabs(nav, 4);
    const inTabs = tabs.some((x) => x.path === current);
    const hotels = (props.hotels as HotelOption[] | undefined) ?? [];
    const property = props.property as unknown as HotelOption | null;

    const pickHotel = (id: string) => {
        document.cookie = `hotel_id=${id}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
        window.location.reload();
    };

    return (
        <>
            <nav aria-label={t('ui.nav.primary')} data-surface="dark"
                className="md:hidden fixed inset-x-0 bottom-0 z-[30] bg-[var(--c-chrome)] border-t border-[var(--c-chrome-raised)] pb-[var(--safe-bottom)] pl-[var(--safe-left)] pr-[var(--safe-right)]">
                <ul className="flex h-[var(--bar-bottom-h)]">
                    {tabs.map((item) => {
                        const Icon = navIcon(item.icon);
                        const active = item.path === current;
                        return (
                            <li key={item.path} className="flex-auto min-w-0">
                                <Link href={item.path} aria-current={active ? 'page' : undefined}
                                    className={cn('tab-bar-item', active && 'is-active')}>
                                    <Icon className="h-6 w-6" aria-hidden="true" />
                                    <span className="truncate max-w-full">{t(item.label)}</span>
                                </Link>
                            </li>
                        );
                    })}
                    <li className="flex-none w-16">
                        <button type="button" onClick={() => setOpen(true)} aria-haspopup="dialog" className={cn('tab-bar-item w-full', !inTabs && !!current && 'is-active')}>
                            <Menu className="h-6 w-6" aria-hidden="true" />
                            <span>{t('ui.nav.more')}</span>
                        </button>
                    </li>
                </ul>
            </nav>

            <Sheet open={open} onClose={() => setOpen(false)} title={t('ui.nav.more_title')}>
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <LanguageSwitch />
                        {hotels.length > 1 && (
                            <select aria-label={t('shell.select_hotel')} className="select flex-1 min-w-0" value={property?.id ?? ''} onChange={(e) => pickHotel(e.target.value)}>
                                {hotels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
                            </select>
                        )}
                    </div>
                    {groupNav(nav).map((section, si) => (
                        <section key={section.title ?? si}>
                            {section.title && <h3 className="mb-1 px-1 text-sm font-semibold text-slate-600">{t(section.title)}</h3>}
                            <ul>
                                {section.items.map((item) => {
                                    const Icon = navIcon(item.icon);
                                    const active = item.path === current;
                                    return (
                                        <li key={item.path}>
                                            <Link href={item.path} onClick={() => setOpen(false)} aria-current={active ? 'page' : undefined}
                                                className={cn('nav-item nav-item-light', active && 'active')}>
                                                <Icon className="h-5 w-5" aria-hidden="true" />
                                                <span className="flex-1">{t(item.label)}</span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                    <Link href="/logout" method="post" as="button" className="nav-item nav-item-light w-full text-red-700">
                        <LogOut className="h-5 w-5" aria-hidden="true" />
                        <span>{t('shell.logout')}</span>
                    </Link>
                </div>
            </Sheet>
        </>
    );
}
