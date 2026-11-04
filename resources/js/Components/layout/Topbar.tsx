import { Link, usePage, router } from '@inertiajs/react';
import { Bell, Search, ChevronDown, PanelLeftClose, PanelLeftOpen, Check, LogOut, UserCircle, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SyncIndicator from '@/Components/layout/SyncIndicator';
import BrandLogo from '@/Components/ui/BrandLogo';
import { cn } from '@/lib/utils';
import { api } from '@/lib/api';
import type { PageProps } from '@/types';
import { setLocale, useLocale, useT } from '@/lib/i18n';
import { openPalette } from '@/lib/desktop';

interface Props {
    title?: string;
    subtitle?: string;
    collapsed: boolean;
    onToggle: () => void;
    actions?: React.ReactNode;
}

interface HotelOption { id: number; code: string; name: string; city?: string }
interface ResHit { id: number; number: string; status?: string; arrival?: string; guest?: { first_name?: string; last_name?: string } | null }
interface GuestHit { id: number; first_name?: string; last_name?: string; email?: string | null; phone?: string | null }

const rows = <T,>(r: unknown): T[] => (Array.isArray(r) ? r : ((r as { data?: T[] })?.data ?? []));

/** Close a popover when clicking outside it. */
function useOutside(ref: React.RefObject<HTMLElement | null>, onOut: () => void) {
    useEffect(() => {
        const h = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && onOut();
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, [ref, onOut]);
}

function GlobalSearch({ full = false, onDone }: { full?: boolean; onDone?: () => void }) {
    const t = useT();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [res, setRes] = useState<ResHit[]>([]);
    const [guests, setGuests] = useState<GuestHit[]>([]);
    const box = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    useOutside(box, () => setOpen(false));

    // Ctrl/Cmd+K is owned by the command palette (DesktopShell) from 1024px; this box is the phone and tablet search.
    useEffect(() => {
        if (full) input.current?.focus();
    }, [full]);

    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setRes([]);
            setGuests([]);
            return;
        }
        let cancelled = false;
        const timer = setTimeout(async () => {
            setLoading(true);
            setError(null);
            const enc = encodeURIComponent(term);
            const [r, g] = await Promise.allSettled([api(`reservations?q=${enc}&per_page=5`), api(`guests?q=${enc}&per_page=5`)]);
            if (cancelled) return;
            setRes(r.status === 'fulfilled' ? rows<ResHit>(r.value).slice(0, 5) : []);
            setGuests(g.status === 'fulfilled' ? rows<GuestHit>(g.value).slice(0, 5) : []);
            if (r.status === 'rejected' && g.status === 'rejected') setError(t('shell.search.unavailable'));
            setLoading(false);
        }, 250);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [q]);

    const go = (href: string) => {
        setOpen(false);
        onDone?.();
        router.visit(href);
    };
    const term = q.trim();

    return (
        <div ref={box} className={cn('relative', full ? 'w-full' : 'hidden xl:block w-56 flex-shrink-0')}>
            <form
                role="search"
                className="flex items-center gap-2 bg-white border border-[var(--c-border-control)] rounded-lg px-3 min-h-[44px] md:min-h-[40px]"
                onSubmit={(e) => { e.preventDefault(); if (term) go(`/reservations?q=${encodeURIComponent(term)}`); }}
            >
                <Search className="w-4 h-4 text-slate-600 flex-shrink-0" aria-hidden="true" />
                <input
                    ref={input}
                    value={q}
                    onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                    placeholder={t('shell.search.placeholder')}
                    className="bg-transparent text-base md:text-sm outline-none flex-1 min-w-0"
                    aria-label={t('shell.search.label')}
                />
                {q ? (
                    <button type="button" onClick={() => setQ('')} aria-label={t('shell.search.clear')} className="p-1"><X className="w-4 h-4 text-slate-600" aria-hidden="true" /></button>
                ) : (
                    <span className="hidden lg:inline text-xs text-slate-700 bg-slate-200 px-1.5 py-0.5 rounded" aria-hidden="true">Ctrl K</span>
                )}
            </form>
            {open && term.length >= 2 && (
                <div className={cn('absolute mt-1 bg-white border border-slate-300 rounded-lg z-[40] text-sm overflow-hidden', full ? 'left-0 right-0' : 'right-0 w-80 max-w-[90vw]')}>
                    {error && <p className="px-3 py-2 text-red-700 bg-red-50">{error}</p>}
                    {loading && <p className="px-3 py-2 text-slate-600">{t('shell.search.searching')}</p>}
                    <p className="px-3 pt-2 pb-1 text-xs font-semibold text-slate-600">{t('shell.search.reservations')}</p>
                    {res.length === 0 && !loading && <p className="px-3 py-1 text-slate-600 text-xs">{t('shell.search.no_matches')}</p>}
                    {res.map((r) => (
                        <button key={r.id} onClick={() => go(`/reservations/${r.id}`)} className="w-full text-left px-3 py-2 hover:bg-slate-50 flex justify-between gap-2">
                            <span className="font-mono text-xs">{r.number}</span>
                            <span className="truncate text-slate-700">{[r.guest?.first_name, r.guest?.last_name].filter(Boolean).join(' ')}</span>
                        </button>
                    ))}
                    <button onClick={() => go(`/reservations?q=${encodeURIComponent(term)}`)} className="w-full text-left px-3 py-2 text-xs text-blue-800 underline">{t('shell.search.all_reservations')}</button>
                    <p className="px-3 pt-2 pb-1 text-xs font-semibold text-slate-600 border-t border-slate-100">{t('shell.search.guests')}</p>
                    {guests.length === 0 && !loading && <p className="px-3 py-1 text-slate-600 text-xs">{t('shell.search.no_matches')}</p>}
                    {guests.map((g) => (
                        <button key={g.id} onClick={() => go(`/guests?q=${encodeURIComponent(`${g.first_name ?? ''} ${g.last_name ?? ''}`.trim())}`)} className="w-full text-left px-3 py-2 hover:bg-slate-50">
                            <span>{[g.first_name, g.last_name].filter(Boolean).join(' ')}</span>
                            <span className="block text-xs text-slate-600 truncate">{g.email ?? g.phone ?? ''}</span>
                        </button>
                    ))}
                    <button onClick={() => go(`/guests?q=${encodeURIComponent(term)}`)} className="w-full text-left px-3 py-2 text-xs text-blue-800 underline">{t('shell.search.all_guests')}</button>
                </div>
            )}
        </div>
    );
}

function HotelSwitcher() {
    const t = useT();
    const { props } = usePage<PageProps>();
    const hotels = (props.hotels as HotelOption[] | undefined) ?? [];
    const current = props.property as unknown as HotelOption | null;
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    useOutside(box, () => setOpen(false));

    const pick = (id: number) => {
        document.cookie = `hotel_id=${id}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
        setOpen(false);
        window.location.reload();
    };

    if (hotels.length <= 1) {
        return <span className="hidden 2xl:block pl-3 border-l border-slate-300 text-sm font-medium text-slate-700 truncate max-w-[160px]">{current?.name ?? ''}</span>;
    }
    return (
        <div ref={box} className="relative hidden md:block">
            <button onClick={() => setOpen(!open)} aria-haspopup="menu" aria-expanded={open} className="flex items-center gap-1.5 pl-3 min-h-[40px] border-l border-slate-300 text-sm text-slate-700 hover:text-slate-900">
                <span className="font-medium truncate max-w-[100px] lg:max-w-[160px]">{current?.name ?? t('shell.select_hotel')}</span>
                <ChevronDown className="w-4 h-4 text-slate-600" aria-hidden="true" />
            </button>
            {open && (
                <div className="absolute right-0 mt-2 w-64 max-w-[90vw] bg-white border border-slate-300 rounded-lg z-[40] py-1 max-h-80 overflow-y-auto">
                    {hotels.map((h) => (
                        <button key={h.id} onClick={() => pick(h.id)} className="w-full text-left px-3 py-2.5 hover:bg-slate-50 flex items-center gap-2 text-sm">
                            <span className="flex-1 min-w-0">
                                <span className="block truncate">{h.name}</span>
                                <span className="block text-xs text-slate-600">{h.code}{h.city ? ` · ${h.city}` : ''}</span>
                            </span>
                            {current?.id === h.id && <Check className="w-4 h-4 text-emerald-700" aria-hidden="true" />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function UserMenu() {
    const t = useT();
    const user = usePage<PageProps>().props.auth?.user;
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    useOutside(box, () => setOpen(false));
    if (!user) return null;

    return (
        <div ref={box} className="relative flex-shrink-0">
            <button onClick={() => setOpen(!open)} aria-label={t('shell.user_menu')} aria-haspopup="menu" aria-expanded={open}
                className="w-11 h-11 rounded-full bg-[var(--c-primary)] text-white text-xs font-bold flex items-center justify-center">
                {user.name?.slice(0, 2).toUpperCase()}
            </button>
            {open && (
                <div className="absolute right-0 mt-2 w-56 bg-white border border-slate-300 rounded-lg z-[40] py-1 text-sm">
                    <div className="px-3 py-2 border-b border-slate-100">
                        <p className="font-semibold text-slate-900 truncate">{user.name}</p>
                        <p className="text-xs text-slate-600 truncate">{user.roles?.[0] ?? t('shell.staff')}</p>
                    </div>
                    <Link href="/profile" className="flex items-center gap-2 px-3 py-2.5 hover:bg-slate-50" onClick={() => setOpen(false)}>
                        <UserCircle className="w-4 h-4" aria-hidden="true" /> {t('shell.profile')}
                    </Link>
                    <Link href="/logout" method="post" as="button" className="w-full flex items-center gap-2 px-3 py-2.5 hover:bg-slate-50 text-red-700">
                        <LogOut className="w-4 h-4" aria-hidden="true" /> {t('shell.logout')}
                    </Link>
                </div>
            )}
        </div>
    );
}

/**
 * Top app bar. Phone: logo, page title (the h1), search and user menu. Desktop: sidebar toggle, title, search,
 * actions, notifications, language, hotel and user. The h1 is focused after each navigation (see AppLayout).
 */
export default function Topbar({ title, subtitle, collapsed, onToggle, actions }: Props) {
    const t = useT();
    const [phoneSearch, setPhoneSearch] = useState(false);
    return (
        <header className="flex-shrink-0 bg-white border-b border-slate-300 z-[30] pt-[var(--safe-top)] pl-[var(--safe-left)] pr-[var(--safe-right)]">
            <div className="min-h-[var(--bar-top-h)] flex items-center px-3 md:px-5 gap-2 md:gap-4">
                <span className="md:hidden flex-shrink-0"><BrandLogo variant="emblem" size={28} /></span>
                <button onClick={onToggle} className="hidden lg:inline-flex btn-ghost btn-icon" aria-label={t('shell.toggle_sidebar')} aria-expanded={!collapsed}>
                    {collapsed ? <PanelLeftOpen className="w-5 h-5" aria-hidden="true" /> : <PanelLeftClose className="w-5 h-5" aria-hidden="true" />}
                </button>

                <div className="flex-1 min-w-[2.5rem] md:min-w-[7rem]">
                    {title && <h1 id="page-title" tabIndex={-1} data-page-title className="text-[1.375rem] md:text-[1.5rem] lg:text-[1.75rem] font-semibold text-slate-900 truncate leading-tight">{title}</h1>}
                    {subtitle && <p className="text-xs md:text-sm text-slate-600 truncate">{subtitle}</p>}
                </div>

                <button type="button" onClick={openPalette} data-palette-button
                    className="hidden lg:flex flex-shrink-0 items-center gap-2 bg-white border border-[var(--c-border-control)] rounded-lg px-3 min-h-[40px] w-48 xl:w-64 text-sm text-slate-600 hover:border-[var(--c-primary)]"
                    aria-label={t('desktop.palette.open')} aria-keyshortcuts="Control+K Meta+K">
                    <Search className="w-4 h-4 text-slate-600 flex-shrink-0" aria-hidden="true" />
                    <span className="flex-1 text-left truncate">{t('desktop.palette.button')}</span>
                    <span className="text-xs text-slate-700 bg-slate-200 px-1.5 py-0.5 rounded" aria-hidden="true">Ctrl K</span>
                </button>

                {actions && <div data-page-actions className="hidden 2xl:flex flex-shrink-0 items-center gap-2">{actions}</div>}

                <SyncIndicator />

                {/* Phone and tablet slot for the voice-alert sound control (see VoiceNotifier): keeps it away from page actions */}
                <span id="voice-slot" className="lg:hidden flex-shrink-0 empty:hidden" />

                <button type="button" className="lg:hidden flex-shrink-0 btn-ghost btn-icon" onClick={() => setPhoneSearch(!phoneSearch)} aria-expanded={phoneSearch} aria-label={t('shell.search.label')}>
                    {phoneSearch ? <X className="w-5 h-5" aria-hidden="true" /> : <Search className="w-5 h-5" aria-hidden="true" />}
                </button>

                <button type="button" className="hidden md:inline-flex flex-shrink-0 btn-ghost btn-icon" aria-label={t('shell.notifications')}>
                    <Bell className="w-5 h-5" aria-hidden="true" />
                </button>

                <span className="hidden md:block flex-shrink-0"><LanguageSwitch /></span>
                <HotelSwitcher />
                <UserMenu />
            </div>
            {phoneSearch && (
                <div className="lg:hidden px-3 md:px-5 pb-3">
                    <GlobalSearch full onDone={() => setPhoneSearch(false)} />
                </div>
            )}
            {actions && <div data-page-actions className="2xl:hidden flex flex-wrap items-center gap-2 px-3 md:px-5 pb-3">{actions}</div>}
        </header>
    );
}

/** FR / EN toggle; the choice is saved on the user. */
export function LanguageSwitch({ dark = false }: { dark?: boolean }) {
    const t = useT();
    const locale = useLocale();
    return (
        <div role="group" aria-label={t('shell.language')} className={cn('flex items-center rounded-lg border text-xs font-semibold overflow-hidden', dark ? 'border-[var(--c-on-chrome-muted)]' : 'border-[var(--c-border-control)]')}>
            {(['fr', 'en'] as const).map((l) => (
                <button key={l} type="button" onClick={() => l !== locale && setLocale(l)} aria-pressed={l === locale}
                    className={cn('px-3 min-w-[44px] min-h-[44px] lg:min-h-[32px] uppercase', l === locale ? (dark ? 'bg-white text-[var(--c-primary)]' : 'bg-[var(--c-primary)] text-white') : (dark ? 'text-[var(--c-on-chrome-muted)] hover:text-white' : 'text-slate-700 hover:bg-slate-100'))}>
                    {l}
                </button>
            ))}
        </div>
    );
}
