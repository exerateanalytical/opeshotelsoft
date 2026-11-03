import { Head, Link, usePage } from '@inertiajs/react';
import { Bed, Building2, CreditCard, Home, LogOut, MessageCircle, MoreHorizontal, Star, X, User, ConciergeBell, CalendarPlus } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { setLocale, useLocale, useT } from '@/lib/i18n';
import { mapi } from './api';

const NAV = [
    { href: '/account', key: 'home', icon: Home },
    { href: '/account/card', key: 'card', icon: CreditCard },
    { href: '/account/points', key: 'points', icon: Star },
    { href: '/account/bookings', key: 'bookings', icon: Bed },
    { href: '/account/book', key: 'book', icon: CalendarPlus },
    { href: '/account/hotels', key: 'hotels', icon: Building2 },
    { href: '/account/chat', key: 'chat', icon: MessageCircle },
    { href: '/account/requests', key: 'requests', icon: ConciergeBell },
    { href: '/account/profile', key: 'profile', icon: User },
];
// Bottom bar on phones: the five most used areas; the rest is under "More".
const BOTTOM = ['home', 'card', 'bookings', 'chat'];

export default function MemberLayout({ title, children }: { title: string; children: ReactNode }) {
    const t = useT();
    const locale = useLocale();
    const url = usePage().url.split('?')[0];
    const [more, setMore] = useState(false);
    useEffect(() => setMore(false), [url]);
    const active = (href: string) => (href === '/account' ? url === '/account' : url.startsWith(href));

    async function logout() {
        await mapi('POST', '/account/logout').catch(() => null);
        window.location.href = '/login?tab=member';
    }

    return (
        <div className="min-h-dvh bg-slate-50 text-slate-900 pb-[calc(4.5rem+var(--safe-bottom))] lg:pb-0 lg:flex">
            <Head title={title} />
            <aside className="hidden lg:flex lg:flex-col w-64 shrink-0 bg-navy-900 text-slate-200 p-4 gap-1 min-h-screen">
                <p className="text-white font-bold text-lg px-3 py-3">{t('member.brand')}</p>
                {NAV.map(({ href, key, icon: Icon }) => (
                    <Link key={key} href={href} className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm ${active(href) ? 'bg-navy-700 text-white border-l-4 border-brand-500' : 'hover:bg-navy-700'}`}>
                        <Icon className="w-5 h-5" />{t(`member.nav.${key}`)}
                    </Link>
                ))}
                <button type="button" onClick={logout} className="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm hover:bg-navy-700 mt-auto text-left"><LogOut className="w-5 h-5" />{t('member.nav.logout')}</button>
            </aside>

            <div className="flex-1 min-w-0">
                <header className="sticky top-0 z-20 flex items-center justify-between gap-3 px-4 pb-3 pt-[calc(0.75rem+var(--safe-top))] lg:pt-3 bg-white border-b border-slate-200">
                    <h1 className="text-lg font-semibold truncate">{title}</h1>
                    <div className="flex items-center gap-2">
                        {(['fr', 'en'] as const).map((l) => (
                            <button key={l} type="button" onClick={() => setLocale(l)} aria-pressed={locale === l}
                                className={`min-h-11 min-w-11 px-2.5 rounded-lg text-sm font-semibold border ${locale === l ? 'bg-navy-900 text-white border-navy-900' : 'border-slate-200 text-slate-600'}`}>{l.toUpperCase()}</button>
                        ))}
                        <button type="button" onClick={logout} className="lg:hidden min-h-11 min-w-11 inline-flex items-center justify-center text-slate-600" aria-label={t('member.nav.logout')}><LogOut className="w-5 h-5" /></button>
                    </div>
                </header>
                <main className="p-4 max-w-3xl mx-auto space-y-4">{children}</main>
            </div>

            <nav className="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-200 grid grid-cols-5 pb-[var(--safe-bottom)]" aria-label={t('member.brand')}>
                {NAV.filter((n) => BOTTOM.includes(n.key)).map(({ href, key, icon: Icon }) => (
                    <Link key={key} href={href} aria-current={active(href) ? 'page' : undefined} className={`flex flex-col items-center justify-center gap-0.5 min-h-14 text-[0.8125rem] active:bg-slate-100 ${active(href) ? 'text-navy-900 font-semibold' : 'text-slate-600'}`}>
                        <Icon className="w-6 h-6" aria-hidden="true" />{t(`member.nav.${key}`)}
                    </Link>
                ))}
                <button type="button" onClick={() => setMore(true)} aria-haspopup="dialog" className="flex flex-col items-center justify-center gap-0.5 min-h-14 text-[0.8125rem] text-slate-600 active:bg-slate-100">
                    <MoreHorizontal className="w-6 h-6" aria-hidden="true" />{t('member.nav.more')}
                </button>
            </nav>

            {more && (
                <div className="lg:hidden fixed inset-0 z-40 bg-slate-900/60" onClick={() => setMore(false)}>
                    <div role="dialog" aria-modal="true" aria-label={t('member.nav.more')} onClick={(e) => e.stopPropagation()}
                        className="absolute bottom-0 inset-x-0 bg-white rounded-t-2xl border-t border-slate-200 p-4 pb-[calc(1rem+var(--safe-bottom))] max-h-[85dvh] overflow-y-auto overscroll-contain">
                        <div className="flex items-center justify-between mb-2">
                            <p className="text-lg font-semibold">{t('member.nav.more')}</p>
                            <button type="button" onClick={() => setMore(false)} aria-label={t('member.nav.close')} className="min-h-11 min-w-11 inline-flex items-center justify-center text-slate-600"><X className="w-6 h-6" /></button>
                        </div>
                        <ul>
                            {NAV.filter((n) => !BOTTOM.includes(n.key)).map(({ href, key, icon: Icon }) => (
                                <li key={key}><Link href={href} className="flex items-center gap-3 min-h-12 px-2 rounded-lg text-base active:bg-slate-100"><Icon className="w-5 h-5 text-navy-500" aria-hidden="true" />{t(`member.nav.${key}`)}</Link></li>
                            ))}
                            <li><button type="button" onClick={logout} className="flex w-full items-center gap-3 min-h-12 px-2 rounded-lg text-base text-left active:bg-slate-100"><LogOut className="w-5 h-5 text-slate-600" aria-hidden="true" />{t('member.nav.logout')}</button></li>
                        </ul>
                    </div>
                </div>
            )}
        </div>
    );
}

/** Quick links to the areas that are not in the phone bottom bar. */
export function MoreLinks() {
    const t = useT();
    return (
        <div className="grid grid-cols-2 gap-2 lg:hidden">
            {NAV.filter((n) => !BOTTOM.includes(n.key)).map(({ href, key, icon: Icon }) => (
                <Link key={key} href={href} className="card p-3 flex items-center gap-2 text-sm"><Icon className="w-5 h-5 text-navy-500" />{t(`member.nav.${key}`)}</Link>
            ))}
        </div>
    );
}
