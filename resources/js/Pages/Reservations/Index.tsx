import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Plus, Search, CalendarDays, Loader2, Star } from 'lucide-react';
import Button from '@/Components/ui/Button';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';
import StatusBadge from './StatusBadge';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

const STATUSES: [string, string][] = [
    ['', 'common.all'], ['tentative', 'res.status.tentative'], ['confirmed', 'res.status.confirmed'], ['checked_in', 'res.status.checked_in'],
    ['checked_out', 'res.status.checked_out'], ['cancelled', 'res.status.cancelled'], ['no_show', 'res.status.no_show'],
];


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

            <FilterBar
                activeCount={(status ? 1 : 0) + (from ? 1 : 0)}
                onClear={() => (setStatus(''), setFrom(''), setPage(1))}
                search={
                    <div className="relative min-w-0 flex-1 sm:w-80 sm:flex-none">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                        <input type="search" className="input pl-9" placeholder={t('res.index.search')} aria-label={t('res.index.search')} value={q}
                            onChange={(e) => (setQ(e.target.value), setPage(1))} />
                    </div>
                }
            >
                <Field label={t('res.index.arriving')}>
                    <input type="date" className="input sm:w-44" value={from} onChange={(e) => (setFrom(e.target.value), setPage(1))} />
                </Field>
                <Field label={t('res.status')} className="sm:hidden">
                    <select className="select" value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))}>
                        {STATUSES.map(([key, label]) => <option key={key} value={key}>{t(label)}</option>)}
                    </select>
                </Field>
            </FilterBar>

            <div role="group" aria-label={t('res.status')} className="mb-4 hidden gap-2 overflow-x-auto pb-1 sm:flex">
                {STATUSES.map(([key, label]) => (
                    <button key={key} type="button" aria-pressed={status === key} onClick={() => (setStatus(key), setPage(1))}
                        className={cn('min-h-9 whitespace-nowrap rounded-lg border px-4 text-sm font-medium',
                            status === key ? 'border-navy-800 bg-navy-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-navy-800')}>
                        {t(label)}
                    </button>
                ))}
            </div>

            {(message || list.error) && (
                <div role="alert" className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{message ?? list.error?.message}</div>
            )}

            <DataTable
                caption={t('res.title')}
                loading={list.loading && !list.data}
                rows={list.data?.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState icon={CalendarDays} title={t('res.index.empty')} />}
                columns={[
                    { key: 'number', header: t('res.confirmation'), cell: (r) => <Link href={`/reservations/${r.id}`} className="font-mono text-navy-600 underline underline-offset-2">{r.number}</Link> },
                    { key: 'guest', header: t('res.guest'), cell: (r) => <>{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '-'}{r.guest?.vip && <Star className="ml-1 inline h-4 w-4 text-amber-600" aria-label="VIP" />}</> },
                    { key: 'room', header: t('res.room'), cell: (r) => r.room?.number ?? <span className="text-slate-500">{r.room_type?.code}</span> },
                    { key: 'in', header: t('res.check_in'), cell: (r) => formatDate(r.arrival) },
                    { key: 'out', header: t('res.check_out'), cell: (r) => formatDate(r.departure) },
                    { key: 'n', header: t('res.nights'), align: 'right', cell: (r) => nights(r.arrival, r.departure) },
                    { key: 'amt', header: t('res.amount'), align: 'right', cell: (r) => <span className="tabular-nums">{formatCurrency(r.total_amount)}</span> },
                    { key: 'status', header: t('res.status'), cell: (r) => <StatusBadge status={r.status} label={t(`res.status.${r.status}`)} /> },
                    { key: 'src', header: t('res.source'), cell: (r) => <span className="text-slate-600">{t(`res.channel.${r.channel}`) === `res.channel.${r.channel}` ? r.channel : t(`res.channel.${r.channel}`)}</span> },
                    { key: 'act', header: '', hideLabel: true, align: 'right', cell: (r) => busy === r.id ? <Loader2 className="inline h-4 w-4 animate-spin" aria-label="..." /> : (
                        <span className="btn-row justify-end">
                            {['confirmed', 'tentative'].includes(r.status) && (
                                <>
                                    <button type="button" className="btn-secondary btn-sm" onClick={() => act(r, 'check-in')}>{t('res.do_check_in')}</button>
                                    <button type="button" className="btn-ghost btn-sm text-red-700" onClick={() => setCancelling({ id: r.id, reason: '' })}>{t('res.do_cancel')}</button>
                                </>
                            )}
                            {r.status === 'checked_in' && <button type="button" className="btn-secondary btn-sm" onClick={() => act(r, 'check-out')}>{t('res.do_check_out')}</button>}
                        </span>
                    ) },
                ]}
            />

            <Modal open={!!cancelling} onClose={() => setCancelling(null)} title={t('res.do_cancel')}
                footer={<>
                    <Button variant="secondary" onClick={() => setCancelling(null)}>{t('res.keep')}</Button>
                    <Button variant="danger" disabled={!cancelling?.reason.trim()} onClick={() => { const r = list.data?.data.find((x) => x.id === cancelling?.id); if (r && cancelling) act(r, 'cancel', cancelling.reason.trim()); }}>{t('res.confirm')}</Button>
                </>}>
                <Field label={t('res.cancel_reason')} required>
                    <input className="input" data-autofocus value={cancelling?.reason ?? ''} onChange={(e) => cancelling && setCancelling({ id: cancelling.id, reason: e.target.value })} />
                </Field>
            </Modal>

            {list.data && list.data.last_page > 1 && (
                <div className="flex items-center justify-between mt-4 text-sm text-slate-500">
                    <span>{t('res.index.count', { count: list.data.total })}</span>
                    <div className="flex items-center gap-2">
                        <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                        <span className="px-2">{page} / {list.data.last_page}</span>
                        <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
