import { api, useApi } from '@/lib/api';
import QueryStatus from '@/Components/ui/QueryStatus';
import { formatDate } from '@/lib/utils';
import { Calculator, Check, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Badge, Banner, errMsg, Field, Paged } from './shared';
import { useT } from '@/lib/i18n';

interface Entry { id: number; date: string; category: string; minutes: number; status: string; employee?: { number: string; first_name: string; last_name: string } }
interface Holiday { id: number; date: string; name: string; movable: boolean }
interface Rules { pack: string; certified: boolean; rules: Record<string, number | string | boolean> }

const CATEGORY: Record<string, string> = { weekday: 'hr.ot.cat.weekday', night: 'hr.ot.cat.night', sunday: 'hr.ot.cat.sunday', holiday: 'hr.ot.cat.holiday' };

function useMsg() {
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    return { ok, err, good: (m: string) => (setOk(m), setErr(null)), bad: (e: unknown) => (setErr(errMsg(e)), setOk(null)) };
}

const today = new Date();
const monthStart = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10);
const iso = (d: Date) => d.toISOString().slice(0, 10);

export function OvertimeTab() {
    const t = useT();
    const m = useMsg();
    const [status, setStatus] = useState('pending');
    const [range, setRange] = useState({ from: monthStart, to: iso(today) });
    const entries = useApi<Paged<Entry>>(`hr/overtime?status=${status}`, [status]);
    const rules = useApi<Rules>('hr/overtime/rules');
    const [busy, setBusy] = useState(false);

    async function compute(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        try {
            const r = await api<{ entries: Entry[] }>('hr/overtime/compute', { method: 'POST', body: JSON.stringify(range) });
            m.good(t('hr.ot.computed', { count: r.entries.length })); entries.reload();
        } catch (x) { m.bad(x); } finally { setBusy(false); }
    }
    async function decide(x: Entry, approve: boolean) {
        try { await api(`hr/overtime/${x.id}/decide`, { method: 'POST', body: JSON.stringify({ approve }) }); m.good(approve ? t('hr.ot.approved') : t('hr.ot.rejected')); entries.reload(); } catch (y) { m.bad(y); }
    }

    const r = rules.data?.rules;
    return (
        <div>
            <Banner ok={m.ok} err={m.err} />
            <QueryStatus query={entries} variant="table" rows={3} />
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={compute} className="card">
                    <h3 className="mb-3 font-semibold">{t('hr.ot.compute_title')}</h3>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label={t('hr.common.from')}><input type="date" className="input" required value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
                        <Field label={t('hr.common.to')}><input type="date" className="input" required value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
                    </div>
                    <button className="btn-primary mt-3 w-full" disabled={busy}><Calculator className="h-4 w-4" /> {t('hr.ot.compute_btn')}</button>
                    <p className="mt-2 text-xs text-slate-500">{t('hr.ot.compute_hint')}</p>
                </form>
                <div className="card lg:col-span-2">
                    <h3 className="mb-2 font-semibold">{t('hr.ot.rates', { pack: rules.data?.pack ?? '…' })}</h3>
                    {r && (
                        <div className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                            <div>{t('hr.ot.cat.weekday')}: ×{String(r.first_8h)} / ×{String(r.next_8h)} / ×{String(r.beyond)}</div>
                            <div>{t('hr.ot.cat.night')} ({String(r.night_start)}–{String(r.night_end)}): ×{String(r.night)}</div>
                            <div>{t('hr.ot.cat.sunday')}: ×{String(r.sunday)}</div>
                            <div>{t('hr.ot.cat.holiday')}: ×{String(r.holiday)}</div>
                        </div>
                    )}
                    {rules.data && !rules.data.certified && <p className="mt-2 text-xs text-amber-700">{t('hr.ot.uncertified')}</p>}
                </div>
            </div>
            <div className="card mt-5">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold">{t('hr.ot.lines')}</h3>
                    <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="pending">{t('hr.status.pending')}</option><option value="approved">{t('hr.status.approved')}</option><option value="rejected">{t('hr.status.rejected')}</option>
                    </select>
                </div>
                <div className="overflow-x-auto">
                    <table className="table-auto w-full text-sm">
                        <thead><tr className="text-left text-slate-500"><th>{t('hr.common.date')}</th><th>{t('hr.emp.employee')}</th><th>{t('hr.common.type')}</th><th>{t('hr.ot.hours')}</th><th>{t('hr.common.status')}</th><th /></tr></thead>
                        <tbody>{entries.data?.data.map((x) => (
                            <tr key={x.id} className="border-t">
                                <td className="py-2">{formatDate(x.date)}</td>
                                <td>{x.employee ? `${x.employee.first_name} ${x.employee.last_name}` : ''}</td>
                                <td>{CATEGORY[x.category] ? t(CATEGORY[x.category]) : x.category}</td>
                                <td>{(x.minutes / 60).toFixed(2)}</td>
                                <td><Badge s={x.status} /></td>
                                <td className="whitespace-nowrap text-right">{x.status === 'pending' && <>
                                    <button className="btn-secondary mr-1" aria-label={t('hr.common.approve')} onClick={() => decide(x, true)}><Check className="h-4 w-4" /></button>
                                    <button className="btn-secondary" aria-label={t('hr.common.reject')} onClick={() => decide(x, false)}><X className="h-4 w-4" /></button>
                                </>}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                    {entries.data?.data.length === 0 && <p className="py-3 text-sm text-slate-500">{t('hr.common.nothing')}</p>}
                </div>
            </div>
            <HolidaysCard />
        </div>
    );
}

function HolidaysCard() {
    const t = useT();
    const m = useMsg();
    const [year, setYear] = useState(today.getFullYear());
    const list = useApi<Holiday[]>(`hr/public-holidays?year=${year}`, [year]);
    const [f, setF] = useState({ date: '', name: '' });
    const [confirm, setConfirm] = useState<number | null>(null);

    async function add(e: React.FormEvent) {
        e.preventDefault();
        try { await api('hr/public-holidays', { method: 'POST', body: JSON.stringify(f) }); m.good(t('hr.ot.holiday_added', { name: f.name })); setF({ date: '', name: '' }); list.reload(); } catch (x) { m.bad(x); }
    }
    async function remove(id: number) {
        try { await api(`hr/public-holidays/${id}`, { method: 'DELETE' }); m.good(t('hr.ot.holiday_removed')); setConfirm(null); list.reload(); } catch (x) { m.bad(x); }
    }

    return (
        <div className="card mt-5">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-semibold">{t('hr.ot.public_holidays')}</h3>
                <input type="number" className="input w-28" value={year} onChange={(e) => setYear(Number(e.target.value) || today.getFullYear())} />
            </div>
            <Banner ok={m.ok} err={m.err} />
            <QueryStatus query={list} variant="list" rows={3} />
            <p className="mb-3 text-sm text-slate-500">{t('hr.ot.holidays_hint')}</p>
            <form onSubmit={add} className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-[10rem_1fr_auto]">
                <input type="date" className="input" required value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} />
                <input className="input" required placeholder={t('hr.ot.holiday_name_ph')} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                <button className="btn-primary"><Plus className="h-4 w-4" /> {t('common.add')}</button>
            </form>
            <ul className="divide-y text-sm">
                {list.data?.map((h) => (
                    <li key={h.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{formatDate(h.date)} · {h.name}</span>
                        {h.movable ? (confirm === h.id
                            ? <span className="flex gap-1"><button className="btn-secondary" onClick={() => remove(h.id)}>{t('hr.common.confirm_remove')}</button><button className="btn-secondary" onClick={() => setConfirm(null)}>{t('common.cancel')}</button></span>
                            : <button className="btn-secondary" aria-label={t('hr.common.remove')} onClick={() => setConfirm(h.id)}><Trash2 className="h-4 w-4" /></button>)
                            : <span className="badge badge-slate text-xs">{t('hr.ot.fixed')}</span>}
                    </li>
                ))}
            </ul>
        </div>
    );
}
