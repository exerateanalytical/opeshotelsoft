import { router, usePage } from '@inertiajs/react';
import { CalendarPlus, Keyboard, Receipt, Search, UserPlus, Zap } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { api } from '@/lib/api';
import { PALETTE_EVENT, fuzzyScore, openHelp, readJson, safeSet } from '@/lib/desktop';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { cn } from '@/lib/utils';
import { navIcon, type NavEntry } from './navModel';

const RECENT_KEY = 'opes.palette.recent';
const MAX_RECENT = 5;

interface Item {
    id: string;
    group: 'recent' | 'actions' | 'pages' | 'reservations' | 'guests';
    label: string;
    hint?: string;
    icon: React.ElementType;
    run: () => void;
}

interface Remote { id: number; label: string; hint?: string; href: string }
const rows = <T,>(r: unknown): T[] => (Array.isArray(r) ? r : ((r as { data?: T[] })?.data ?? []));

const GROUP_ORDER: Item['group'][] = ['recent', 'actions', 'pages', 'reservations', 'guests'];

/**
 * Command palette (Ctrl+K / Cmd+K, or the top bar button). Combobox pattern: the input keeps focus, the active option is
 * referenced with aria-activedescendant, results are grouped, a polite live region announces the count.
 * Sources: the permitted menu (props.nav), quick actions, recent items, and the same reservation and guest search
 * endpoints the top bar search uses.
 */
export default function CommandPalette() {
    const t = useT();
    const { props } = usePage<PageProps & { nav?: NavEntry[] }>();
    const nav = props.nav ?? [];
    const perms = props.auth?.user?.permissions ?? [];
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const [remote, setRemote] = useState<{ res: Remote[]; guests: Remote[]; loading: boolean; failed: boolean }>({ res: [], guests: [], loading: false, failed: false });
    const [recent, setRecent] = useState<string[]>([]);
    const input = useRef<HTMLInputElement>(null);
    const list = useRef<HTMLDivElement>(null);
    const prevFocus = useRef<HTMLElement | null>(null);
    const uid = useId();
    const listId = `${uid}-list`;

    useEffect(() => {
        const toggle = () => setOpen((o) => !o);
        window.addEventListener(PALETTE_EVENT, toggle);
        return () => window.removeEventListener(PALETTE_EVENT, toggle);
    }, []);

    useEffect(() => {
        if (!open) return;
        prevFocus.current = document.activeElement as HTMLElement | null;
        setQ('');
        setActive(0);
        setRecent(readJson<string[]>(RECENT_KEY, []));
        setTimeout(() => input.current?.focus(), 0);
        const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.stopPropagation(); setOpen(false); } };
        document.addEventListener('keydown', esc, true);
        return () => { document.removeEventListener('keydown', esc, true); prevFocus.current?.focus?.(); };
    }, [open]);

    // Remote reservation and guest search (same endpoints as the top bar search).
    useEffect(() => {
        const term = q.trim();
        if (!open || term.length < 2) { setRemote({ res: [], guests: [], loading: false, failed: false }); return; }
        let cancelled = false;
        setRemote((r) => ({ ...r, loading: true }));
        const timer = setTimeout(async () => {
            const enc = encodeURIComponent(term);
            const [r, g] = await Promise.allSettled([api(`reservations?q=${enc}&per_page=5`), api(`guests?q=${enc}&per_page=5`)]);
            if (cancelled) return;
            const res = r.status === 'fulfilled' ? rows<{ id: number; number: string; arrival?: string; guest?: { first_name?: string; last_name?: string } | null }>(r.value).slice(0, 5)
                .map((x) => ({ id: x.id, label: x.number, hint: [x.guest?.first_name, x.guest?.last_name].filter(Boolean).join(' '), href: `/reservations/${x.id}` })) : [];
            const guests = g.status === 'fulfilled' ? rows<{ id: number; first_name?: string; last_name?: string; email?: string | null; phone?: string | null }>(g.value).slice(0, 5)
                .map((x) => ({ id: x.id, label: [x.first_name, x.last_name].filter(Boolean).join(' '), hint: x.email ?? x.phone ?? '', href: `/guests/${x.id}` })) : [];
            setRemote({ res, guests, loading: false, failed: r.status === 'rejected' && g.status === 'rejected' });
        }, 250);
        return () => { cancelled = true; clearTimeout(timer); };
    }, [q, open]);

    const close = () => setOpen(false);
    const go = (href: string) => {
        const entry = nav.find((n) => n.path === href);
        if (entry) safeSet(RECENT_KEY, JSON.stringify([entry.path, ...readJson<string[]>(RECENT_KEY, []).filter((p) => p !== entry.path)].slice(0, MAX_RECENT)));
        close();
        router.visit(href);
    };

    const items = useMemo<Item[]>(() => {
        const out: Item[] = [];
        const pageItems = nav.map<Item>((n) => ({ id: `p:${n.path}`, group: 'pages', label: t(n.label), hint: n.section ? t(n.section) : undefined, icon: navIcon(n.icon), run: () => go(n.path) }));
        const can = (p: string) => perms.includes(p);
        const actions: Item[] = [];
        if (can('reservations.manage')) {
            actions.push({ id: 'a:res', group: 'actions', label: t('desktop.palette.new_reservation'), icon: CalendarPlus, run: () => go('/reservations/create') });
            actions.push({ id: 'a:walkin', group: 'actions', label: t('desktop.palette.new_walkin'), icon: Zap, run: () => go('/reservations/create?walk_in=1') });
        }
        if (can('guests.manage')) actions.push({ id: 'a:guest', group: 'actions', label: t('desktop.palette.new_guest'), icon: UserPlus, run: () => go('/guests?new=1') });
        if (can('folios.manage')) actions.push({ id: 'a:folio', group: 'actions', label: t('desktop.palette.open_folio'), icon: Receipt, run: () => go('/billing/folios?new=1') });
        actions.push({ id: 'a:help', group: 'actions', label: t('desktop.palette.shortcuts'), icon: Keyboard, run: () => { close(); setTimeout(openHelp, 0); } });

        const term = q.trim();
        if (!term) {
            const rec = recent.map((p) => pageItems.find((i) => i.id === `p:${p}`)).filter((x): x is Item => !!x).map((i) => ({ ...i, id: `r:${i.id}`, group: 'recent' as const }));
            return [...rec, ...actions, ...pageItems];
        }
        const scored = [...actions, ...pageItems]
            .map((i) => ({ i, s: Math.max(fuzzyScore(term, i.label), i.hint ? fuzzyScore(term, i.hint) - 50 : -1) }))
            .filter((x) => x.s >= 0)
            .sort((a, b) => b.s - a.s)
            .map((x) => x.i);
        const rem: Item[] = [
            ...remote.res.map<Item>((r) => ({ id: `r${r.id}`, group: 'reservations', label: r.label, hint: r.hint, icon: Search, run: () => go(r.href) })),
            ...remote.guests.map<Item>((g) => ({ id: `g${g.id}`, group: 'guests', label: g.label, hint: g.hint, icon: UserPlus, run: () => go(g.href) })),
        ];
        return [...scored, ...rem];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, nav, perms.join(','), recent, remote, t]);

    useEffect(() => { setActive(0); }, [q]);
    useEffect(() => {
        list.current?.querySelector<HTMLElement>(`[data-idx="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    if (!open || typeof document === 'undefined') return null;

    const grouped = GROUP_ORDER.map((g) => ({ g, entries: items.map((it, idx) => ({ it, idx })).filter((x) => x.it.group === g) })).filter((x) => x.entries.length);
    const total = items.length;
    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => (total ? (a + 1) % total : 0)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => (total ? (a - 1 + total) % total : 0)); }
        else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
        else if (e.key === 'End') { e.preventDefault(); setActive(Math.max(0, total - 1)); }
        else if (e.key === 'Enter') { e.preventDefault(); items[active]?.run(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
        else if (e.key === 'Tab') { e.preventDefault(); }
    };

    return createPortal(
        <div className="n1-overlay" onMouseDown={(e) => e.target === e.currentTarget && close()}>
            <div role="dialog" aria-modal="true" aria-label={t('desktop.palette.title')} className="n1-palette" onKeyDown={onKey}>
                <div className="n1-palette-input">
                    <Search className="h-4 w-4 flex-shrink-0 text-slate-600" aria-hidden="true" />
                    <input
                        ref={input}
                        role="combobox"
                        aria-expanded="true"
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={total ? `${uid}-opt-${active}` : undefined}
                        aria-label={t('desktop.palette.title')}
                        placeholder={t('desktop.palette.placeholder')}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        autoComplete="off"
                        spellCheck={false}
                    />
                    <kbd className="n1-kbd">Esc</kbd>
                </div>
                <div ref={list} id={listId} role="listbox" aria-label={t('desktop.palette.title')} className="n1-palette-list">
                    {grouped.map(({ g, entries }) => (
                        <div key={g} role="group" aria-labelledby={`${uid}-g-${g}`}>
                            <p id={`${uid}-g-${g}`} className="n1-palette-group">{t(`desktop.palette.group.${g}`)}</p>
                            {entries.map(({ it, idx }) => {
                                const Icon = it.icon;
                                return (
                                    <div key={it.id} id={`${uid}-opt-${idx}`} data-idx={idx} role="option" aria-selected={idx === active}
                                        className={cn('n1-palette-option', idx === active && 'is-active')}
                                        onMouseMove={() => idx !== active && setActive(idx)} onClick={() => it.run()}>
                                        <Icon className="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                                        <span className="min-w-0 flex-1 truncate">{it.label}</span>
                                        {it.hint && <span className="n1-palette-hint truncate">{it.hint}</span>}
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                    {!total && !remote.loading && <p className="px-4 py-6 text-center text-sm text-slate-500">{remote.failed ? t('shell.search.unavailable') : t('desktop.palette.none')}</p>}
                    {remote.loading && <p className="px-4 py-2 text-sm text-slate-500">{t('desktop.palette.searching')}</p>}
                </div>
                <p className="n1-palette-foot">
                    <span><kbd className="n1-kbd">↑</kbd> <kbd className="n1-kbd">↓</kbd> {t('desktop.palette.navigate')}</span>
                    <span><kbd className="n1-kbd">Enter</kbd> {t('desktop.palette.select')}</span>
                </p>
                <div className="sr-only" aria-live="polite" role="status">{total ? t('desktop.palette.count', { count: total }) : t('desktop.palette.none')}</div>
            </div>
        </div>,
        document.body,
    );
}
