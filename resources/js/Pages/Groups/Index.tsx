import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Loader2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';

interface Allocation { room_type_id: number; date: string; rooms: number; picked_up: number }
interface Block {
    id: number; code: string; name: string; status: string; arrival: string; departure: string;
    cutoff_date: string | null; company_id: number | null; rate_plan_id: number | null; allocations: Allocation[];
}
type List<T> = T[] | { data: T[] };
interface Named { id: number; name: string; code?: string }

const rowsOf = <T,>(l: List<T> | null | undefined): T[] => (!l ? [] : Array.isArray(l) ? l : l.data ?? []);
const STATUSES = ['tentative', 'definite', 'released'];

const blockStatusClass = (s: string) =>
    s === 'definite' ? 'bg-emerald-50 text-emerald-700' : s === 'tentative' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500';

export default function GroupsIndex() {
    const t = useT();
    const [status, setStatus] = useState('');
    const [creating, setCreating] = useState(false);
    const blocks = useApi<List<Block>>(`blocks${status ? `?status=${status}` : ''}`);
    const companies = useApi<List<Named>>('companies?per_page=200');

    const rows = rowsOf(blocks.data);
    const companyName = (id: number | null) => rowsOf(companies.data).find((c) => c.id === id)?.name;

    return (
        <AppLayout title={t('groups.title')} subtitle={t('groups.subtitle')}
            actions={<button className="btn-primary" onClick={() => setCreating(!creating)}><Plus className="w-4 h-4" /> {t('groups.new_block')}</button>}>
            <Head title={t('groups.short')} />
            {creating && <CreateBlock companies={rowsOf(companies.data)} onDone={() => { setCreating(false); blocks.reload(); }} />}

            <div className="flex gap-3 mb-4">
                <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('groups.all_statuses')}>
                    <option value="">{t('groups.all_statuses')}</option>
                    {STATUSES.map((s) => <option key={s} value={s}>{t(`groups.status.${s}`)}</option>)}
                </select>
            </div>

            {blocks.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{blocks.error.message}</div>}

            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th>{t('groups.code')}</th><th>{t('groups.name')}</th><th>{t('res.company')}</th><th>{t('groups.dates')}</th><th>{t('groups.cutoff')}</th><th className="text-right">{t('groups.pickup')}</th><th>{t('res.status')}</th></tr></thead>
                    <tbody>
                        {blocks.loading && !blocks.data && <tr><td colSpan={7} className="py-10"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-500" /></td></tr>}
                        {blocks.data && rows.length === 0 && <tr><td colSpan={7} className="text-center py-10 text-slate-500 text-sm">{t('groups.empty')}</td></tr>}
                        {rows.map((b) => {
                            const rooms = b.allocations.reduce((a, x) => a + x.rooms, 0);
                            const picked = b.allocations.reduce((a, x) => a + (x.picked_up ?? 0), 0);
                            return (
                                <tr key={b.id}>
                                    <td><Link href={`/groups/${b.id}`} className="font-mono text-sm text-navy-600 underline underline-offset-2">{b.code}</Link></td>
                                    <td className="text-sm">{b.name}</td>
                                    <td className="text-sm">{companyName(b.company_id) ?? '—'}</td>
                                    <td className="text-sm whitespace-nowrap">{formatDate(b.arrival)} → {formatDate(b.departure)}</td>
                                    <td className="text-sm">{b.cutoff_date ? formatDate(b.cutoff_date) : '—'}</td>
                                    <td className="text-right text-sm">{picked} / {rooms} <span className="text-xs text-slate-500">{t('groups.room_nights')}</span></td>
                                    <td><span className={cn('text-xs px-2 py-0.5 rounded', blockStatusClass(b.status))}>{t(`groups.status.${b.status}`)}</span><span className="ml-2 inline-flex gap-1 align-middle"><OpsPrintButton path={`group-contract/${b.id}`} label={t('documents4.group_contract')} /><OpsPrintButton path={`rooming-list/${b.id}`} label={t('documents4.rooming_list')} /></span></td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}

function CreateBlock({ companies, onDone }: { companies: Named[]; onDone: () => void }) {
    const t = useT();
    const roomTypes = useApi<List<Named>>('room-types?per_page=200');
    const ratePlans = useApi<List<Named>>('rate-plans?per_page=200');
    const [form, setForm] = useState({ code: '', name: '', arrival: '', departure: '', cutoff_date: '', rate_plan_id: '', company_id: '', status: 'tentative', notes: '' });
    const [allocs, setAllocs] = useState([{ room_type_id: '', rooms: '', rate: '' }]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm({ ...form, [k]: e.target.value });

    return (
        <form className="card px-5 py-4 mb-6 space-y-4" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setError(null);
            try {
                await api('blocks', { method: 'POST', body: JSON.stringify({
                    code: form.code, name: form.name, arrival: form.arrival, departure: form.departure, status: form.status,
                    cutoff_date: form.cutoff_date || null, notes: form.notes || null,
                    rate_plan_id: form.rate_plan_id ? Number(form.rate_plan_id) : null,
                    company_id: form.company_id ? Number(form.company_id) : null,
                    allocations: allocs.map((a) => ({ room_type_id: Number(a.room_type_id), rooms: Number(a.rooms), rate: a.rate ? Number(a.rate) : null })),
                }) });
                onDone();
            } catch (err) {
                setError(err instanceof ApiError ? (err.problem.errors ? Object.values(err.problem.errors).flat().join(' ') : err.message) : String(err));
            } finally {
                setBusy(false);
            }
        }}>
            <h3 className="font-semibold text-slate-800">{t('groups.create.title')}</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <label className="label">{t('groups.code')}<input className="input mt-1" required maxLength={20} value={form.code} onChange={set('code')} /></label>
                <label className="label lg:col-span-3">{t('groups.name')}<input className="input mt-1" required maxLength={150} value={form.name} onChange={set('name')} /></label>
                <label className="label">{t('res.arrival')}<input className="input mt-1" type="date" required value={form.arrival} onChange={set('arrival')} /></label>
                <label className="label">{t('res.departure')}<input className="input mt-1" type="date" required min={form.arrival || undefined} value={form.departure} onChange={set('departure')} /></label>
                <label className="label">{t('groups.cutoff_date')}<input className="input mt-1" type="date" max={form.arrival || undefined} value={form.cutoff_date} onChange={set('cutoff_date')} /></label>
                <label className="label">{t('res.status')}
                    <select className="input mt-1" value={form.status} onChange={set('status')}><option value="tentative">{t('groups.status.tentative')}</option><option value="definite">{t('groups.status.definite')}</option></select>
                </label>
                <label className="label lg:col-span-2">{t('res.rate_plan')}
                    <select className="input mt-1" value={form.rate_plan_id} onChange={set('rate_plan_id')}>
                        <option value="">{t('groups.create.rate_later')}</option>
                        {rowsOf(ratePlans.data).map((r) => <option key={r.id} value={r.id}>{r.code ? `${r.code} · ` : ''}{r.name}</option>)}
                    </select>
                </label>
                <label className="label lg:col-span-2">{t('res.company')}
                    <select className="input mt-1" value={form.company_id} onChange={set('company_id')}>
                        <option value="">—</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </label>
            </div>

            <div>
                <div className="text-xs text-slate-500 mb-2">{t('groups.create.allocations')}</div>
                <div className="space-y-2">
                    {allocs.map((a, i) => (
                        <div key={i} className="grid grid-cols-[1fr_5rem_7rem_auto] gap-2">
                            <select className="input" required value={a.room_type_id} onChange={(e) => setAllocs(allocs.map((x, j) => j === i ? { ...x, room_type_id: e.target.value } : x))} aria-label={t('groups.room_type_ph')}>
                                <option value="" disabled>{t('groups.room_type_ph')}</option>
                                {rowsOf(roomTypes.data).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                            <input className="input" type="number" min={1} max={500} required aria-label={t('res.rooms')} placeholder={t('res.rooms')} value={a.rooms} onChange={(e) => setAllocs(allocs.map((x, j) => j === i ? { ...x, rooms: e.target.value } : x))} />
                            <input className="input" type="number" min={0} aria-label={t('groups.create.rate_opt')} placeholder={t('groups.create.rate_opt')} value={a.rate} onChange={(e) => setAllocs(allocs.map((x, j) => j === i ? { ...x, rate: e.target.value } : x))} />
                            <button type="button" className="text-slate-500 hover:text-red-600 disabled:opacity-30" disabled={allocs.length === 1} title={t('groups.remove')}
                                onClick={() => setAllocs(allocs.filter((_, j) => j !== i))}><Trash2 className="w-4 h-4" /></button>
                        </div>
                    ))}
                </div>
                <button type="button" className="btn-secondary btn-sm mt-2" onClick={() => setAllocs([...allocs, { room_type_id: '', rooms: '', rate: '' }])}><Plus className="w-3 h-3" /> {t('groups.create.add_type')}</button>
            </div>

            <textarea className="input" rows={2} aria-label={t('groups.create.notes')} placeholder={t('groups.create.notes')} value={form.notes} onChange={set('notes')} />
            {error && <div className="rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{error}</div>}
            <div className="flex gap-2">
                <button className="btn-primary" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('groups.create.submit')}</button>
                <button type="button" className="btn-secondary" onClick={onDone}>{t('common.cancel')}</button>
            </div>
        </form>
    );
}
