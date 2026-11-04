import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';
import MemberLayout from './MemberLayout';
import { mapi, MemberApiError, money } from './api';

interface Offer {
    room_type: { id: number; name: string; max_adults: number }; rate_plan: { id: number; name: string; members_only: boolean; refundable: boolean; deposit_required: boolean };
    rooms_left: number; total: number; currency: string; nights: number;
}
interface Result { hotel: { code: string; name: string; city: string }; offers: Offer[] }

const today = () => new Date().toISOString().slice(0, 10);
const plus = (d: string, n: number) => new Date(new Date(d + 'T00:00:00').getTime() + n * 86400000).toISOString().slice(0, 10);

export default function Book({ hotels, hotel }: { hotels: { code: string; name: string; city: string }[]; hotel: string | null }) {
    const t = useT();
    const [f, setF] = useState({ arrival: plus(today(), 1), departure: plus(today(), 2), adults: 2, hotel_code: hotel ?? '' });
    const [results, setResults] = useState<Result[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState<{ confirmation: string; deposit_required: number; pay_url: string | null } | null>(null);

    async function search(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true); setError(''); setDone(null);
        try { setResults((await mapi<{ results: Result[] }>('POST', '/account/book/search', { ...f, hotel_code: f.hotel_code || null })).results); }
        catch (err) { setError(err instanceof MemberApiError ? err.message : String(err)); }
        setBusy(false);
    }
    async function book(r: Result, o: Offer) {
        setBusy(true); setError('');
        try {
            setDone(await mapi('POST', '/account/book', { hotel_code: r.hotel.code, room_type_id: o.room_type.id, rate_plan_id: o.rate_plan.id, arrival: f.arrival, departure: f.departure, adults: f.adults }));
        } catch (err) { setError(err instanceof MemberApiError ? err.message : String(err)); }
        setBusy(false);
    }

    if (done) {
        return (
            <MemberLayout title={t('member.book.title')}>
                <div className="card p-4 space-y-3">
                    <p className="text-lg font-semibold">{t('member.book.done')}</p>
                    <p>{t('member.book.confirmation')} <strong>{done.confirmation}</strong></p>
                    {done.pay_url && <a className="btn-primary" href={done.pay_url}>{t('member.book.pay_deposit')}</a>}
                    <Link href="/account/bookings" className="btn-secondary inline-flex">{t('member.nav.bookings')}</Link>
                </div>
            </MemberLayout>
        );
    }
    return (
        <MemberLayout title={t('member.book.title')}>
            <form onSubmit={search} className="card p-4 grid gap-3 sm:grid-cols-2">
                <label className="text-sm">{t('member.arrival')}<input type="date" className="input" min={today()} value={f.arrival} onChange={(e) => setF({ ...f, arrival: e.target.value })} required /></label>
                <label className="text-sm">{t('member.departure')}<input type="date" className="input" min={plus(f.arrival, 1)} value={f.departure} onChange={(e) => setF({ ...f, departure: e.target.value })} required /></label>
                <label className="text-sm">{t('member.book.adults')}<input type="number" min={1} max={8} className="input" value={f.adults} onChange={(e) => setF({ ...f, adults: Number(e.target.value) })} /></label>
                <label className="text-sm">{t('member.book.hotel')}
                    <select className="select" value={f.hotel_code} onChange={(e) => setF({ ...f, hotel_code: e.target.value })}>
                        <option value="">{t('member.book.all_hotels')}</option>
                        {hotels.map((h) => <option key={h.code} value={h.code}>{h.name} · {h.city}</option>)}
                    </select>
                </label>
                <button className="btn-primary justify-center sm:col-span-2" disabled={busy}>{t('member.book.search')}</button>
            </form>
            <p className="text-sm text-slate-600">{t('member.book.prefilled')}</p>
            {error && <p role="alert" className="text-red-700 text-sm">{error}</p>}
            {results?.map((r) => (
                <div key={r.hotel.code} className="card">
                    <p className="p-4 font-semibold border-b border-slate-200">{r.hotel.name} <span className="font-normal text-slate-500">· {r.hotel.city}</span></p>
                    {r.offers.length === 0 && <p className="p-4 text-slate-600">{t('member.book.none')}</p>}
                    <ul className="divide-y divide-slate-100">
                        {r.offers.map((o, i) => (
                            <li key={i} className="p-4 flex items-center justify-between gap-3">
                                <div>
                                    <p className="font-medium">{o.room_type.name}</p>
                                    <p className="text-sm text-slate-600">{o.rate_plan.name}{o.rate_plan.members_only ? ` · ${t('member.book.members_rate')}` : ''} · {t(o.rate_plan.refundable ? 'member.book.refundable' : 'member.book.non_refundable')}</p>
                                    <p className="text-sm text-slate-500">{t('member.nights', { count: o.nights })}</p>
                                </div>
                                <div className="text-right">
                                    <p className="font-semibold">{money(o.total, o.currency)}</p>
                                    <button type="button" className="btn-primary mt-1" disabled={busy} onClick={() => book(r, o)}>{t('member.book.book')}</button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </MemberLayout>
    );
}
