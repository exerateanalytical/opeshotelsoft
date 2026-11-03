import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Plus, Search, CalendarDays, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

const STATUSES: [string, string][] = [
    ['', 'common.all'], ['tentative', 'res.status.tentative'], ['confirmed', 'res.status.confirmed'], ['checked_in', 'res.status.checked_in'],
    ['checked_out', 'res.status.checked_out'], ['cancelled', 'res.status.cancelled'], ['no_show', 'res.status.no_show'],
];

const BADGE: Record<string, string> = {
    tentative: 'bg-amber-50 text-amber-700', confirmed: 'bg-blue-50 text-blue-700', checked_in: 'bg-emerald-50 text-emerald-700',
    checked_out: 'bg-slate-100 text-slate-600', cancelled: 'bg-red-50 text-red-700', no_show: 'bg-red-50 text-red-700',
};

interface Reservation {
    id: number;
    number: string;
    arrival: string;
    departure: string;
    status: string;
    channel: string;
    total_amount: number;
    guest: { first_name: string; last_name: string; vip: boolean } | null;
    room: { number: string } | null;
    room_type: { code: string } | null;
}

interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }

const nights = (a: string, d: string) => Math.round((Date.parse(d) - Date.parse(a)) / 86_400_000);

export default function ReservationsIndex() {
    const t = useT();
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const [from, setFrom] = useState('');
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState<number | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    const params = new URLSearchParams({ page: String(page), per_page: '25' });
    if (status) params.set('status', status);
    if (q) params.set('q', q);
    if (from) params.set('arriving', from);
    const list = useApi<Page<Reservation>>(`reservations?${params}`);

    const [cancelling, setCancelling] = useState<{ id: number; reason: string } | null>(null);

    async function act(r: Reservation, action: 'check-in' | 'check-out' | 'cancel', reason?: string) {
        const body = action === 'cancel' ? { reason } : {};
        if (action === 'cancel' && !reason) return;
        setCancelling(null);
        setBusy(r.id);
        setMessage(null);
        try {
            await api(`reservations/${r.id}/${action}`, { method: 'POST', body: JSON.stringify(body) });
            list.reload();
        } catch (e) {
            setMessage(e instanceof ApiError ? e.message : String(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <AppLayout
            title={t('res.title')}
            subtitle={t('res.index.subtitle')}
            actions={<div className="flex flex-wrap gap-2"><Link href="/reservations/tape-chart" className="btn-secondary">{t('res.tape.short')}</Link><Link href="/reservations/waitlist" className="btn-secondary">{t('res.waitlist.title')}</Link><Link href="/reservations/create" className="btn-primary"><Plus className="w-4 h-4" /> {t('res.create.title')}</Link></div>}
        >
            <Head title={t('res.title')} />

            <div className="flex items-center gap-3 mb-5 flex-wrap">
                <div className="relative flex-1 min-w-[200px]">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    <input className="input pl-9" placeholder={t('res.index.search')} value={q}
                        onChange={(e) => (setQ(e.target.value), setPage(1))} />
                </div>
                <label className="text-sm text-slate-500">{t('res.index.arriving')}</label>
                <input type="date" className="input w-40" value={from} onChange={(e) => (setFrom(e.target.value), setPage(1))} />
            </div>

            <div className="flex gap-1 mb-5 overflow-x-auto pb-1">
                {STATUSES.map(([key, label]) => (
                    <button key={key} onClick={() => (setStatus(key), setPage(1))}
                        className={cn('px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap',
                            status === key ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300')}>
                        {t(label)}
                    </button>
                ))}
            </div>

            {(message || list.error) && (
                <div className="mb-4 rounded-lg bg-red-50 text-red-700 text-sm px-4 py-3">{message ?? list.error?.message}</div>
            )}

            <div className="card overflow-x-auto">
                <table className="table-auto w-full">
                    <thead>
                        <tr>
                            <th>{t('res.confirmation')}</th><th>{t('res.guest')}</th><th>{t('res.room')}</th><th>{t('res.check_in')}</th><th>{t('res.check_out')}</th>
                            <th>{t('res.nights')}</th><th>{t('res.amount')}</th><th>{t('res.status')}</th><th>{t('res.source')}</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.loading && !list.data && (
                            <tr><td colSpan={10} className="text-center py-12 text-slate-400"><Loader2 className="w-6 h-6 mx-auto animate-spin" /></td></tr>
                        )}
                        {list.data?.data.map((r) => (
                            <tr key={r.id}>
                                <td className="font-mono text-sm"><Link href={`/reservations/${r.id}`} className="text-emerald-700 hover:underline">{r.number}</Link></td>
                                <td>{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '—'}{r.guest?.vip && <span className="ml-1 text-amber-500">★</span>}</td>
                                <td>{r.room?.number ?? <span className="text-slate-400">{r.room_type?.code}</span>}</td>
                                <td>{formatDate(r.arrival)}</td>
                                <td>{formatDate(r.departure)}</td>
                                <td>{nights(r.arrival, r.departure)}</td>
                                <td>{formatCurrency(r.total_amount)}</td>
                                <td><span className={cn('px-2 py-0.5 rounded text-xs font-medium', BADGE[r.status])}>{t(`res.status.${r.status}`)}</span></td>
                                <td className="text-sm text-slate-500">{t(`res.channel.${r.channel}`) === `res.channel.${r.channel}` ? r.channel : t(`res.channel.${r.channel}`)}</td>
                                <td className="whitespace-nowrap text-right">
                                    {cancelling?.id === r.id ? (
                                        <span className="inline-flex items-center gap-1">
                                            <input className="input w-40" autoFocus placeholder={t('res.cancel_reason')} aria-label={t('res.cancel_reason')} value={cancelling.reason}
                                                onChange={(e) => setCancelling({ id: r.id, reason: e.target.value })} />
                                            <button className="btn-primary text-xs" disabled={!cancelling.reason.trim()} onClick={() => act(r, 'cancel', cancelling.reason.trim())}>{t('res.confirm')}</button>
                                            <button className="btn-secondary text-xs" onClick={() => setCancelling(null)}>{t('res.keep')}</button>
                                        </span>
                                    ) : busy === r.id ? <Loader2 className="w-4 h-4 animate-spin inline" /> : (
                                        <>
                                            {['confirmed', 'tentative'].includes(r.status) && (
                                                <>
                                                    <button className="btn-secondary text-xs mr-1" onClick={() => act(r, 'check-in')}>{t('res.do_check_in')}</button>
                                                    <button className="text-xs text-red-600 hover:underline" onClick={() => setCancelling({ id: r.id, reason: '' })}>{t('res.do_cancel')}</button>
                                                </>
                                            )}
                                            {r.status === 'checked_in' && (
                                                <button className="btn-secondary text-xs" onClick={() => act(r, 'check-out')}>{t('res.do_check_out')}</button>
                                            )}
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {list.data && list.data.data.length === 0 && (
                            <tr>
                                <td colSpan={10} className="text-center py-16 text-slate-400">
                                    <CalendarDays className="w-10 h-10 mx-auto mb-3 opacity-30" />
                                    <p className="font-medium">{t('res.index.empty')}</p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {list.data && list.data.last_page > 1 && (
                <div className="flex items-center justify-between mt-4 text-sm text-slate-500">
                    <span>{t('res.index.count', { count: list.data.total })}</span>
                    <div className="flex gap-2">
                        <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                        <span className="px-2 py-2">{page} / {list.data.last_page}</span>
                        <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
