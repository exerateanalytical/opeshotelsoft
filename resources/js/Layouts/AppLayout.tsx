import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import BottomNav from '@/Components/layout/BottomNav';
import DesktopShell from '@/Components/layout/DesktopShell';
import Breadcrumbs, { type Crumb } from '@/Components/ui/Breadcrumbs';
import OfflineBanner from '@/Components/layout/OfflineBanner';
import Sidebar from '@/Components/layout/Sidebar';
import Topbar from '@/Components/layout/Topbar';
import { ToastRegion } from '@/Components/ui/Toast';
import VoiceNotifier from '@/Components/voice/VoiceNotifier';
import { useT } from '@/lib/i18n';
import { useIsTablet } from '@/lib/useMediaQuery';
import { cn } from '@/lib/utils';

interface Props {
    children: React.ReactNode;
    title?: string;
    subtitle?: string;
    actions?: React.ReactNode;
    noPadding?: boolean;
    /** Content width: narrow (~960px, prose and short forms), default (1440px), wide (1760px), full (operational screens). Defaults by route folder. */
    width?: PageWidth;
    /** Breadcrumb items; omit for the automatic trail from the menu, false to hide. */
    breadcrumbs?: Crumb[] | false;
}

export type PageWidth = 'narrow' | 'default' | 'wide' | 'full';

/** Operational screens use the whole width; lists with a detail panel get a wider cap; profile-like pages stay narrow. */
const FULL = ['/reservations/tape-chart', '/reservations/arrivals', '/pos', '/front-desk', '/housekeeping', '/maintenance', '/stock', '/billing/cashier', '/service-requests', '/edge', '/messaging'];
const WIDE = ['/reservations', '/guests', '/billing', '/payments', '/hr', '/finance', '/people', '/reports'];
const NARROW = ['/profile', '/onboarding', '/me/profile'];
const under = (p: string, list: string[]) => list.some((x) => p === x || p.startsWith(`${x}/`));

export function defaultWidth(url: string): PageWidth {
    const p = url.split('?')[0].split('#')[0];
    if (under(p, FULL)) return 'full';
    if (under(p, NARROW)) return 'narrow';
    if (/^\/reservations\/(create|group-booking|rooming-import)/.test(p) || /^\/(settings|admin|developer|properties)/.test(p)) return 'default';
    if (under(p, WIDE)) return 'wide';
    return 'default';
}

const KEY = 'opes.sidebar.collapsed';
const read = () => { try { return localStorage.getItem(KEY) === '1'; } catch { return false; } };

/**
 * Staff app shell.
 * Phone (<768px): app bar on top, bottom tab bar, content padded clear of both, safe-area aware.
 * Tablet (768-1023px): icon rail. Desktop (1024px+): collapsible sidebar. Only the main pane scrolls.
 */
export default function AppLayout({ children, title, subtitle, actions, noPadding, width, breadcrumbs }: Props) {
    const t = useT();
    const { url } = usePage();
    const [collapsed, setCollapsed] = useState(read);
    const tablet = useIsTablet();
    const first = useRef(true);
    const w = width ?? defaultWidth(url);
    // Full-width and edge-to-edge pages render directly in <main> (no wrapper, so h-full layouts keep working).
    const contained = !noPadding && w !== 'full';

    const toggle = () => setCollapsed((c) => {
        try { localStorage.setItem(KEY, c ? '0' : '1'); } catch { /* storage unavailable */ }
        return !c;
    });

    // After each client-side navigation move focus to the page heading so keyboard and screen reader users land on the new page.
    useEffect(() => {
        if (first.current) { first.current = false; return; }
        const target = document.querySelector<HTMLElement>('[data-page-title]') ?? document.getElementById('main');
        target?.focus({ preventScroll: true });
        document.getElementById('main')?.scrollTo({ top: 0 });
    }, [url]);

    return (
        <div className="flex h-dvh overflow-hidden bg-[var(--c-surface-page)]">
            <a href="#main" className="skip-link">{t('ui.skip')}</a>
            <Sidebar collapsed={collapsed || tablet} />

            <div className="flex-1 min-w-0 flex flex-col overflow-hidden">
                <Topbar title={title} subtitle={subtitle} collapsed={collapsed} onToggle={toggle} actions={actions} />

                <OfflineBanner />
                <main id="main" tabIndex={-1}
                    className={cn(
                        'flex-1 scroll-pane focus:outline-none',
                        !noPadding && 'p-4 md:p-6',
                        'pb-[calc(var(--bar-bottom-h)+var(--safe-bottom)+72px)] md:pb-20',
                    )}>
                    {contained ? (
                        <div className={cn('n1-page', `n1-${w}`)}>
                            {breadcrumbs !== false && <Breadcrumbs items={breadcrumbs} className="mb-3 hidden md:block" />}
                            {children}
                        </div>
                    ) : (
                        <>
                            {!noPadding && breadcrumbs !== false && <Breadcrumbs items={breadcrumbs} className="mb-3 hidden md:block" />}
                            {children}
                        </>
                    )}
                </main>
            </div>
            <BottomNav />
            <DesktopShell />
            <VoiceNotifier />
            <ToastRegion />
        </div>
    );
}
