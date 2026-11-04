import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import { HELP_EVENT, dialogOpen, isTypingTarget, openPalette, shortcutsEnabled, useShortcutsEnabled } from '@/lib/desktop';
import { useT } from '@/lib/i18n';
import { useIsDesktop } from '@/lib/useDevice';
import type { PageProps } from '@/types';
import CommandPalette from './CommandPalette';
import type { NavEntry } from './navModel';

/** g then a letter goes to a section. The first path that exists in the user's permitted menu wins. */
export const GO_KEYS: { key: string; label: string; paths: string[] }[] = [
    { key: 'd', label: 'nav.dashboard', paths: ['/dashboard'] },
    { key: 'r', label: 'nav.reservations', paths: ['/reservations'] },
    { key: 'f', label: 'nav.front_desk', paths: ['/front-desk'] },
    { key: 'h', label: 'nav.housekeeping', paths: ['/housekeeping'] },
    { key: 'b', label: 'nav.folios', paths: ['/billing/folios', '/billing/invoices'] },
    { key: 's', label: 'nav.stock', paths: ['/stock'] },
    { key: 'p', label: 'nav.pos', paths: ['/pos'] },
    { key: 'u', label: 'nav.guests', paths: ['/guests'] },
    { key: 'i', label: 'nav.invoices', paths: ['/billing/invoices'] },
];

const visible = (el: Element) => (el as HTMLElement).offsetParent !== null;

/**
 * Desktop-only shell layer (1024px and wider): keyboard shortcuts, the command palette and the help dialog.
 * Single-key shortcuts never fire while typing, while a dialog is open, or when the user switched them off.
 */
export default function DesktopShell() {
    const desktop = useIsDesktop();
    if (!desktop) return null;
    return <Shell />;
}

function Shell() {
    const t = useT();
    const { props } = usePage<PageProps & { nav?: NavEntry[] }>();
    const nav = props.nav ?? [];
    const [help, setHelp] = useState(false);
    const [chord, setChord] = useState(false);
    const [enabled, setEnabled] = useShortcutsEnabled();
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const navRef = useRef(nav);
    navRef.current = nav;

    useEffect(() => {
        const h = () => setHelp(true);
        window.addEventListener(HELP_EVENT, h);
        return () => window.removeEventListener(HELP_EVENT, h);
    }, []);

    useEffect(() => {
        const clearChord = () => { setChord(false); if (timer.current) clearTimeout(timer.current); timer.current = null; };
        let armed = false;
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); return; }
            if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return;
            if (!shortcutsEnabled() || isTypingTarget(e.target) || dialogOpen()) { armed = false; clearChord(); return; }

            if (armed) {
                armed = false; clearChord();
                const hit = GO_KEYS.find((g) => g.key === e.key.toLowerCase());
                const path = hit?.paths.find((p) => navRef.current.some((n) => n.path === p));
                if (path) { e.preventDefault(); router.visit(path); }
                return;
            }
            if (e.key === 'g') { armed = true; setChord(true); timer.current = setTimeout(() => { armed = false; setChord(false); }, 1500); return; }
            if (e.key === '?') { e.preventDefault(); setHelp(true); return; }
            if (e.key === '/') {
                const el = [...document.querySelectorAll<HTMLInputElement>('main input[type="search"], main [data-page-search]')].find(visible);
                e.preventDefault();
                if (el) el.focus(); else openPalette();
                return;
            }
            if (e.key === 'n') {
                const el = [...document.querySelectorAll<HTMLElement>('[data-primary-action]')].find(visible)
                    ?? [...document.querySelectorAll<HTMLElement>('[data-page-actions] .btn-primary')].find(visible);
                if (el) { e.preventDefault(); el.click(); }
            }
        };
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('keydown', onKey); clearChord(); };
    }, []);

    const mod = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform) ? 'Cmd' : 'Ctrl';
    const K = ({ children }: { children: React.ReactNode }) => <kbd className="n1-kbd">{children}</kbd>;
    const row = (keys: React.ReactNode, text: string) => (
        <div className="flex items-center justify-between gap-4 border-b border-slate-100 py-2 text-sm last:border-0"><span className="text-slate-700">{text}</span><span className="flex flex-shrink-0 items-center gap-1">{keys}</span></div>
    );
    const group = (title: string, children: React.ReactNode) => (
        <section aria-label={title}><h3 className="mb-1 mt-4 text-sm font-semibold text-slate-900 first:mt-0">{title}</h3>{children}</section>
    );

    return (
        <>
            <CommandPalette />
            {chord && <div role="status" className="n1-chord"><K>g</K> {t('desktop.help.chord')}</div>}
            <Modal open={help} onClose={() => setHelp(false)} title={t('desktop.help.title')} size="lg"
                footer={<button type="button" className="btn-secondary" onClick={() => setHelp(false)}>{t('ui.modal.close')}</button>}>
                <p className="mb-3 text-sm text-slate-600">{t('desktop.help.intro')}</p>
                {group(t('desktop.help.general'), <>
                    {row(<><K>{mod}</K><K>K</K></>, t('desktop.help.palette'))}
                    {row(<K>?</K>, t('desktop.help.help'))}
                    {row(<K>/</K>, t('desktop.help.search'))}
                    {row(<K>n</K>, t('desktop.help.new'))}
                    {row(<K>Esc</K>, t('desktop.help.esc'))}
                </>)}
                {group(t('desktop.help.goto'), GO_KEYS.map((g) => <div key={g.key}>{row(<><K>g</K><K>{g.key}</K></>, t(g.label))}</div>))}
                {group(t('desktop.help.tables'), <>
                    {row(<><K>↑</K><K>↓</K></>, t('desktop.help.rows'))}
                    {row(<><K>Home</K><K>End</K></>, t('desktop.help.rows_ends'))}
                    {row(<K>Enter</K>, t('desktop.help.open'))}
                    {row(<K>Space</K>, t('desktop.help.select'))}
                    {row(<><K>Shift</K><K>F10</K></>, t('desktop.help.menu'))}
                </>)}
                <label className="mt-4 flex min-h-11 items-center gap-2 text-sm text-slate-800">
                    <input type="checkbox" checked={!enabled} onChange={(e) => setEnabled(!e.target.checked)} /> {t('desktop.help.disable')}
                </label>
                <p className="text-sm text-slate-500">{t('desktop.help.disable_note', { mod })}</p>
            </Modal>
        </>
    );
}
