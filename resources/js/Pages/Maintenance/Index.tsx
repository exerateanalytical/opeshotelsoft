import Tabs from '@/Components/ui/Tabs';
import { EmptyFor, EmptyAction } from '@/Components/ui/EmptyStates';
import ExportButtons from '@/Components/ExportButtons';
import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';
import { useIsDesktop } from '@/lib/useDevice';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Plus, Search, Loader2, Wrench, CalendarClock, Play, CheckCircle2, UserPlus, X, Trash2, Pause, Ban, Pencil } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Page<T> { data: T[]; total: number; current_page: number; last_page: number; per_page?: number }
interface Room { id: number; number: string }
interface Asset { id: number; name: string; tag: string | null; room_id: number | null }
interface StockItem { id: number; name: string; sku?: string; unit?: string }
interface User { id: number; name: string }
interface Staff { id: number; name: string; roles?: string[] }
interface WorkOrder {
    id: number; number: string; title: string; description: string | null; category: string; priority: string; status: string;
    room_id: number | null; asset_id: number | null; room: Room | null; room_out_of_order: boolean;
    asset?: { id: number; tag: string | null; name: string } | null; assignee?: User | null;
    labour_cost?: number; hold_reason?: string | null; cancel_reason?: string | null;
    reported_by: number | null; assigned_to: number | null; pm_schedule_id: number | null;
    minutes_spent: number; parts_cost: number; due_at: string | null; completed_at: string | null; resolution: string | null; created_at: string;
}
interface Schedule { id: number; title: string; asset_id: number | null; category: string; every_days: number; next_due: string; active: boolean }

const STATUSES = ['open', 'assigned', 'in_progress', 'on_hold', 'done', 'cancelled'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const CATEGORIES = ['electrical', 'plumbing', 'hvac', 'furniture', 'it', 'kitchen', 'general'];
const STATUS_BADGE: Record<string, string> = {
    open: 'bg-amber-50 text-amber-700', assigned: 'bg-blue-50 text-blue-700', in_progress: 'bg-indigo-50 text-indigo-700',
    on_hold: 'bg-slate-100 text-slate-600', done: 'bg-emerald-50 text-emerald-700', cancelled: 'bg-red-50 text-red-700',
};
const PRIO_BADGE: Record<string, string> = {
    low: 'bg-slate-100 text-slate-600', normal: 'bg-blue-50 text-blue-700', high: 'bg-amber-50 text-amber-700', urgent: 'bg-red-50 text-red-700',
};
const label = (s: string) => s.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

function Badge({ cls, text }: { cls?: string; text: string }) {
    const t = useT();
    const tl = (s: string) => { const k = `maint.v.${s}`; const v = t(k); return v === k ? label(s) : v; };
    return <span className={cn('inline-block rounded px-2 py-0.5 text-xs font-medium', cls)}>{tl(text)}</span>;
}

export default function MaintenanceIndex() {
    const t = useT();
    const wide = useIsDesktop();
    const tl = (s: string) => { const k = `maint.v.${s}`; const v = t(k); return v === k ? label(s) : v; };
    const [tab, setTab] = useState<'orders' | 'schedules'>('orders');
    const [status, setStatus] = useState('');
    const [priority, setPriority] = useState('');
    const [selected, setSelected] = useState<number | null>(null);
    const [showCreate, setShowCreate] = useState(false);
    const [showSchedule, setShowSchedule] = useState(false);
    const [assetId, setAssetId] = useState<number | null>(null);
    const assetDetail = useApi<Asset & { status?: string; category?: string; location?: string | null; purchased_on?: string | null }>(assetId ? `assets/${assetId}` : null, [assetId]);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    const [editing, setEditing] = useState<number | null>(null);

    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const qs = new URLSearchParams({ page: String(page), per_page: String(perPage) });
    if (status) qs.set('status', status);
    if (priority) qs.set('priority', priority);
    if (q.trim()) qs.set('q', q.trim());
    const orders = useApi<Page<WorkOrder>>(`maintenance/work-orders?${qs.toString()}`);
    const schedules = useApi<Schedule[]>('maintenance/schedules');
    const rooms = useApi<Page<Room>>('rooms?per_page=500&sort=number');
    const assets = useApi<Page<Asset>>('assets?per_page=500&sort=name');
    const users = useApi<Page<User>>('users');
    const staff = useApi<Staff[]>('staff?role=maintenance-technician');
    const techs: Staff[] | null = staff.error ? null : Array.isArray(staff.data) ? staff.data : null;

    const roomList = rooms.data?.data ?? [];
    const assetList = assets.data?.data ?? [];
    const assetName = (id: number | null) => (id ? assetList.find((a) => a.id === id)?.name ?? `#${id}` : '');
    const userName = (id: number | null) => (id ? users.data?.data.find((u) => u.id === id)?.name ?? t('maint.user_no', { id }) : '—');

    const list = orders.data?.data ?? [];

    const run = async (fn: () => Promise<unknown>, ok: string) => {
        setError(null); setSuccess(null);
        try {
            await fn();
            setSuccess(ok);
            orders.reload(); schedules.reload();
            return true;
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
            return false;
        }
    };

    const runDue = () => {
        setError(null); setSuccess(null);
        api<{ created: number }>('maintenance/schedules/run', { method: 'POST', body: '{}' })
            .then((r) => { setSuccess(t('maint.pm_created', { count: r.created })); orders.reload(); schedules.reload(); })
            .catch((e) => setError(e instanceof ApiError ? e.message : String(e)));
    };

    return (
        <AppLayout
            title={t('maint.title')}
            subtitle={t('maint.subtitle')}
            actions={
                <div className="flex flex-wrap gap-2">
                    <button className="btn-secondary" onClick={runDue}><CalendarClock className="w-4 h-4 mr-1 inline" />{t('maint.run_due')}</button>
                    <button className="btn-primary" onClick={() => (tab === 'orders' ? setShowCreate(true) : setShowSchedule(true))}>
                        <Plus className="w-4 h-4 mr-1 inline" />{tab === 'orders' ? t('maint.new_wo') : t('maint.new_schedule')}
                    </button>
                </div>
            }
        >
            <Head title={t('maint.title')} />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            {success && <Alert variant="success" className="mb-4">{success}</Alert>}

            <Tabs label={t('maint.title')} className="mb-4" value={tab} onChange={(k) => setTab(k as typeof tab)}
                tabs={[{ key: 'orders', label: t('maint.tab.orders') }, { key: 'schedules', label: t('maint.tab.schedules') }]} />

            {tab === 'orders' && (
                <>
                    {showCreate && (
                        <CreateOrder rooms={roomList} assets={assetList} techs={techs} onClose={() => setShowCreate(false)}
                            onSubmit={(body) => run(() => api('maintenance/work-orders', { method: 'POST', body: JSON.stringify(body) }), t('maint.wo_created')).then((ok) => ok && setShowCreate(false))} />
                    )}
                    <FilterBar
                        activeCount={[status, priority].filter(Boolean).length}
                        onClear={() => { setStatus(''); setPriority(''); setPage(1); }}
                        search={
                            <div className="relative min-w-0 flex-1 sm:w-72 sm:flex-none">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                                <input type="search" className="input pl-9" aria-label={t('maint.search')} placeholder={t('maint.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                            </div>
                        }
                    >
                        <Field label={t('guests.status')}>
                            <select className="select sm:w-48" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                                <option value="">{t('hk.all_statuses')}</option>
                                {STATUSES.map((s) => <option key={s} value={s}>{tl(s)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('maint.priority')}>
                            <select className="select sm:w-48" value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1); }}>
                                <option value="">{t('maint.all_priorities')}</option>
                                {PRIORITIES.map((s) => <option key={s} value={s}>{tl(s)}</option>)}
                            </select>
                        </Field>
                    </FilterBar>
                    <ExportButtons report="work-orders" params={{ status, priority, q, per_page: 200 }} className="mb-3" />
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className={cn('card overflow-hidden', selected && wide ? 'lg:col-span-2' : 'lg:col-span-3')}>
                            {orders.loading ? <Loader2 className="w-5 h-5 animate-spin m-6 text-slate-500" /> : orders.error ? (
                                <p className="p-4 text-sm text-red-700">{orders.error.message}</p>
                            ) : list.length === 0 ? (
                                <EmptyFor kind="maintenance" action={<EmptyAction kind="maintenance" onClick={() => setShowCreate(true)} />} />
                            ) : (
                                <div className="table-wrap"><table className="table-auto">
                                    <thead><tr>
                                        <th>#</th><th>{t('maint.f.title')}</th><th>{t('hk.lf.location')}</th><th>{t('maint.priority')}</th>
                                        <th>{t('guests.status')}</th><th>{t('hk.assigned')}</th><th>{t('maint.opened')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {list.map((w) => (
                                            <tr key={w.id} onClick={() => setSelected(w.id)}
                                                className={cn('cursor-pointer hover:bg-slate-50', selected === w.id && 'bg-navy-50')}>
                                                <td className="font-mono text-sm"><button type="button" className="min-h-9 text-navy-600 underline underline-offset-2" onClick={() => setSelected(w.id)}>{w.number}</button></td>
                                                <td>{w.title}{w.room_out_of_order && <span className="ml-1 text-xs font-medium text-red-700">{t('maint.ooo')}</span>}</td>
                                                <td>{w.room ? t('hk.room_no', { number: w.room.number }) : w.asset_id ? <button type="button" className="hit-44 text-navy-600 underline underline-offset-2" onClick={(e) => { e.stopPropagation(); setAssetId(w.asset_id); }}>{assetName(w.asset_id) || '—'}</button> : '—'}</td>
                                                <td><Badge cls={PRIO_BADGE[w.priority]} text={w.priority} /></td>
                                                <td><Badge cls={STATUS_BADGE[w.status]} text={w.status} /></td>
                                                <td>{w.assignee?.name ?? userName(w.assigned_to)}</td>
                                                <td className="whitespace-nowrap">{formatDate(w.created_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table></div>
                            )}
                            {orders.data && orders.data.total > 0 && (
                                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 p-2 text-xs text-slate-500">
                                    <span>{t('maint.pager', { page: orders.data.current_page, last: orders.data.last_page, count: orders.data.total })}</span>
                                    <div className="flex items-center gap-2">
                                        <select className="select w-auto" value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }}>
                                            {[25, 50, 100, 200].map((n) => <option key={n} value={n}>{t('maint.per_page', { count: n })}</option>)}
                                        </select>
                                        <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                                        <button className="btn-secondary" disabled={page >= orders.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                                    </div>
                                </div>
                            )}
                        </div>
                        {selected && wide && (
                            <Detail key={selected} id={selected} techs={techs} assetName={assetName} userName={userName}
                                onClose={() => setSelected(null)} run={run} />
                        )}
                        {selected && !wide && (
                            <Modal open size="lg" title={t('maint.title')} onClose={() => setSelected(null)}>
                                <Detail key={selected} embedded id={selected} techs={techs} assetName={assetName} userName={userName}
                                    onClose={() => setSelected(null)} run={run} />
                            </Modal>
                        )}
                    </div>
                </>
            )}

            {tab === 'schedules' && (
                <>
                    {showSchedule && (
                        <CreateSchedule assets={assetList} onClose={() => setShowSchedule(false)}
                            onSubmit={(body) => run(() => api('maintenance/schedules', { method: 'POST', body: JSON.stringify(body) }), t('maint.sched_created')).then((ok) => ok && setShowSchedule(false))} />
                    )}
                    <div className="card overflow-x-auto">
                        {schedules.loading ? <Loader2 className="w-5 h-5 animate-spin m-6 text-slate-500" /> : schedules.error ? (
                            <p className="p-4 text-sm text-red-700">{schedules.error.message}</p>
                        ) : (schedules.data ?? []).length === 0 ? (
                            <p className="p-8 text-center text-sm text-slate-500">{t('maint.no_schedules')}</p>
                        ) : (
                            <div className="table-wrap"><table className="table-auto">
                                <thead><tr>
                                    <th>{t('maint.f.title')}</th><th>{t('maint.asset')}</th><th>{t('maint.category')}</th><th>{t('maint.every')}</th><th>{t('maint.next_due')}</th><th>{t('guests.status.active')}</th><th></th>
                                </tr></thead>
                                <tbody>
                                    {schedules.data!.map((s) => {
                                        if (editing === s.id) {
                                            return (
                                                <tr key={s.id}><td colSpan={7}>
                                                    <CreateSchedule assets={assetList} initial={s} onClose={() => setEditing(null)}
                                                        onSubmit={(body) => run(() => api(`maintenance/schedules/${s.id}`, { method: 'PATCH', body: JSON.stringify(body) }), t('maint.sched_updated')).then((ok) => ok && setEditing(null))} />
                                                </td></tr>
                                            );
                                        }
                                        const overdue = s.next_due.slice(0, 10) <= new Date().toISOString().slice(0, 10);
                                        return (
                                            <tr key={s.id}>
                                                <td>{s.title}</td>
                                                <td>{assetName(s.asset_id) || '—'}</td>
                                                <td>{tl(s.category)}</td>
                                                <td>{t('maint.days', { count: s.every_days })}</td>
                                                <td className={cn('p-2 whitespace-nowrap', overdue && 'text-amber-700 font-medium')}>{formatDate(s.next_due)}{overdue && ` ${t('maint.due_suffix')}`}</td>
                                                <td>
                                                    <label className="min-h-11 flex items-center gap-1"><input type="checkbox" checked={s.active}
                                                        onChange={(e) => run(() => api(`maintenance/schedules/${s.id}`, { method: 'PATCH', body: JSON.stringify({ active: e.target.checked }) }), e.target.checked ? t('maint.sched_activated') : t('maint.sched_paused'))} />{s.active ? t('guests.yes') : t('guests.no')}</label>
                                                </td>
                                                <td><button type="button" title={t('common.edit')} aria-label={t('common.edit')} className="btn-ghost btn-icon" onClick={() => setEditing(s.id)}><Pencil className="w-4 h-4" aria-hidden="true" /></button></td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table></div>
                        )}
                    </div>
                </>
            )}
            <Modal open={assetId !== null} onClose={() => setAssetId(null)} title={assetDetail.data?.name ?? t('maint.asset')}>
                {assetDetail.error && <p className="text-sm text-red-700">{assetDetail.error.message}</p>}
                {assetDetail.data && (
                    <dl className="grid grid-cols-2 gap-2 text-sm">
                        <div><dt className="text-slate-500">{t('gapf.maint.asset_tag')}</dt><dd>{assetDetail.data.tag ?? '—'}</dd></div>
                        <div><dt className="text-slate-500">{t('guests.status')}</dt><dd>{assetDetail.data.status ?? '—'}</dd></div>
                        <div><dt className="text-slate-500">{t('maint.category')}</dt><dd>{assetDetail.data.category ?? '—'}</dd></div>
                        <div><dt className="text-slate-500">{t('hk.lf.location')}</dt><dd>{assetDetail.data.location ?? '—'}</dd></div>
                    </dl>
                )}
            </Modal>
        </AppLayout>
    );
}

function TechPicker({ techs, value, onChange, className, placeholder }: { techs: Staff[] | null; value: string; onChange: (v: string) => void; className: string; placeholder: string }) {
    const t = useT();
    if (!techs) return <input className={className} type="number" aria-label={t('maint.user_id_ph', { label: placeholder })} placeholder={t('maint.user_id_ph', { label: placeholder })} value={value} onChange={(e) => onChange(e.target.value)} />;
    return (
        <select className={className.replace(/\binput\b/, 'select')} value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">{placeholder}</option>
            {techs.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
        </select>
    );
}

function CreateOrder({ rooms, assets, techs, onClose, onSubmit }: {
    rooms: Room[]; assets: Asset[]; techs: Staff[] | null; onClose: () => void; onSubmit: (b: Record<string, unknown>) => void;
}) {
    const t = useT();
    const tl = (s: string) => { const k = `maint.v.${s}`; const v = t(k); return v === k ? label(s) : v; };
    const [f, setF] = useState({ title: '', description: '', target: 'room', room_id: '', asset_id: '', category: 'general', priority: 'normal', room_out_of_order: false, out_of_order_until: '', assigned_to: '', due_at: '' });
    const set = (k: keyof typeof f, v: string | boolean) => setF((p) => ({ ...p, [k]: v }));
    const [busy, setBusy] = useState(false);
    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        const asset = assets.find((a) => String(a.id) === f.asset_id);
        await onSubmit({
            title: f.title, description: f.description || null, category: f.category, priority: f.priority,
            room_id: f.target === 'room' ? (f.room_id ? Number(f.room_id) : null) : asset?.room_id ?? null,
            asset_id: f.target === 'asset' && f.asset_id ? Number(f.asset_id) : null,
            room_out_of_order: f.target === 'room' && !!f.room_id && f.room_out_of_order,
            out_of_order_until: f.room_out_of_order && f.out_of_order_until ? f.out_of_order_until : null,
            assigned_to: f.assigned_to ? Number(f.assigned_to) : null, due_at: f.due_at || null,
        });
        setBusy(false);
    };
    return (
        <Modal open size="lg" title={t('maint.new_wo')} onClose={onClose}>
        <form onSubmit={submit} className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <input className="input sm:col-span-2" aria-label={t('maint.f.title')} placeholder={t('maint.f.title')} required maxLength={150} value={f.title} onChange={(e) => set('title', e.target.value)} />
                <textarea className="input sm:col-span-2" rows={2} aria-label={t('loyalty.members.description')} placeholder={t('loyalty.members.description')} value={f.description} onChange={(e) => set('description', e.target.value)} />
                <div className="flex gap-3 text-sm items-center sm:col-span-2">
                    <label className="flex min-h-11 items-center gap-2"><input type="radio" checked={f.target === 'room'} onChange={() => set('target', 'room')} /> {t('guests.show.room')}</label>
                    <label className="flex min-h-11 items-center gap-2"><input type="radio" checked={f.target === 'asset'} onChange={() => set('target', 'asset')} /> {t('maint.asset')}</label>
                </div>
                {f.target === 'room' ? (
                    <select className="select" value={f.room_id} onChange={(e) => set('room_id', e.target.value)} aria-label={t('maint.no_room')}>
                        <option value="">{t('maint.no_room')}</option>
                        {rooms.map((r) => <option key={r.id} value={r.id}>{t('hk.room_no', { number: r.number })}</option>)}
                    </select>
                ) : (
                    <select className="select" value={f.asset_id} required onChange={(e) => set('asset_id', e.target.value)} aria-label={t('maint.select_asset')}>
                        <option value="">{t('maint.select_asset')}</option>
                        {assets.map((a) => <option key={a.id} value={a.id}>{a.name}{a.tag ? ` (${a.tag})` : ''}</option>)}
                    </select>
                )}
                <select className="select" value={f.category} onChange={(e) => set('category', e.target.value)}>
                    {CATEGORIES.map((c) => <option key={c} value={c}>{tl(c)}</option>)}
                </select>
                <select className="select" value={f.priority} onChange={(e) => set('priority', e.target.value)}>
                    {PRIORITIES.map((c) => <option key={c} value={c}>{tl(c)}</option>)}
                </select>
                <TechPicker techs={techs} className="input" placeholder={t('hk.unassigned')} value={f.assigned_to} onChange={(v) => set('assigned_to', v)} />
                <label className="label">{t('maint.due')}<input className="input" type="datetime-local" value={f.due_at} onChange={(e) => set('due_at', e.target.value)} /></label>
                {f.target === 'room' && f.room_id && (
                    <Alert variant="warning" className="sm:col-span-2"><div className="space-y-2">
                        <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={f.room_out_of_order} onChange={(e) => set('room_out_of_order', e.target.checked)} />{t('maint.take_ooo')}</label>
                        {f.room_out_of_order && (
                            <label className="label">{t('maint.ooo_until')}<input className="input" type="date" min={new Date().toISOString().slice(0, 10)} value={f.out_of_order_until} onChange={(e) => set('out_of_order_until', e.target.value)} /></label>
                        )}
                    </div></Alert>
                )}
            </div>
            <div className="flex justify-end gap-2">
                <button type="button" className="btn-secondary" onClick={onClose}>{t('common.cancel')}</button>
                <button className="btn-primary" disabled={busy}>{busy ? t('hk.saving') : t('maint.create')}</button>
            </div>
        </form>
        </Modal>
    );
}

function Detail({ id, techs, assetName, userName, onClose, run, embedded }: {
    embedded?: boolean;
    id: number; techs: Staff[] | null; assetName: (id: number | null) => string; userName: (id: number | null) => string; onClose: () => void;
    run: (fn: () => Promise<unknown>, ok: string) => Promise<boolean>;
}) {
    const t = useT();
    const wo = useApi<WorkOrder>(`maintenance/work-orders/${id}`);
    const items = useApi<Page<StockItem>>('stock/items?per_page=500');
    const [assignee, setAssignee] = useState('');
    const [completing, setCompleting] = useState(false);
    const [resolution, setResolution] = useState('');
    const [minutes, setMinutes] = useState('');
    const [labour, setLabour] = useState('');
    const [reasonFor, setReasonFor] = useState<'hold' | 'cancel' | null>(null);
    const [reason, setReason] = useState('');
    const [parts, setParts] = useState<{ stock_item_id: string; quantity: string }[]>([]);
    const w = wo.data;
    const act = async (path: string, body: object, ok: string) => {
        if (await run(() => api(`maintenance/work-orders/${id}/${path}`, { method: 'POST', body: JSON.stringify(body) }), ok)) {
            wo.reload();
            setCompleting(false);
            setReasonFor(null); setReason('');
        }
    };
    const closed = w && ['done', 'cancelled'].includes(w.status);

    return (
        <div className={cn('space-y-3 text-sm', !embedded && 'card p-4')}>
            <div className="flex items-start justify-between gap-2">
                <div><p className="font-mono text-sm text-slate-600">{w?.number}</p><h3 className="font-semibold">{w?.title ?? t('common.loading')}</h3></div>
                {!embedded && <button type="button" className="btn-ghost btn-icon" aria-label={t('ui.modal.close')} onClick={onClose}><X className="w-4 h-4" aria-hidden="true" /></button>}
            </div>
            {wo.error && <p className="text-red-700">{wo.error.message}</p>}
            {w && (
                <>
                    <div className="flex flex-wrap gap-1"><Badge cls={STATUS_BADGE[w.status]} text={w.status} /><Badge cls={PRIO_BADGE[w.priority]} text={w.priority} /><Badge cls="bg-slate-100 text-slate-600" text={w.category} />
                        {w.room_out_of_order && <span className="rounded bg-red-50 px-2 py-0.5 text-xs text-red-700">{t('maint.room_ooo')}</span>}</div>
                    {w.description && <p className="text-slate-600 whitespace-pre-line">{w.description}</p>}
                    <dl className="grid grid-cols-2 gap-x-2 gap-y-1">
                        <dt className="text-slate-500">{t('guests.show.room')}</dt><dd>{w.room?.number ?? '—'}</dd>
                        <dt className="text-slate-500">{t('maint.asset')}</dt><dd>{w.asset ? `${w.asset.name}${w.asset.tag ? ` (${w.asset.tag})` : ''}` : assetName(w.asset_id) || '—'}</dd>
                        <dt className="text-slate-500">{t('hk.assigned')}</dt><dd>{w.assignee?.name ?? userName(w.assigned_to)}</dd>
                        <dt className="text-slate-500">{t('maint.reported_by')}</dt><dd>{userName(w.reported_by)}</dd>
                        <dt className="text-slate-500">{t('maint.due')}</dt><dd>{w.due_at ? formatDateTime(w.due_at) : '—'}</dd>
                        <dt className="text-slate-500">{t('maint.opened')}</dt><dd>{formatDateTime(w.created_at)}</dd>
                        {w.pm_schedule_id && (<><dt className="text-slate-500">{t('maint.source')}</dt><dd>{t('maint.preventive')}</dd></>)}
                        <dt className="text-slate-500">{t('maint.time_spent')}</dt><dd>{t('maint.minutes', { count: w.minutes_spent })}</dd>
                        <dt className="text-slate-500">{t('maint.parts_cost')}</dt><dd>{formatCurrency(w.parts_cost)}</dd>
                        <dt className="text-slate-500">{t('maint.labour')}</dt><dd>{formatCurrency(w.labour_cost ?? 0)}</dd>
                        {w.completed_at && (<><dt className="text-slate-500">{t('maint.completed')}</dt><dd>{formatDateTime(w.completed_at)}</dd></>)}
                    </dl>
                    {w.status === 'on_hold' && w.hold_reason && <div className="rounded bg-slate-100 p-2 text-slate-700"><b>{t('maint.on_hold_label')}</b> {w.hold_reason}</div>}
                    {w.status === 'cancelled' && w.cancel_reason && <div className="rounded bg-red-50 p-2 text-red-800"><b>{t('maint.cancelled_label')}</b> {w.cancel_reason}</div>}
                    {w.resolution && <div className="rounded bg-emerald-50 p-2 text-emerald-800"><b>{t('maint.resolution_label')}</b> {w.resolution}</div>}

                    {!closed && (
                        <div className="space-y-2 border-t border-slate-100 pt-3">
                            <div className="flex gap-2">
                                <TechPicker techs={techs} className="input flex-1" placeholder={t('maint.assign_to')} value={assignee} onChange={setAssignee} />
                                <button className="btn-secondary" disabled={!assignee || w.status === 'on_hold'} onClick={() => act('assign', { user_id: Number(assignee) }, t('maint.wo_assigned'))}><UserPlus className="w-4 h-4" /></button>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {['open', 'assigned'].includes(w.status) && <button className="btn-secondary" onClick={() => act('start', {}, t('maint.wo_started'))}><Play className="w-4 h-4 mr-1 inline" />{t('hk.start')}</button>}
                                {w.status === 'on_hold'
                                    ? <button className="btn-secondary" onClick={() => act('resume', {}, t('maint.wo_resumed'))}><Play className="w-4 h-4 mr-1 inline" />{t('maint.resume')}</button>
                                    : <button className="btn-secondary" onClick={() => setReasonFor(reasonFor === 'hold' ? null : 'hold')}><Pause className="w-4 h-4 mr-1 inline" />{t('maint.hold')}</button>}
                                <button className="btn-secondary" onClick={() => setReasonFor(reasonFor === 'cancel' ? null : 'cancel')}><Ban className="w-4 h-4 mr-1 inline" />{t('common.cancel')}</button>
                                {w.status !== 'on_hold' && <button className="btn-primary" onClick={() => setCompleting(!completing)}><CheckCircle2 className="w-4 h-4 mr-1 inline" />{t('maint.complete')}</button>}
                            </div>
                            {reasonFor && (
                                <form className="flex gap-2 rounded border border-slate-200 p-2" onSubmit={(e) => {
                                    e.preventDefault();
                                    act(reasonFor, { reason }, reasonFor === 'hold' ? t('maint.wo_held') : t('maint.wo_cancelled'));
                                }}>
                                    <input className="input flex-1" required maxLength={255} aria-label={reasonFor === 'hold' ? t('maint.hold_reason') : t('maint.cancel_reason')} placeholder={reasonFor === 'hold' ? t('maint.hold_reason') : t('maint.cancel_reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
                                    <button className="btn-primary">{reasonFor === 'hold' ? t('maint.put_on_hold') : t('maint.cancel_order')}</button>
                                </form>
                            )}
                            {completing && (
                                <form className="space-y-2 rounded border border-slate-200 p-2" onSubmit={(e) => {
                                    e.preventDefault();
                                    act('complete', {
                                        resolution, ...(minutes ? { minutes: Number(minutes) } : {}), ...(labour ? { labour_cost: Math.round(Number(labour)) } : {}),
                                        ...(parts.length ? { parts: parts.filter((p) => p.stock_item_id).map((p) => ({ stock_item_id: Number(p.stock_item_id), quantity: Number(p.quantity) })) } : {}),
                                    }, t('maint.wo_completed'));
                                }}>
                                    <textarea className="input" rows={2} required aria-label={t('maint.resolution_ph')} placeholder={t('maint.resolution_ph')} value={resolution} onChange={(e) => setResolution(e.target.value)} />
                                    <input className="input" type="number" min={0} aria-label={t('maint.minutes_ph')} placeholder={t('maint.minutes_ph')} value={minutes} onChange={(e) => setMinutes(e.target.value)} />
                                    <input className="input" type="number" min={0} step={1} aria-label={t('maint.labour_ph')} placeholder={t('maint.labour_ph')} value={labour} onChange={(e) => setLabour(e.target.value)} />
                                    <p className="text-xs text-slate-500">{t('maint.parts_hint')}</p>
                                    {parts.map((p, i) => (
                                        <div key={i} className="flex gap-1">
                                            <select className="select flex-1" value={p.stock_item_id} onChange={(e) => setParts(parts.map((x, j) => (j === i ? { ...x, stock_item_id: e.target.value } : x)))} aria-label={t('maint.item_ph')}>
                                                <option value="">{t('maint.item_ph')}</option>
                                                {(items.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}{s.unit ? ` (${s.unit})` : ''}</option>)}
                                            </select>
                                            <input className="input w-20" type="number" step="0.001" min="0.001" required value={p.quantity} onChange={(e) => setParts(parts.map((x, j) => (j === i ? { ...x, quantity: e.target.value } : x)))} />
                                            <button className="btn-ghost btn-icon btn-sm" type="button" onClick={() => setParts(parts.filter((_, j) => j !== i))} aria-label={t('ui.remove')}><Trash2 className="w-4 h-4 text-red-500" /></button>
                                        </div>
                                    ))}
                                    <button type="button" className="btn-ghost btn-sm" onClick={() => setParts([...parts, { stock_item_id: '', quantity: '1' }])}>{t('maint.add_part')}</button>
                                    <div className="flex justify-end"><button className="btn-primary">{t('maint.confirm_complete')}</button></div>
                                </form>
                            )}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function CreateSchedule({ assets, initial, onClose, onSubmit }: { assets: Asset[]; initial?: Schedule; onClose: () => void; onSubmit: (b: Record<string, unknown>) => void }) {
    const t = useT();
    const tl = (s: string) => { const k = `maint.v.${s}`; const v = t(k); return v === k ? label(s) : v; };
    const [f, setF] = useState(initial
        ? { title: initial.title, asset_id: initial.asset_id ? String(initial.asset_id) : '', category: initial.category, every_days: String(initial.every_days), next_due: initial.next_due.slice(0, 10) }
        : { title: '', asset_id: '', category: 'general', every_days: '30', next_due: new Date().toISOString().slice(0, 10) });
    const set = (k: keyof typeof f, v: string) => setF((p) => ({ ...p, [k]: v }));
    return (
        <Modal open size="lg" title={initial ? t('maint.edit_pm') : t('maint.new_pm')} onClose={onClose}>
        <form className="space-y-3" onSubmit={(e) => {
            e.preventDefault();
            onSubmit({ title: f.title, asset_id: f.asset_id ? Number(f.asset_id) : null, category: f.category, every_days: Number(f.every_days), next_due: f.next_due });
        }}>
            <div className="grid gap-3 sm:grid-cols-2">
                <input className="input sm:col-span-2" required aria-label={t('maint.pm_title_ph')} placeholder={t('maint.pm_title_ph')} value={f.title} onChange={(e) => set('title', e.target.value)} />
                <select className="select" value={f.asset_id} onChange={(e) => set('asset_id', e.target.value)} aria-label={t('maint.no_asset')}>
                    <option value="">{t('maint.no_asset')}</option>
                    {assets.map((a) => <option key={a.id} value={a.id}>{a.name}{a.tag ? ` (${a.tag})` : ''}</option>)}
                </select>
                <select className="select" value={f.category} onChange={(e) => set('category', e.target.value)}>
                    {CATEGORIES.map((c) => <option key={c} value={c}>{tl(c)}</option>)}
                </select>
                <label className="label">{t('maint.every_days')}<input className="input" type="number" min={1} max={730} required value={f.every_days} onChange={(e) => set('every_days', e.target.value)} /></label>
                <label className="label">{t('maint.next_due')}<input className="input" type="date" required value={f.next_due} onChange={(e) => set('next_due', e.target.value)} /></label>
            </div>
            <div className="flex justify-end gap-2">
                <button type="button" className="btn-secondary" onClick={onClose}>{t('common.cancel')}</button>
                <button className="btn-primary">{initial ? t('common.save') : t('maint.create_schedule')}</button>
            </div>
        </form>
        </Modal>
    );
}
