import Tabs from '@/Components/ui/Tabs';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';
import DataTable from '@/Components/ui/DataTable';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { BedDouble, ClipboardList, Loader2, PackageSearch, RefreshCw, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatDate, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';

const STATUSES = ['vacant_clean', 'vacant_dirty', 'occupied_clean', 'occupied_dirty', 'inspected', 'out_of_order', 'out_of_service'] as const;
const STATUS_STYLE: Record<string, string> = {
    vacant_clean: 'bg-emerald-50 border-emerald-300 text-emerald-800',
    vacant_dirty: 'bg-amber-50 border-amber-300 text-amber-800',
    occupied_clean: 'bg-sky-50 border-sky-300 text-sky-800',
    occupied_dirty: 'bg-orange-50 border-orange-300 text-orange-800',
    inspected: 'bg-emerald-100 border-emerald-500 text-emerald-900',
    out_of_order: 'bg-red-50 border-red-300 text-red-800',
    out_of_service: 'bg-slate-100 border-slate-300 text-slate-700',
};
const label = (s: string) => s.replace(/_/g, ' ');

interface Room { id: number; number: string; floor: string | number | null; room_type_id: number; hk_status: string; occupied: boolean; room_type?: { id: number; code: string } | null }
interface ChecklistItem { item: string; done: boolean }
interface Task {
    id: number; room_id: number; date: string; kind: string; priority: number; status: string;
    assigned_to: number | null; inspected_by: number | null; started_at: string | null; finished_at: string | null;
    notes: string | null; checklist: ChecklistItem[] | null;
    room: { id: number; number: string; floor: string | number | null; hk_status: string; occupied: boolean } | null;
}
interface LostItem { id: number; description: string; location: string | null; room_id: number | null; found_on: string; status: string; returned_to: string | null; returned_at: string | null; disposal_reason?: string | null; disposed_at?: string | null }
interface Paged<T> { data: T[]; total: number; current_page: number; last_page: number }
interface Staff { id: number; name: string; roles: string[] }

type Msg = { ok: boolean; text: string } | null;

function Banner({ msg }: { msg: Msg }) {
    if (!msg) return null;
    return <div className={cn('rounded-lg px-4 py-2 text-sm mb-4', msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700')}>{msg.text}</div>;
}

function useAction(setMsg: (m: Msg) => void) {
    const [busy, setBusy] = useState<string | null>(null);
    async function run(key: string, path: string, body: object, ok: string, after: () => void) {
        setBusy(key);
        setMsg(null);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            setMsg({ ok: true, text: ok });
            after();
        } catch (e) {
            setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        } finally {
            setBusy(null);
        }
    }
    return { busy, run };
}

/* ---------------- Room board ---------------- */
function RoomBoard({ setMsg }: { setMsg: (m: Msg) => void }) {
    const board = useApi<Room[]>('housekeeping/board');
    const t = useT();
    const tl = (s: string) => { const k = `hk.st.${s}`; const v = t(k); return v === k ? label(s) : v; };
    const { busy, run } = useAction(setMsg);
    const [filter, setFilter] = useState('');
    const [editing, setEditing] = useState<Room | null>(null);
    const [status, setStatus] = useState('');
    const [reason, setReason] = useState('');

    const floors = useMemo(() => {
        const m = new Map<string, Room[]>();
        (board.data ?? []).filter((r) => !filter || r.hk_status === filter).forEach((r) => {
            const k = r.floor == null || r.floor === '' ? '—' : String(r.floor);
            m.set(k, [...(m.get(k) ?? []), r]);
        });
        return [...m.entries()];
    }, [board.data, filter]);

    const counts = useMemo(() => {
        const c: Record<string, number> = {};
        (board.data ?? []).forEach((r) => (c[r.hk_status] = (c[r.hk_status] ?? 0) + 1));
        return c;
    }, [board.data]);

    function open(r: Room) { setEditing(r); setStatus(r.hk_status); setReason(''); }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                <button type="button" aria-pressed={!filter} className={cn('min-h-11 rounded-full border px-4 text-sm font-medium', !filter ? 'border-navy-800 bg-navy-800 text-white' : 'border-slate-300 bg-white text-slate-800')} onClick={() => setFilter('')}>{t('hk.all_count', { count: board.data?.length ?? 0 })}</button>
                {STATUSES.map((s) => (
                    <button key={s} type="button" aria-pressed={filter === s} className={cn('min-h-11 rounded-full border px-4 text-sm font-medium capitalize', STATUS_STYLE[s], filter === s && 'outline-2 outline-slate-900')} onClick={() => setFilter(filter === s ? '' : s)}>
                        {tl(s)} ({counts[s] ?? 0})
                    </button>
                ))}
            </div>
            {board.loading && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            {board.error && <Banner msg={{ ok: false, text: board.error.message }} />}

            {editing && (
                <div className="card p-4 space-y-3">
                    <div className="font-semibold">{t('hk.room_no', { number: editing.number })} <span className="text-xs text-slate-500">{editing.occupied ? t('hk.occupied') : t('hk.vacant')} · {t('hk.now', { status: tl(editing.hk_status) })}</span></div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        <select className="input capitalize" value={status} onChange={(e) => setStatus(e.target.value)}>
                            {STATUSES.map((s) => <option key={s} value={s} disabled={editing.occupied && s.startsWith('vacant')}>{tl(s)}</option>)}
                        </select>
                        <input className="input sm:col-span-2" aria-label={t('hk.reason_optional')} placeholder={t('hk.reason_optional')} value={reason} onChange={(e) => setReason(e.target.value)} />
                    </div>
                    <div className="flex gap-2">
                        <button className="btn-primary" disabled={busy !== null || status === editing.hk_status}
                            onClick={() => run('status', `housekeeping/rooms/${editing.id}/status`, { status, reason: reason || null }, t('hk.status_set', { number: editing.number, status: tl(status) }), () => { setEditing(null); board.reload(); })}>
                            {busy === 'status' ? t('hk.saving') : t('hk.save_status')}
                        </button>
                        <button className="btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
                    </div>
                </div>
            )}

            {floors.map(([floor, rooms]) => (
                <div key={floor} className="card p-4">
                    <div className="text-sm font-semibold text-slate-600 mb-2">{t('hk.floor', { floor })}</div>
                    <div className="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-8 lg:grid-cols-10 gap-2">
                        {rooms.map((r) => (
                            <button key={r.id} onClick={() => open(r)} title={tl(r.hk_status)}
                                className={cn('border rounded-lg p-2 text-left hover:border-slate-400', STATUS_STYLE[r.hk_status] ?? 'bg-white', editing?.id === r.id && 'outline-2 outline-slate-700')}>
                                <div className="font-bold flex items-center gap-1">{r.number}{r.occupied && <BedDouble className="w-3 h-3" />}</div>
                                <div className="text-xs uppercase">{r.room_type?.code}</div>
                                <div className="text-xs capitalize truncate">{tl(r.hk_status)}</div>
                            </button>
                        ))}
                    </div>
                </div>
            ))}
            {!board.loading && floors.length === 0 && <p className="text-sm text-slate-500">{t('hk.no_rooms')}</p>}
        </div>
    );
}

/* ---------------- Tasks ---------------- */
const TASK_STYLE: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-700', in_progress: 'bg-sky-100 text-sky-800', done: 'bg-amber-100 text-amber-800',
    inspected: 'bg-emerald-100 text-emerald-800', failed: 'bg-red-100 text-red-700',
};

function Tasks({ setMsg }: { setMsg: (m: Msg) => void }) {
    const [date, setDate] = useState('');
    const t = useT();
    const tl = (s: string) => { const k = `hk.st.${s}`; const v = t(k); return v === k ? label(s) : v; };
    const [mine, setMine] = useState(false);
    const [statusFilter, setStatusFilter] = useState('');
    const [q, setQ] = useState('');
    const qs = new URLSearchParams();
    if (date) qs.set('date', date);
    if (mine) qs.set('mine', '1');
    const tasks = useApi<Task[]>(`housekeeping/tasks?${qs}`);
    const [allStaff, setAllStaff] = useState(false);
    const users = useApi<Staff[]>(allStaff ? 'staff' : 'staff?role=room-attendant');
    const userName = (id: number | null) => (id == null ? '—' : users.data?.find((u) => u.id === id)?.name ?? `#${id}`);
    const { busy, run } = useAction(setMsg);
    const [finishing, setFinishing] = useState<Task | null>(null);
    const [checklist, setChecklist] = useState<ChecklistItem[]>([]);
    const [notes, setNotes] = useState('');
    const [inspecting, setInspecting] = useState<Task | null>(null);
    const [inspNotes, setInspNotes] = useState('');
    const [assignId, setAssignId] = useState<Record<number, string>>({});

    const rows = (tasks.data ?? []).filter((x) => (!statusFilter || x.status === statusFilter) && (!q || x.room?.number.toLowerCase().includes(q.toLowerCase())));
    const reload = () => tasks.reload();

    return (
        <div className="space-y-4">
            <button type="button" data-primary-action className="btn-primary btn-lg w-full sm:w-auto" disabled={busy !== null}
                onClick={() => api<{ created: number }>('housekeeping/tasks/generate', { method: 'POST', body: JSON.stringify(date ? { date } : {}) })
                    .then((r) => { setMsg({ ok: true, text: t('hk.tasks_created', { count: r.created }) }); reload(); })
                    .catch((e) => setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) }))}>
                <Sparkles className="w-4 h-4" aria-hidden="true" /> {t('hk.generate')}
            </button>
            <FilterBar
                activeCount={[date, q, statusFilter].filter(Boolean).length + (mine ? 1 : 0) + (allStaff ? 1 : 0)}
                onClear={() => { setDate(''); setQ(''); setStatusFilter(''); setMine(false); setAllStaff(false); }}
                search={<input type="search" className="input min-w-0 flex-1 sm:w-40 sm:flex-none" aria-label={t('hk.room_ph')} placeholder={t('hk.room_ph')} value={q} onChange={(e) => setQ(e.target.value)} />}
            >
                <label className="label">{t('hk.date_hint')}<input type="date" className="input sm:w-44" value={date} onChange={(e) => setDate(e.target.value)} /></label>
                <label className="label">{t('guests.status')}
                    <select className="select sm:w-44" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                        <option value="">{t('hk.all_statuses')}</option>
                        {Object.keys(TASK_STYLE).map((s) => <option key={s} value={s}>{tl(s)}</option>)}
                    </select>
                </label>
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} /> {t('hk.mine_only')}</label>
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={allStaff} onChange={(e) => setAllStaff(e.target.checked)} /> {t('hk.any_staff')}</label>
            </FilterBar>

            <Modal open={!!finishing} onClose={() => setFinishing(null)} title={finishing ? t('hk.finish_room', { number: finishing.room?.number ?? '' }) : ''}>
                {finishing && (
                <div className="space-y-3">
                    <div className="grid sm:grid-cols-2 gap-1">
                        {checklist.map((c, i) => (
                            <label key={i} className="flex min-h-11 items-center gap-2 text-sm">
                                <input type="checkbox" checked={c.done} onChange={(e) => setChecklist(checklist.map((x, j) => (j === i ? { ...x, done: e.target.checked } : x)))} /> {c.item}
                            </label>
                        ))}
                    </div>
                    <textarea className="input w-full" rows={2} aria-label={t('guests.field.notes')} placeholder={t('guests.field.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} />
                    <div className="btn-row">
                        <button className="btn-primary" disabled={busy !== null}
                            onClick={() => run('finish', `housekeeping/tasks/${finishing.id}/finish`, { checklist, notes: notes || null }, t('hk.room_cleaned', { number: finishing.room?.number ?? '' }), () => { setFinishing(null); reload(); })}>{t('hk.mark_done')}</button>
                        <button className="btn-secondary" onClick={() => setFinishing(null)}>{t('common.cancel')}</button>
                    </div>
                </div>
                )}
            </Modal>

            <Modal open={!!inspecting} onClose={() => setInspecting(null)} title={inspecting ? t('hk.inspect_room', { number: inspecting.room?.number ?? '' }) : ''}>
                {inspecting && (
                <div className="space-y-3">
                    <textarea className="input w-full" rows={2} aria-label={t('hk.insp_notes')} placeholder={t('hk.insp_notes')} value={inspNotes} onChange={(e) => setInspNotes(e.target.value)} />
                    <div className="btn-row">
                        <button className="btn-primary" disabled={busy !== null} onClick={() => run('insp', `housekeeping/tasks/${inspecting.id}/inspect`, { passed: true, notes: inspNotes || null }, t('hk.insp_passed'), () => { setInspecting(null); reload(); })}>{t('hk.pass')}</button>
                        <button className="btn-secondary text-red-700" disabled={busy !== null} onClick={() => run('insp', `housekeeping/tasks/${inspecting.id}/inspect`, { passed: false, notes: inspNotes || null }, t('hk.insp_failed'), () => { setInspecting(null); reload(); })}>{t('hk.fail')}</button>
                        <button className="btn-secondary" onClick={() => setInspecting(null)}>{t('common.cancel')}</button>
                    </div>
                </div>
                )}
            </Modal>

            {tasks.error && <Banner msg={{ ok: false, text: tasks.error.message }} />}
            <DataTable
                caption={t('hk.title')}
                tableKey="hk-tasks"
                loading={tasks.loading && !tasks.data}
                rows={rows}
                rowKey={(x) => x.id}
                rowLabel={(x) => x.room?.number ?? `#${x.id}`}
                selectable
                bulkActions={({ rows: picked, clear }) => {
                    const pending = picked.filter((x) => x.status === 'pending' || x.status === 'failed');
                    return (
                        <button type="button" className="btn-secondary btn-sm" disabled={busy !== null || pending.length === 0}
                            onClick={async () => {
                                for (const x of pending) await run(`s${x.id}`, `housekeeping/tasks/${x.id}/start`, {}, t('hk.started', { number: x.room?.number ?? '' }), () => undefined);
                                setMsg({ ok: true, text: t('desktop.hk.started_many', { count: pending.length }) });
                                clear();
                                reload();
                            }}>{t('desktop.hk.start_selected')} ({pending.length})</button>
                    );
                }}
                rowActions={(x) => [
                    { key: 'start', label: t('hk.start'), hidden: !(x.status === 'pending' || x.status === 'failed'), onSelect: (r) => run(`s${r.id}`, `housekeeping/tasks/${r.id}/start`, {}, t('hk.started', { number: r.room?.number ?? '' }), reload) },
                    { key: 'finish', label: t('hk.finish'), hidden: x.status !== 'in_progress', onSelect: (r) => { setFinishing(r); setChecklist(r.checklist ?? []); setNotes(r.notes ?? ''); } },
                    { key: 'inspect', label: t('hk.inspect'), hidden: x.status !== 'done', onSelect: (r) => { setInspecting(r); setInspNotes(''); } },
                ]}
                empty={<p className="text-slate-500 py-4 text-center">{t('hk.no_tasks')}</p>}
                columns={[
                    { key: 'room', header: t('guests.show.room'), sortValue: (x) => x.room?.number ?? '', className: 'font-semibold', cell: (x) => <>{x.room?.number} <span className="text-xs font-normal text-slate-500">{x.room?.floor != null && t('hk.floor_short', { floor: x.room.floor })}</span></> },
                    { key: 'kind', header: t('guests.companies.kind'), sortValue: (x) => x.kind, className: 'capitalize', cell: (x) => tl(x.kind) },
                    { key: 'prio', header: t('hk.prio'), sortValue: (x) => x.priority, cell: (x) => x.priority },
                    { key: 'status', header: t('guests.status'), sortValue: (x) => x.status, cell: (x) => <span className={cn('px-2 py-0.5 rounded border border-current text-xs font-medium capitalize', TASK_STYLE[x.status])}>{tl(x.status)}</span> },
                    { key: 'assigned', header: t('hk.assigned'), cell: (x) => (
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        <select className="select w-40" aria-label={t('hk.assigned')} disabled={!users.data} value={assignId[x.id] ?? x.assigned_to ?? ''} onChange={(e) => setAssignId({ ...assignId, [x.id]: e.target.value })}>
                                            <option value="">{users.loading ? t('common.loading') : t('hk.unassigned')}</option>
                                            {x.assigned_to != null && !users.data?.some((u) => u.id === x.assigned_to) && <option value={x.assigned_to}>{userName(x.assigned_to)}</option>}
                                            {users.data?.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                        </select>
                                        {assignId[x.id] && String(assignId[x.id]) !== String(x.assigned_to ?? '') && (
                                            <button type="button" className="btn-secondary btn-sm" disabled={busy !== null}
                                                onClick={() => run(`a${x.id}`, `housekeeping/tasks/${x.id}/assign`, { user_id: Number(assignId[x.id]) }, t('hk.assigned_to', { name: userName(Number(assignId[x.id])) }), () => { setAssignId({ ...assignId, [x.id]: '' }); reload(); })}>{t('hk.assign')}</button>
                                        )}
                                    </div>
                    ) },
                    { key: 'times', header: t('hk.times'), className: 'text-sm', cell: (x) => <span className="text-slate-600">{x.started_at && <>▶ {formatDateTime(x.started_at)}<br /></>}{x.finished_at && <>✓ {formatDateTime(x.finished_at)}</>}</span> },
                    { key: 'actions', header: t('hk.actions'), required: true, className: 'whitespace-nowrap', cell: (x) => (
                        <>
                            {(x.status === 'pending' || x.status === 'failed') && (
                                <button className="btn-primary" disabled={busy !== null} onClick={() => run(`s${x.id}`, `housekeeping/tasks/${x.id}/start`, {}, t('hk.started', { number: x.room?.number ?? '' }), reload)}>{t('hk.start')}</button>
                            )}
                            {x.status === 'in_progress' && (
                                <button className="btn-primary" onClick={() => { setFinishing(x); setChecklist(x.checklist ?? []); setNotes(x.notes ?? ''); }}>{t('hk.finish')}</button>
                            )}
                            {x.status === 'done' && (
                                <button className="btn-primary" onClick={() => { setInspecting(x); setInspNotes(''); }}>{t('hk.inspect')}</button>
                            )}
                        </>
                    ) },
                ]}
            />
        </div>
    );
}

/* ---------------- Lost & found ---------------- */
function LostFound({ setMsg }: { setMsg: (m: Msg) => void }) {
    const t = useT();
    const [page, setPage] = useState(1);
    const [q, setQ] = useState('');
    const [show, setShow] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const lq = new URLSearchParams({ page: String(page) });
    if (q) lq.set('q', q);
    if (show) lq.set('status', show);
    if (from) lq.set('from', from);
    if (to) lq.set('to', to);
    const list = useApi<Paged<LostItem>>(`housekeeping/lost-found?${lq}`);
    const rooms = useApi<Room[]>('housekeeping/board');
    const { busy, run } = useAction(setMsg);
    const today = new Date().toISOString().slice(0, 10);
    const [form, setForm] = useState({ description: '', location: '', room_id: '', found_on: '' });
    const [disposing, setDisposing] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const [returning, setReturning] = useState<number | null>(null);
    const [returnedTo, setReturnedTo] = useState('');
    const roomNo = (id: number | null) => rooms.data?.find((r) => r.id === id)?.number ?? (id ? `#${id}` : '—');
    const rows = list.data?.data ?? [];
    const filter = (f: () => void) => { f(); setPage(1); };

    return (
        <div className="space-y-4">
            <form className="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2" onSubmit={(e) => {
                e.preventDefault();
                run('log', 'housekeeping/lost-found', { description: form.description, location: form.location || null, room_id: form.room_id ? Number(form.room_id) : null, found_on: form.found_on || null }, t('hk.lf.logged'), () => { setForm({ description: '', location: '', room_id: '', found_on: '' }); list.reload(); });
            }}>
                <input className="input sm:col-span-2" required maxLength={255} aria-label={t('hk.lf.description')} placeholder={t('hk.lf.description')} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                <input className="input" aria-label={t('hk.lf.location_found')} placeholder={t('hk.lf.location_found')} value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
                <select className="input" value={form.room_id} onChange={(e) => setForm({ ...form, room_id: e.target.value })} aria-label={t('hk.lf.no_room')}>
                    <option value="">{t('hk.lf.no_room')}</option>
                    {rooms.data?.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}
                </select>
                <input type="date" className="input" aria-label={t('hk.lf.found_on_hint')} title={t('hk.lf.found_on_hint')} max={today} value={form.found_on} onChange={(e) => setForm({ ...form, found_on: e.target.value })} />
                <button className="btn-primary sm:col-span-5 sm:w-40" disabled={busy !== null}>{t('hk.lf.log')}</button>
            </form>

            <div className="flex flex-wrap gap-2">
                <input className="input w-48" aria-label={t('hk.lf.search')} placeholder={t('hk.lf.search')} value={q} onChange={(e) => filter(() => setQ(e.target.value))} />
                <select className="input w-40" value={show} onChange={(e) => filter(() => setShow(e.target.value))} aria-label={t('common.all')}>
                    <option value="">{t('common.all')}</option><option value="stored">{t('hk.lf.stored')}</option><option value="returned">{t('hk.lf.returned')}</option><option value="disposed">{t('hk.lf.disposed')}</option>
                </select>
                <input type="date" className="input" aria-label={t('hk.lf.from')} title={t('hk.lf.from')} value={from} onChange={(e) => filter(() => setFrom(e.target.value))} />
                <input type="date" className="input" aria-label={t('hk.lf.to')} title={t('hk.lf.to')} value={to} onChange={(e) => filter(() => setTo(e.target.value))} />
            </div>
            {list.error && <Banner msg={{ ok: false, text: list.error.message }} />}
            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr className="text-left"><th>{t('hk.lf.found')}</th><th>{t('hk.lf.item')}</th><th>{t('hk.lf.location')}</th><th>{t('guests.show.room')}</th><th>{t('guests.status')}</th><th></th></tr></thead>
                    <tbody>
                        {rows.map((i) => (
                            <tr key={i.id} className="border-t">
                                <td>{formatDate(i.found_on)}</td>
                                <td>{i.description}</td>
                                <td>{i.location ?? '—'}</td>
                                <td>{roomNo(i.room_id)}</td>
                                <td>{i.status === 'returned'
                                    ? <span className="text-emerald-700 text-xs">{t('hk.lf.returned_to', { name: i.returned_to ?? '' })}{i.returned_at && ` · ${formatDate(i.returned_at)}`}</span>
                                    : i.status === 'disposed'
                                    ? <span className="text-slate-500 text-xs">{t('hk.lf.disposed')}{i.disposal_reason && `: ${i.disposal_reason}`}{i.disposed_at && ` · ${formatDate(i.disposed_at)}`}</span>
                                    : <span className="text-amber-700 text-xs capitalize">{i.status === 'stored' ? t('hk.lf.stored') : i.status}</span>}</td>
                                <td className="whitespace-nowrap">
                                    <span className="inline-flex gap-1 mr-1 align-middle"><OpsPrintButton path={`lost-found-handin/${i.id}`} label={t('documents4.lost_in')} />{i.status === 'returned' && <OpsPrintButton path={`lost-found-return/${i.id}`} label={t('documents4.lost_out')} />}</span>
                                    {i.status === 'stored' && disposing === i.id && (
                                        <span className="flex flex-wrap gap-2">
                                            <input className="input w-40" aria-label={t('hk.lf.disposal_reason')} placeholder={t('hk.lf.disposal_reason')} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
                                            <button className="btn-primary" disabled={!reason || busy !== null}
                                                onClick={() => run('disp', `housekeeping/lost-found/${i.id}/dispose`, { reason }, t('hk.lf.item_disposed'), () => { setDisposing(null); list.reload(); })}>OK</button>
                                            <button className="btn-secondary btn-sm" aria-label={t('common.cancel')} onClick={() => setDisposing(null)}>×</button>
                                        </span>
                                    )}
                                    {i.status === 'stored' && disposing !== i.id && (returning === i.id ? (
                                        <span className="flex flex-wrap gap-2">
                                            <input className="input w-40" aria-label={t('hk.lf.returned_to_ph')} placeholder={t('hk.lf.returned_to_ph')} value={returnedTo} onChange={(e) => setReturnedTo(e.target.value)} />
                                            <button className="btn-primary" disabled={!returnedTo || busy !== null}
                                                onClick={() => run('ret', `housekeeping/lost-found/${i.id}/return`, { returned_to: returnedTo }, t('hk.lf.marked_returned'), () => { setReturning(null); list.reload(); })}>OK</button>
                                            <button className="btn-secondary btn-sm" aria-label={t('common.cancel')} onClick={() => setReturning(null)}>×</button>
                                        </span>
                                    ) : (
                                        <span className="flex flex-wrap gap-2">
                                            <button className="btn-secondary btn-sm" onClick={() => { setReturning(i.id); setReturnedTo(''); }}>{t('hk.lf.mark_returned')}</button>
                                            <button className="btn-secondary btn-sm" onClick={() => { setDisposing(i.id); setReason(''); setReturning(null); }}>{t('hk.lf.dispose')}</button>
                                        </span>
                                    ))}
                                </td>
                            </tr>
                        ))}
                        {!list.loading && rows.length === 0 && <tr><td colSpan={6} className="text-center text-slate-500 py-4">{t('hk.lf.none')}</td></tr>}
                    </tbody>
                </table>
            </div>
            {list.data && list.data.last_page > 1 && (
                <div className="flex gap-2 items-center text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                    {t('hk.page', { page: list.data.current_page, last: list.data.last_page })}
                    <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                </div>
            )}
        </div>
    );
}

const TABS = [
    { key: 'board', label: 'hk.tab.board', icon: BedDouble },
    { key: 'tasks', label: 'hk.tab.tasks', icon: ClipboardList },
    { key: 'lost', label: 'hk.tab.lost', icon: PackageSearch },
] as const;

export default function Housekeeping() {
    const t = useT();
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>('board');
    const [msg, setMsg] = useState<Msg>(null);
    const [key, setKey] = useState(0);
    return (
        <AppLayout title={t('hk.title')} subtitle={t('hk.subtitle')}
            actions={<button type="button" className="btn-secondary" onClick={() => setKey((k) => k + 1)}><RefreshCw className="w-4 h-4" /> {t('hk.refresh')}</button>}>
            <Head title={t('hk.title')} />
            <Tabs label={t('hk.title')} className="mb-4" value={tab} onChange={(k) => { setTab(k as typeof tab); setMsg(null); }}
                tabs={TABS.map((tb) => ({ key: tb.key, label: t(tb.label), icon: tb.icon }))} />
            <Banner msg={msg} />
            <div key={key}>
                {tab === 'board' && <RoomBoard setMsg={setMsg} />}
                {tab === 'tasks' && <Tasks setMsg={setMsg} />}
                {tab === 'lost' && <LostFound setMsg={setMsg} />}
            </div>
        </AppLayout>
    );
}
