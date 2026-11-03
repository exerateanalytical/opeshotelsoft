import { Link, usePage, router } from '@inertiajs/react';
import { Bell, Search, ChevronDown, PanelLeftClose, PanelLeftOpen, Check, LogOut, UserCircle, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { api } from '@/lib/api';
import type { PageProps } from '@/types';
import { setLocale, useLocale, useT } from '@/lib/i18n';

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

function GlobalSearch() {
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

    useEffect(() => {
        const k = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', k);
        return () => window.removeEventListener('keydown', k);
    }, []);

    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setRes([]);
            setGuests([]);
            return;
        }
        let cancelled = false;
        const t = setTimeout(async () => {
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
            clearTimeout(t);
        };
    }, [q]);

    const go = (href: string) => {
        setOpen(false);
        router.visit(href);
    };
    const term = q.trim();

    return (
        <div ref={box} className="relative hidden sm:block w-44 md:w-64">
            <form
                className="flex items-center gap-2 bg-slate-100 rounded-lg px-3 py-2"
                onSubmit={(e) => { e.preventDefault(); if (term) go(`/reservations?q=${encodeURIComponent(term)}`); }}
            >
                <Search className="w-3.5 h-3.5 text-slate-400 flex-shrink-0" />
                <input
                    ref={input}
                    value={q}
                    onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                    placeholder={t('shell.search.placeholder')}
                    className="bg-transparent text-sm outline-none flex-1 min-w-0 placeholder:text-slate-400"
                    aria-label={t('shell.search.label')}
                />
                {q ? (
                    <button type="button" onClick={() => setQ('')} aria-label={t('shell.search.clear')}><X className="w-3.5 h-3.5 text-slate-400" /></button>
                ) : (
                    <span className="hidden md:inline text-xs text-slate-400 bg-slate-200 px-1.5 py-0.5 rounded">Ctrl K</span>
                )}
            </form>
            {open && term.length >= 2 && (
                <div className="absolute right-0 mt-1 w-80 max-w-[90vw] bg-white border border-slate-200 rounded-lg  z-50 text-sm overflow-hidden">
                    {error && <p className="px-3 py-2 text-red-600 bg-red-50">{error}</p>}
                    {loading && <p className="px-3 py-2 text-slate-500">{t('shell.search.searching')}</p>}
                    <p className="px-3 pt-2 pb-1 text-xs uppercase font-semibold text-slate-400">{t('shell.search.reservations')}</p>
                    {res.length === 0 && !loading && <p className="px-3 py-1 text-slate-400 text-xs">{t('shell.search.no_matches')}</p>}
                    {res.map((r) => (
                        <button key={r.id} onClick={() => go(`/reservations/${r.id}`)} className="w-full text-left px-3 py-1.5 hover:bg-slate-50 flex justify-between gap-2">
                            <span className="font-mono text-xs">{r.number}</span>
                            <span className="truncate text-slate-600">{[r.guest?.first_name, r.guest?.last_name].filter(Boolean).join(' ')}</span>
                        </button>
                    ))}
                    <button onClick={() => go(`/reservations?q=${encodeURIComponent(term)}`)} className="w-full text-left px-3 py-1 text-xs text-blue-700 hover:underline">{t('shell.search.all_reservations')}</button>
                    <p className="px-3 pt-2 pb-1 text-xs uppercase font-semibold text-slate-400 border-t border-slate-100">{t('shell.search.guests')}</p>
                    {guests.length === 0 && !loading && <p className="px-3 py-1 text-slate-400 text-xs">{t('shell.search.no_matches')}</p>}
                    {guests.map((g) => (
                        <button key={g.id} onClick={() => go(`/guests?q=${encodeURIComponent(`${g.first_name ?? ''} ${g.last_name ?? ''}`.trim())}`)} className="w-full text-left px-3 py-1.5 hover:bg-slate-50">
                            <span>{[g.first_name, g.last_name].filter(Boolean).join(' ')}</span>
                            <span className="block text-xs text-slate-400 truncate">{g.email ?? g.phone ?? ''}</span>
                        </button>
                    ))}
                    <button onClick={() => go(`/guests?q=${encodeURIComponent(term)}`)} className="w-full text-left px-3 py-1 pb-2 text-xs text-blue-700 hover:underline">{t('shell.search.all_guests')}</button>
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
        return <span className="hidden md:block pl-3 border-l border-slate-200 text-sm font-medium text-slate-600 truncate max-w-[140px]">{current?.name ?? ''}</span>;
    }
    return (
        <div ref={box} className="relative">
            <button onClick={() => setOpen(!open)} className="flex items-center gap-1.5 pl-3 border-l border-slate-200 text-sm text-slate-600 hover:text-slate-900">
                <span className="font-medium truncate max-w-[90px] md:max-w-[140px]">{current?.name ?? t('shell.select_hotel')}</span>
                <ChevronDown className="w-3.5 h-3.5 text-slate-400" />
            </button>
            {open && (
                <div className="absolute right-0 mt-2 w-64 max-w-[90vw] bg-white border border-slate-200 rounded-lg  z-50 py-1 max-h-80 overflow-y-auto">
                    {hotels.map((h) => (
                        <button key={h.id} onClick={() => pick(h.id)} className="w-full text-left px-3 py-2 hover:bg-slate-50 flex items-center gap-2 text-sm">
                            <span className="flex-1 min-w-0">
                                <span className="block truncate">{h.name}</span>
                                <span className="block text-xs text-slate-400">{h.code}{h.city ? ` · ${h.city}` : ''}</span>
                            </span>
                            {current?.id === h.id && <Check className="w-4 h-4 text-emerald-600" />}
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
        <div ref={box} className="relative">
            <button onClick={() => setOpen(!open)} aria-label={t('shell.user_menu')} className="w-8 h-8 rounded-full bg-[#0f2044] text-white text-xs font-bold flex items-center justify-center">
                {user.name?.slice(0, 2).toUpperCase()}
            </button>
            {open && (
                <div className="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-lg  z-50 py-1 text-sm">
                    <div className="px-3 py-2 border-b border-slate-100">
                        <p className="font-semibold text-slate-900 truncate">{user.name}</p>
                        <p className="text-xs text-slate-500 truncate">{user.roles?.[0] ?? t('shell.staff')}</p>
                    </div>
                    <Link href="/profile" className="flex items-center gap-2 px-3 py-2 hover:bg-slate-50" onClick={() => setOpen(false)}>
                        <UserCircle className="w-4 h-4" /> {t('shell.profile')}
                    </Link>
                    <Link href="/logout" method="post" as="button" className="w-full flex items-center gap-2 px-3 py-2 hover:bg-slate-50 text-red-600">
                        <LogOut className="w-4 h-4" /> {t('shell.logout')}
                    </Link>
                </div>
            )}
        </div>
    );
}

export default function Topbar({ title, subtitle, collapsed, onToggle, actions }: Props) {
    const t = useT();
    return (
        <header className="h-[60px] bg-white border-b border-slate-200 flex items-center px-3 sm:px-5 gap-2 sm:gap-4 flex-shrink-0">
            <button onClick={onToggle} className="text-slate-400 hover:text-slate-700 transition-colors" aria-label={t('shell.toggle_sidebar')}>
                {collapsed ? <PanelLeftOpen className="w-5 h-5" /> : <PanelLeftClose className="w-5 h-5" />}
            </button>

            <div className="flex-1 min-w-0">
                {title && <h1 className="text-[15px] font-semibold text-slate-900 truncate leading-tight">{title}</h1>}
                {subtitle && <p className="text-xs text-slate-500 truncate">{subtitle}</p>}
            </div>

            <GlobalSearch />

            {actions && <div className={cn('flex items-center gap-2')}>{actions}</div>}

            <button className="relative text-slate-400 hover:text-slate-700 transition-colors hidden sm:block" aria-label={t('shell.notifications')}>
                <Bell className="w-5 h-5" />
            </button>

            <LanguageSwitch />
            <HotelSwitcher />
            <UserMenu />
        </header>
    );
}

/** FR / EN toggle; the choice is saved on the user. */
export function LanguageSwitch({ dark = false }: { dark?: boolean }) {
    const t = useT();
    const locale = useLocale();
    return (
        <div role="group" aria-label={t('shell.language')} className={cn('flex items-center rounded-lg border text-xs font-semibold overflow-hidden', dark ? 'border-[#1a3a6b]' : 'border-slate-200')}>
            {(['fr', 'en'] as const).map((l) => (
                <button key={l} type="button" onClick={() => l !== locale && setLocale(l)} aria-pressed={l === locale}
                    className={cn('px-2 py-1 uppercase', l === locale ? (dark ? 'bg-[#1a3a6b] text-white' : 'bg-slate-900 text-white') : (dark ? 'text-slate-400 hover:text-white' : 'text-slate-500 hover:text-slate-900'))}>
                    {l}
                </button>
            ))}
        </div>
    );
}
