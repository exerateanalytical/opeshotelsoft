import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, CalendarDays, Loader2, Star, ExternalLink, LogIn, LogOut, XCircle, Copy, BedDouble, MessageSquare } from 'lucide-react';
import Button from '@/Components/ui/Button';
import DataTable, { type RowAction } from '@/Components/ui/DataTable';
import MasterDetail, { useSplitView } from '@/Components/ui/MasterDetail';
import { toast } from '@/Components/ui/Toast';
import { useQueryParam } from '@/lib/desktop';
import { EmptyAction, EmptyFor, NoResults } from '@/Components/ui/EmptyStates';
import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';
import StatusBadge from './StatusBadge';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import ExportButtons from '@/Components/ExportButtons';
import BulkAssignRoomModal, { type AssignRow } from '@/Components/gapg/BulkAssignRoomModal';
import BulkMessageModal, { type MessageRow } from '@/Components/gapg/BulkMessageModal';

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
    const split = useSplitView();
    const [selId, setSelId] = useQueryParam('selected');
    const current = list.data?.data.find((r) => String(r.id) === selId) ?? null;
    const guestName = (r: Reservation) => (r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '-');
    const open = (r: Reservation) => (split ? setSelId(String(r.id)) : router.visit(`/reservations/${r.id}`));
    const rowActions = (r: Reservation): RowAction<Reservation>[] => [
        { key: 'open', label: t('desktop.detail.open_page'), icon: ExternalLink, onSelect: (x) => router.visit(`/reservations/${x.id}`) },
        { key: 'in', label: t('res.do_check_in'), icon: LogIn, hidden: !['confirmed', 'tentative'].includes(r.status), onSelect: (x) => act(x, 'check-in') },
        { key: 'out', label: t('res.do_check_out'), icon: LogOut, hidden: r.status !== 'checked_in', onSelect: (x) => act(x, 'check-out') },
        { key: 'cancel', label: t('res.do_cancel'), icon: XCircle, danger: true, hidden: !['confirmed', 'tentative'].includes(r.status), onSelect: (x) => setCancelling({ id: x.id, reason: '' }) },
    ];
    const copyNumbers = (rs: Reservation[]) => {
        navigator.clipboard?.writeText(rs.map((r) => r.number).join('\n'))
            .then(() => toast.success(t('desktop.copy.done', { count: rs.length })))
            .catch(() => toast.error(t('desktop.copy.failed')));
    };

    const can = useCan();
    const [bulkBusy, setBulkBusy] = useState(false);
    const [assignRows, setAssignRows] = useState<AssignRow[] | null>(null);
    const [messageRows, setMessageRows] = useState<MessageRow[] | null>(null);
    const [bulkCancel, setBulkCancel] = useState<{ rows: Reservation[]; reason: string; clear: () => void } | null>(null);
    async function bulk(rows: Reservation[], action: 'check-in' | 'cancel', reason?: string, clear?: () => void) {
        setBulkBusy(true);
        setMessage(null);
        let ok = 0;
        const failed: string[] = [];
        for (const r of rows) {
            try {
                await api(`reservations/${r.id}/${action}`, { method: 'POST', body: JSON.stringify(action === 'cancel' ? { reason } : {}) });
                ok++;
            } catch (e) {
                failed.push(`${r.number}: ${e instanceof ApiError ? e.message : String(e)}`);
            }
        }
        setBulkBusy(false);
        setBulkCancel(null);
        clear?.();
        list.reload();
        if (ok) toast.success(t('gape.bulk.done', { count: ok }));
        if (failed.length) setMessage(t('gape.bulk.failed', { count: failed.length }) + ' ' + failed.join('; '));
    }

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
            actions={<div className="btn-row"><Link href="/reservations/tape-chart" className="btn-secondary hidden sm:inline-flex">{t('res.tape.short')}</Link><Link href="/reservations/waitlist" className="btn-secondary hidden sm:inline-flex">{t('res.waitlist.title')}</Link><Link href="/reservations/create" data-primary-action className="btn-primary btn-lg w-full sm:w-auto"><Plus className="w-4 h-4" aria-hidden="true" /> {t('res.create.title')}</Link></div>}
        >
            <Head title={t('res.title')} />

            <div className="mb-3 grid grid-cols-2 gap-2 sm:hidden">
                <Link href="/reservations/tape-chart" className="btn-secondary">{t('res.tape.short')}</Link>
                <Link href="/reservations/waitlist" className="btn-secondary">{t('res.waitlist.title')}</Link>
            </div>

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
                        className={cn('min-h-11 whitespace-nowrap rounded-lg border px-4 text-sm font-medium',
                            status === key ? 'border-navy-800 bg-navy-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-navy-800')}>
                        {t(label)}
                    </button>
                ))}
            </div>

            {(message || (list.error && list.data)) && (
                <div role="alert" className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{message ?? list.error?.message}</div>
            )}

            <MasterDetail
                open={!!current}
                title={current ? `${current.number} · ${guestName(current)}` : ''}
                onClose={() => setSelId(null)}
                detail={current && (
                    <div className="space-y-4">
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-slate-500">{t('res.status')}</dt><dd><StatusBadge status={current.status} label={t(`res.status.${current.status}`)} /></dd>
                            <dt className="text-slate-500">{t('res.guest')}</dt><dd>{guestName(current)}{current.guest?.vip && <Star className="ml-1 inline h-4 w-4 text-amber-600" aria-label="VIP" />}</dd>
                            <dt className="text-slate-500">{t('res.room')}</dt><dd>{current.room?.number ?? current.room_type?.code ?? '-'}</dd>
                            <dt className="text-slate-500">{t('res.check_in')}</dt><dd>{formatDate(current.arrival)}</dd>
                            <dt className="text-slate-500">{t('res.check_out')}</dt><dd>{formatDate(current.departure)} <span className="text-slate-500">({t('desktop.res.nights', { count: nights(current.arrival, current.departure) })})</span></dd>
                            <dt className="text-slate-500">{t('res.source')}</dt><dd>{t(`res.channel.${current.channel}`) === `res.channel.${current.channel}` ? current.channel : t(`res.channel.${current.channel}`)}</dd>
                            <dt className="text-slate-500">{t('desktop.res.total')}</dt><dd className="font-semibold tabular-nums">{formatCurrency(current.total_amount)}</dd>
                        </dl>
                        <div className="btn-row">
                            {['confirmed', 'tentative'].includes(current.status) && <Button size="sm" variant="primary" onClick={() => act(current, 'check-in')}>{t('res.do_check_in')}</Button>}
                            {current.status === 'checked_in' && <Button size="sm" variant="primary" onClick={() => act(current, 'check-out')}>{t('res.do_check_out')}</Button>}
                            {['confirmed', 'tentative'].includes(current.status) && <Button size="sm" variant="secondary" onClick={() => setCancelling({ id: current.id, reason: '' })}>{t('res.do_cancel')}</Button>}
                            <Link href={`/reservations/${current.id}`} className="btn-secondary btn-sm"><ExternalLink className="h-4 w-4" aria-hidden="true" /> {t('desktop.detail.open_page')}</Link>
                        </div>
                        <p className="text-sm text-slate-500">{t('desktop.detail.hint')}</p>
                    </div>
                )}
            >
            <DataTable
                caption={t('res.title')}
                tableKey="reservations"
                loading={list.loading && !list.data}
                error={list.error && !list.data ? list.error.message : undefined}
                rows={list.data?.data ?? []}
                rowKey={(r) => r.id}
                rowLabel={(r) => r.number}
                activeKey={current?.id ?? null}
                onRowClick={open}
                selectable
                bulkActions={({ rows, clear }) => {
                    const arriving = rows.filter((r) => ['confirmed', 'tentative'].includes(r.status));
                    const assignable = arriving; // in-house guests change rooms via move-room, not assign
                    return (<>
                        <Button size="sm" variant="secondary" icon={Copy} onClick={() => { copyNumbers(rows); clear(); }}>{t('desktop.copy.numbers')}</Button>
                        {can('frontdesk.manage', 'reservations.manage') && arriving.length > 0 && <Button size="sm" variant="secondary" icon={LogIn} loading={bulkBusy} onClick={() => bulk(arriving, 'check-in', undefined, clear)}>{t('gape.bulk.check_in', { count: arriving.length })}</Button>}
                        {can('reservations.manage') && assignable.length > 0 && <Button size="sm" variant="secondary" icon={BedDouble} onClick={() => { setAssignRows(assignable.map((r) => ({ id: r.id, number: r.number, guest: guestName(r), roomType: r.room_type?.code ?? '', arrival: r.arrival, departure: r.departure, currentRoom: r.room?.number ?? null }))); clear(); }}>{t('gapg.assign.button', { count: assignable.length })}</Button>}
                        {can('messaging.reply', 'messaging.manage') && <Button size="sm" variant="secondary" icon={MessageSquare} onClick={() => { setMessageRows(rows.slice(0, 100).map((r) => ({ id: r.id, number: r.number, guest: guestName(r) }))); clear(); }}>{t('gapg.msg.button', { count: Math.min(rows.length, 100) })}</Button>}
                        {can('reservations.manage') && arriving.length > 0 && <Button size="sm" variant="danger" icon={XCircle} disabled={bulkBusy} onClick={() => setBulkCancel({ rows: arriving, reason: '', clear })}>{t('gape.bulk.cancel', { count: arriving.length })}</Button>}
                    </>);
                }}
                rowActions={rowActions}
                empty={(status || q.trim() || from)
                    ? <NoResults onReset={() => { setStatus(''); setQ(''); setFrom(''); setPage(1); }} />
                    : <EmptyFor kind="reservations" action={<EmptyAction kind="reservations" href="/reservations/create" />} />}
                columns={[
                    { key: 'number', header: t('res.confirmation'), sortValue: (r) => r.number, cell: (r) => <Link href={`/reservations/${r.id}`} className="inline-flex min-h-11 items-center font-mono text-navy-600 underline underline-offset-2">{r.number}</Link> },
                    { key: 'guest', header: t('res.guest'), sortValue: (r) => guestName(r), cell: (r) => <>{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '-'}{r.guest?.vip && <Star className="ml-1 inline h-4 w-4 text-amber-600" aria-label="VIP" />}</> },
                    { key: 'room', header: t('res.room'), cell: (r) => r.room?.number ?? <span className="text-slate-500">{r.room_type?.code}</span> },
                    { key: 'in', header: t('res.check_in'), sortValue: (r) => r.arrival, cell: (r) => formatDate(r.arrival) },
                    { key: 'out', header: t('res.check_out'), sortValue: (r) => r.departure, cell: (r) => formatDate(r.departure) },
                    { key: 'n', header: t('res.nights'), align: 'right', defaultHidden: false, cell: (r) => nights(r.arrival, r.departure) },
                    { key: 'amt', header: t('res.amount'), align: 'right', sortValue: (r) => r.total_amount, cell: (r) => <span className="tabular-nums">{formatCurrency(r.total_amount)}</span> },
                    { key: 'status', header: t('res.status'), sortValue: (r) => r.status, cell: (r) => <StatusBadge status={r.status} label={t(`res.status.${r.status}`)} /> },
                    { key: 'src', header: t('res.source'), cell: (r) => <span className="text-slate-600">{t(`res.channel.${r.channel}`) === `res.channel.${r.channel}` ? r.channel : t(`res.channel.${r.channel}`)}</span> },
                    { key: 'act', header: '', hideLabel: true, align: 'right', required: true, cell: (r) => busy === r.id ? <Loader2 className="inline h-4 w-4 animate-spin" aria-label="..." /> : (
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
            </MasterDetail>

            <Modal open={!!cancelling} onClose={() => setCancelling(null)} title={t('res.do_cancel')}
                footer={<>
                    <Button variant="secondary" onClick={() => setCancelling(null)}>{t('res.keep')}</Button>
                    <Button variant="danger" disabled={!cancelling?.reason.trim()} onClick={() => { const r = list.data?.data.find((x) => x.id === cancelling?.id); if (r && cancelling) act(r, 'cancel', cancelling.reason.trim()); }}>{t('res.confirm')}</Button>
                </>}>
                <Field label={t('res.cancel_reason')} required>
                    <input className="input" data-autofocus value={cancelling?.reason ?? ''} onChange={(e) => cancelling && setCancelling({ id: cancelling.id, reason: e.target.value })} />
                </Field>
            </Modal>

            <Modal open={!!bulkCancel} onClose={() => setBulkCancel(null)} title={t('gape.bulk.cancel', { count: bulkCancel?.rows.length ?? 0 })}
                footer={<>
                    <Button variant="secondary" onClick={() => setBulkCancel(null)}>{t('res.keep')}</Button>
                    <Button variant="danger" loading={bulkBusy} disabled={!bulkCancel?.reason.trim()} onClick={() => bulkCancel && bulk(bulkCancel.rows, 'cancel', bulkCancel.reason.trim(), bulkCancel.clear)}>{t('res.confirm')}</Button>
                </>}>
                <p className="mb-3 text-sm text-slate-600">{bulkCancel?.rows.map((r) => r.number).join(', ')}</p>
                <Field label={t('res.cancel_reason')} required>
                    <input className="input" data-autofocus value={bulkCancel?.reason ?? ''} onChange={(e) => bulkCancel && setBulkCancel({ ...bulkCancel, reason: e.target.value })} />
                </Field>
            </Modal>

            <BulkAssignRoomModal rows={assignRows} onClose={() => setAssignRows(null)} onDone={() => list.reload()} />
            <BulkMessageModal rows={messageRows} onClose={() => setMessageRows(null)} />

            <section className="mt-4 flex flex-wrap items-center gap-2" aria-label={t('gape.export.label')}>
                <span className="text-sm text-slate-600">{t('gape.export.label')}</span>
                <ExportButtons report="reservations" params={{ status, q, arriving: from, per_page: 200 }} />
            </section>

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
