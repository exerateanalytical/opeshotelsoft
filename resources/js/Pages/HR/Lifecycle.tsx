import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatDate } from '@/lib/utils';
import { ArrowRightLeft, Check, FileSignature, Gift, Send, TrendingUp, X } from 'lucide-react';
import { useState } from 'react';
import SignaturePad from './SignaturePad';
import { Badge, Banner, Department, Employee, errMsg, Field, Paged, Position } from './shared';
import OpsPrintButton from '@/Components/OpsPrintButton';

type Emp = Pick<Employee, 'id' | 'number' | 'first_name' | 'last_name'>;
interface EDoc { id: number; number: string; kind: string; title: string; status: string; created_at: string; employee?: Emp; employee_signed_at: string | null; pdf_sha256: string | null }
interface Change { id: number; type: string; from_salary: number; to_salary: number; effective_date: string; status: string; reason: string | null; employee?: Emp }
interface Element { id: number; code: string; name: string; kind: string; amount: number; taxable: boolean; cnps: boolean; period: string | null; start_period: string | null; end_period: string | null; status: string; employee?: Emp }
interface Pool { id: number; period: string; amount: number; method: string; status: string; shares: { employee_id: number; weight: number; amount: number }[] }
interface Transfer { id: number; employee_id: number; from_hotel_id: number; to_hotel_id: number; effective_date: string; status: string; from_number: string | null; to_number: string | null; leave_balance: number | null; seniority_date: string | null }

const money = (n: number) => new Intl.NumberFormat('fr-FR').format(n);
const name = (e?: Emp) => (e ? `${e.number} · ${e.first_name} ${e.last_name}` : '');
const thisMonth = new Date().toISOString().slice(0, 7);
const todayIso = new Date().toISOString().slice(0, 10);

function useMsg() {
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    return { ok, err, good: (m: string) => (setOk(m), setErr(null)), bad: (e: unknown) => (setErr(errMsg(e)), setOk(null)) };
}

function EmployeePicker({ value, onChange }: { value: string; onChange: (v: string) => void }) {
    const t = useT();
    const list = useApi<Paged<Employee>>('hr/employees?status=active');
    return (
        <select className="input w-full" required value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">{t('hr2.common.choose_employee')}</option>
            {list.data?.data.map((e) => <option key={e.id} value={e.id}>{name(e)}</option>)}
        </select>
    );
}

function ContractsTab() {
    const t = useT();
    const m = useMsg();
    const docs = useApi<Paged<EDoc>>('hr/econtracts');
    const [emp, setEmp] = useState('');
    const [kind, setKind] = useState('');
    const [locale, setLocale] = useState('fr');
    const [link, setLink] = useState<string | null>(null);
    const [signing, setSigning] = useState<EDoc | null>(null);
    const [sig, setSig] = useState<string | null>(null);

    async function generate(e: React.FormEvent) {
        e.preventDefault();
        try { await api(`hr/employees/${emp}/econtracts`, { method: 'POST', body: JSON.stringify({ kind: kind || null, locale }) }); m.good(t('hr2.contracts.generated')); docs.reload(); } catch (x) { m.bad(x); }
    }
    async function send(d: EDoc) {
        try { const r = await api<{ url: string }>(`hr/econtracts/${d.id}/send`, { method: 'POST' }); setLink(r.url); m.good(t('hr2.contracts.sent')); docs.reload(); } catch (x) { m.bad(x); }
    }
    async function countersign() {
        if (!signing || !sig) return;
        try { await api(`hr/econtracts/${signing.id}/countersign`, { method: 'POST', body: JSON.stringify({ signature: sig }) }); setSigning(null); setSig(null); m.good(t('hr2.contracts.completed')); docs.reload(); } catch (x) { m.bad(x); }
    }
    async function cancel(d: EDoc) {
        try { await api(`hr/econtracts/${d.id}/cancel`, { method: 'POST' }); docs.reload(); } catch (x) { m.bad(x); }
    }

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? docs.error?.message} />
            {link && <div className="mb-4 break-all rounded-lg border bg-slate-50 p-3 text-sm">{t('hr2.contracts.link')}: <span className="font-mono">{link}</span></div>}
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={generate} className="card space-y-2">
                    <h3 className="font-semibold">{t('hr2.contracts.new')}</h3>
                    <Field label={t('hr2.common.employee')}><EmployeePicker value={emp} onChange={setEmp} /></Field>
                    <Field label={t('hr2.contracts.kind')}>
                        <select className="input w-full" value={kind} onChange={(e) => setKind(e.target.value)}>
                            <option value="">{t('hr2.contracts.kind_current')}</option>
                            {['CDI', 'CDD', 'stage', 'journalier'].map((k) => <option key={k} value={k}>{t(`hr2.kind.${k}`)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('hr2.common.language')}>
                        <select className="input w-full" value={locale} onChange={(e) => setLocale(e.target.value)}><option value="fr">Français</option><option value="en">English</option></select>
                    </Field>
                    <button className="btn-primary w-full"><FileSignature className="h-4 w-4" /> {t('hr2.contracts.generate')}</button>
                </form>
                <div className="card lg:col-span-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="text-left text-xs text-slate-500"><th>{t('hr2.common.number')}</th><th>{t('hr2.common.employee')}</th><th>{t('hr2.contracts.kind')}</th><th>{t('hr2.common.status')}</th><th></th></tr></thead>
                        <tbody>
                            {docs.data?.data.map((d) => (
                                <tr key={d.id} className="border-t">
                                    <td className="py-2">{d.number}</td><td>{name(d.employee)}</td><td>{t(`hr2.kind.${d.kind}`)}</td><td><Badge s={d.status} /></td>
                                    <td className="space-x-2 whitespace-nowrap text-right">
                                        <a className="text-emerald-700 underline" href={`/api/v1/hr/econtracts/${d.id}/pdf`} target="_blank" rel="noreferrer">PDF</a>
                                        {['draft', 'sent'].includes(d.status) && <button className="btn-secondary" onClick={() => send(d)}><Send className="h-3 w-3" /> {d.status === 'sent' ? t('hr2.contracts.resend') : t('hr2.contracts.send')}</button>}
                                        {d.status === 'employee_signed' && <button className="btn-primary" onClick={() => setSigning(d)}>{t('hr2.contracts.countersign')}</button>}
                                        {!['completed', 'cancelled'].includes(d.status) && <button className="text-slate-500" title={t('hr2.common.cancel')} onClick={() => cancel(d)}><X className="h-4 w-4" /></button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {signing && (
                <div className="card mt-5 max-w-lg">
                    <h3 className="mb-2 font-semibold">{t('hr2.contracts.countersign')} · {signing.number}</h3>
                    <SignaturePad onChange={setSig} clearLabel={t('hr2.sign.clear')} />
                    <div className="mt-3 flex gap-2">
                        <button className="btn-primary" disabled={!sig} onClick={countersign}><Check className="h-4 w-4" /> {t('hr2.contracts.countersign')}</button>
                        <button className="btn-secondary" onClick={() => setSigning(null)}>{t('hr2.common.cancel')}</button>
                    </div>
                </div>
            )}
        </div>
    );
}

function ChangesTab() {
    const t = useT();
    const m = useMsg();
    const list = useApi<Paged<Change>>('hr/compensation-changes');
    const positions = useApi<Position[]>('hr/positions');
    const [f, setF] = useState({ employee: '', type: 'raise', to_salary: '', to_position_id: '', effective_date: todayIso, reason: '' });
    const [hist, setHist] = useState<{ effective_date: string; base_salary: number; source: string }[] | null>(null);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api(`hr/employees/${f.employee}/compensation-changes`, { method: 'POST', body: JSON.stringify({ type: f.type, to_salary: Number(f.to_salary), to_position_id: f.to_position_id ? Number(f.to_position_id) : null, effective_date: f.effective_date, reason: f.reason || null }) });
            m.good(t('hr2.changes.requested')); list.reload();
            setHist(await api(`hr/employees/${f.employee}/salary-history`));
        } catch (x) { m.bad(x); }
    }
    async function decide(c: Change, approve: boolean) {
        try { await api(`hr/compensation-changes/${c.id}/decide`, { method: 'POST', body: JSON.stringify({ approve }) }); m.good(approve ? t('hr2.common.approved') : t('hr2.common.rejected')); list.reload(); } catch (x) { m.bad(x); }
    }

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? list.error?.message} />
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={submit} className="card space-y-2">
                    <h3 className="font-semibold">{t('hr2.changes.new')}</h3>
                    <Field label={t('hr2.common.employee')}><EmployeePicker value={f.employee} onChange={(v) => setF({ ...f, employee: v })} /></Field>
                    <Field label={t('hr2.changes.type')}>
                        <select className="input w-full" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}><option value="raise">{t('hr2.changes.raise')}</option><option value="promotion">{t('hr2.changes.promotion')}</option></select>
                    </Field>
                    <Field label={t('hr2.changes.new_salary')}><input type="number" min={0} required className="input w-full" value={f.to_salary} onChange={(e) => setF({ ...f, to_salary: e.target.value })} /></Field>
                    {f.type === 'promotion' && (
                        <Field label={t('hr2.changes.new_position')}>
                            <select className="input w-full" required value={f.to_position_id} onChange={(e) => setF({ ...f, to_position_id: e.target.value })}>
                                <option value="" />{positions.data?.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                            </select>
                        </Field>
                    )}
                    <Field label={t('hr2.common.effective_date')}><input type="date" required className="input w-full" value={f.effective_date} onChange={(e) => setF({ ...f, effective_date: e.target.value })} /></Field>
                    <Field label={t('hr2.common.reason')}><input className="input w-full" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
                    <button className="btn-primary w-full"><TrendingUp className="h-4 w-4" /> {t('hr2.changes.submit')}</button>
                    <p className="text-xs text-slate-500">{t('hr2.common.sod_hint')}</p>
                </form>
                <div className="card lg:col-span-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="text-left text-xs text-slate-500"><th>{t('hr2.common.employee')}</th><th>{t('hr2.changes.type')}</th><th className="text-right">{t('hr2.changes.salary')}</th><th>{t('hr2.common.effective_date')}</th><th>{t('hr2.common.status')}</th><th></th></tr></thead>
                        <tbody>
                            {list.data?.data.map((c) => (
                                <tr key={c.id} className="border-t">
                                    <td className="py-2">{name(c.employee)}</td><td>{t(`hr2.changes.${c.type}`)}</td>
                                    <td className="text-right">{money(c.from_salary)} → {money(c.to_salary)}</td><td>{formatDate(c.effective_date)}</td><td><Badge s={c.status} /></td>
                                    <td className="whitespace-nowrap text-right">{['approved', 'applied'].includes(c.status) && <OpsPrintButton path={`raise-letter/${c.id}`} label={t('documents4.raise')} />}{c.status === 'pending' && <>
                                        <button className="btn-secondary mr-1" onClick={() => decide(c, true)}><Check className="h-3 w-3" /></button>
                                        <button className="btn-secondary" onClick={() => decide(c, false)}><X className="h-3 w-3" /></button></>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {hist && <div className="mt-4"><h4 className="mb-1 text-sm font-semibold">{t('hr2.changes.history')}</h4>
                        <ul className="text-sm">{hist.map((h, i) => <li key={i}>{formatDate(h.effective_date)} · {t(`hr2.source.${h.source}`)} · {money(h.base_salary)}</li>)}</ul></div>}
                </div>
            </div>
        </div>
    );
}

function PayTab() {
    const t = useT();
    const m = useMsg();
    const [period, setPeriod] = useState(thisMonth);
    const els = useApi<Paged<Element>>(`hr/pay-elements?period=${period}`, [period]);
    const pools = useApi<Paged<Pool>>('hr/service-charge-pools');
    const emps = useApi<Paged<Employee>>('hr/employees?status=active');
    const [f, setF] = useState({ employee: '', code: '', name: '', kind: 'one_off', amount: '', taxable: true, cnps: true, end_period: '' });
    const [pool, setPool] = useState({ amount: '', method: 'points', weights: {} as Record<number, string> });

    async function add(e: React.FormEvent) {
        e.preventDefault();
        const body = { code: f.code, name: f.name, kind: f.kind, amount: Number(f.amount), taxable: f.taxable, cnps: f.cnps, ...(f.kind === 'one_off' ? { period } : { start_period: period, end_period: f.end_period || null }) };
        try { await api(`hr/employees/${f.employee}/pay-elements`, { method: 'POST', body: JSON.stringify(body) }); m.good(t('hr2.pay.added')); els.reload(); } catch (x) { m.bad(x); }
    }
    async function cancelEl(x: Element) {
        try { await api(`hr/pay-elements/${x.id}/cancel`, { method: 'POST' }); els.reload(); } catch (y) { m.bad(y); }
    }
    async function createPool(e: React.FormEvent) {
        e.preventDefault();
        const entries = Object.entries(pool.weights).filter(([, w]) => Number(w) > 0).map(([id, w]) => ({ employee_id: Number(id), weight: Number(w) }));
        try { await api('hr/service-charge-pools', { method: 'POST', body: JSON.stringify({ period, amount: Number(pool.amount), method: pool.method, entries }) }); m.good(t('hr2.pool.created')); pools.reload(); } catch (x) { m.bad(x); }
    }
    async function distribute(p: Pool) {
        try { await api(`hr/service-charge-pools/${p.id}/distribute`, { method: 'POST' }); m.good(t('hr2.pool.distributed')); pools.reload(); els.reload(); } catch (x) { m.bad(x); }
    }

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? els.error?.message} />
            <div className="mb-4 flex flex-wrap items-center gap-2"><span className="text-sm">{t('hr2.common.period')}</span><input type="month" className="input" value={period} onChange={(e) => setPeriod(e.target.value)} /></div>
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={add} className="card space-y-2">
                    <h3 className="font-semibold">{t('hr2.pay.new')}</h3>
                    <Field label={t('hr2.common.employee')}><EmployeePicker value={f.employee} onChange={(v) => setF({ ...f, employee: v })} /></Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label={t('hr2.pay.code')}><input required className="input w-full" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.toUpperCase() })} /></Field>
                        <Field label={t('hr2.pay.amount')}><input required type="number" min={1} className="input w-full" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} /></Field>
                    </div>
                    <Field label={t('hr2.pay.label')}><input required className="input w-full" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
                    <Field label={t('hr2.pay.kind')}>
                        <select className="input w-full" value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })}><option value="one_off">{t('hr2.pay.one_off')}</option><option value="recurring">{t('hr2.pay.recurring')}</option></select>
                    </Field>
                    {f.kind === 'recurring' && <Field label={t('hr2.pay.until')}><input type="month" className="input w-full" value={f.end_period} onChange={(e) => setF({ ...f, end_period: e.target.value })} /></Field>}
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.taxable} onChange={(e) => setF({ ...f, taxable: e.target.checked })} /> {t('hr2.pay.taxable')}</label>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.cnps} onChange={(e) => setF({ ...f, cnps: e.target.checked })} /> {t('hr2.pay.cnps')}</label>
                    <button className="btn-primary w-full"><Gift className="h-4 w-4" /> {t('hr2.pay.add')}</button>
                </form>
                <div className="card lg:col-span-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="text-left text-xs text-slate-500"><th>{t('hr2.common.employee')}</th><th>{t('hr2.pay.label')}</th><th>{t('hr2.pay.kind')}</th><th className="text-right">{t('hr2.pay.amount')}</th><th>{t('hr2.pay.flags')}</th><th></th></tr></thead>
                        <tbody>
                            {els.data?.data.map((x) => (
                                <tr key={x.id} className="border-t">
                                    <td className="py-2">{name(x.employee)}</td><td>{x.code} · {x.name}</td><td>{t(`hr2.pay.${x.kind}`)}</td><td className="text-right">{money(x.amount)}</td>
                                    <td className="text-xs">{x.taxable ? 'IRPP' : '—'} / {x.cnps ? 'CNPS' : '—'}</td>
                                    <td className="text-right"><button className="text-slate-500" title={t('hr2.common.cancel')} onClick={() => cancelEl(x)}><X className="h-4 w-4" /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={createPool} className="card space-y-2">
                    <h3 className="font-semibold">{t('hr2.pool.new')}</h3>
                    <Field label={t('hr2.pool.amount')}><input required type="number" min={1} className="input w-full" value={pool.amount} onChange={(e) => setPool({ ...pool, amount: e.target.value })} /></Field>
                    <Field label={t('hr2.pool.method')}>
                        <select className="input w-full" value={pool.method} onChange={(e) => setPool({ ...pool, method: e.target.value })}><option value="points">{t('hr2.pool.points')}</option><option value="days">{t('hr2.pool.days')}</option></select>
                    </Field>
                    <div className="max-h-64 space-y-1 overflow-y-auto">
                        {emps.data?.data.map((e) => (
                            <div key={e.id} className="flex items-center gap-2 text-sm">
                                <span className="flex-1 truncate">{name(e)}</span>
                                <input type="number" min={0} step="0.5" className="input w-20" value={pool.weights[e.id] ?? ''} onChange={(x) => setPool({ ...pool, weights: { ...pool.weights, [e.id]: x.target.value } })} />
                            </div>
                        ))}
                    </div>
                    <button className="btn-primary w-full">{t('hr2.pool.compute')}</button>
                </form>
                <div className="card lg:col-span-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="text-left text-xs text-slate-500"><th>{t('hr2.common.period')}</th><th className="text-right">{t('hr2.pool.amount')}</th><th>{t('hr2.pool.method')}</th><th>{t('hr2.pool.shares')}</th><th>{t('hr2.common.status')}</th><th></th></tr></thead>
                        <tbody>
                            {pools.data?.data.map((p) => (
                                <tr key={p.id} className="border-t align-top">
                                    <td className="py-2">{p.period}</td><td className="text-right">{money(p.amount)}</td><td>{t(`hr2.pool.${p.method}`)}</td>
                                    <td className="text-xs">{p.shares.map((s) => `#${s.employee_id}: ${money(s.amount)}`).join(' · ')}</td><td><Badge s={p.status} /></td>
                                    <td className="text-right">{p.status === 'draft' && <button className="btn-secondary" onClick={() => distribute(p)}>{t('hr2.pool.distribute')}</button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

function TransfersTab() {
    const t = useT();
    const m = useMsg();
    const hotels = usePage<{ hotels?: { id: number; name: string }[]; property?: { id: number } | null }>().props;
    const list = useApi<Paged<Transfer>>('hr/transfers');
    const [f, setF] = useState({ employee: '', to_hotel_id: '', to_department_id: '', to_position_id: '', new_base_salary: '', effective_date: todayIso, reason: '' });
    const [target, setTarget] = useState<{ depts: Department[]; positions: Position[] }>({ depts: [], positions: [] });
    const hotelName = (id: number) => hotels.hotels?.find((h) => h.id === id)?.name ?? `#${id}`;

    async function pickHotel(id: string) {
        setF({ ...f, to_hotel_id: id, to_department_id: '', to_position_id: '' });
        if (!id) return;
        try {
            const [depts, positions] = await Promise.all([api<Department[]>('hr/departments', { hotelId: id }), api<Position[]>('hr/positions', { hotelId: id })]);
            setTarget({ depts, positions });
        } catch (x) { m.bad(x); }
    }
    async function submit(e: React.FormEvent) {
        e.preventDefault();
        const num = (v: string) => (v ? Number(v) : null);
        try {
            await api(`hr/employees/${f.employee}/transfers`, { method: 'POST', body: JSON.stringify({ to_hotel_id: Number(f.to_hotel_id), to_department_id: num(f.to_department_id), to_position_id: num(f.to_position_id), new_base_salary: num(f.new_base_salary), effective_date: f.effective_date, reason: f.reason || null }) });
            m.good(t('hr2.transfers.requested')); list.reload();
        } catch (x) { m.bad(x); }
    }
    async function decide(x: Transfer, approve: boolean) {
        try { await api(`hr/transfers/${x.id}/decide`, { method: 'POST', body: JSON.stringify({ approve }) }); m.good(approve ? t('hr2.common.approved') : t('hr2.common.rejected')); list.reload(); } catch (y) { m.bad(y); }
    }

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? list.error?.message} />
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={submit} className="card space-y-2">
                    <h3 className="font-semibold">{t('hr2.transfers.new')}</h3>
                    <Field label={t('hr2.common.employee')}><EmployeePicker value={f.employee} onChange={(v) => setF({ ...f, employee: v })} /></Field>
                    <Field label={t('hr2.transfers.to_hotel')}>
                        <select className="input w-full" required value={f.to_hotel_id} onChange={(e) => pickHotel(e.target.value)}>
                            <option value="" />{hotels.hotels?.filter((h) => h.id !== hotels.property?.id).map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('hr2.transfers.department')}>
                        <select className="input w-full" value={f.to_department_id} onChange={(e) => setF({ ...f, to_department_id: e.target.value })}><option value="" />{target.depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select>
                    </Field>
                    <Field label={t('hr2.transfers.position')}>
                        <select className="input w-full" value={f.to_position_id} onChange={(e) => setF({ ...f, to_position_id: e.target.value })}><option value="" />{target.positions.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select>
                    </Field>
                    <Field label={t('hr2.transfers.new_salary')}><input type="number" min={0} className="input w-full" value={f.new_base_salary} onChange={(e) => setF({ ...f, new_base_salary: e.target.value })} /></Field>
                    <Field label={t('hr2.common.effective_date')}><input type="date" required className="input w-full" value={f.effective_date} onChange={(e) => setF({ ...f, effective_date: e.target.value })} /></Field>
                    <Field label={t('hr2.common.reason')}><input className="input w-full" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
                    <button className="btn-primary w-full"><ArrowRightLeft className="h-4 w-4" /> {t('hr2.transfers.submit')}</button>
                    <p className="text-xs text-slate-500">{t('hr2.transfers.hint')}</p>
                </form>
                <div className="card lg:col-span-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="text-left text-xs text-slate-500"><th>{t('hr2.common.number')}</th><th>{t('hr2.transfers.route')}</th><th>{t('hr2.common.effective_date')}</th><th>{t('hr2.transfers.leave')}</th><th>{t('hr2.common.status')}</th><th></th></tr></thead>
                        <tbody>
                            {list.data?.data.map((x) => (
                                <tr key={x.id} className="border-t">
                                    <td className="py-2">{x.from_number}{x.to_number ? ` → ${x.to_number}` : ''}</td>
                                    <td>{hotelName(x.from_hotel_id)} → {hotelName(x.to_hotel_id)}</td><td>{formatDate(x.effective_date)}</td>
                                    <td>{x.leave_balance ?? '—'}</td><td><Badge s={x.status} /></td>
                                    <td className="whitespace-nowrap text-right">{['approved', 'completed'].includes(x.status) && <OpsPrintButton path={`transfer-letter/${x.id}`} label={t('documents4.transfer_letter')} />}{x.status === 'pending' && <>
                                        <button className="btn-secondary mr-1" onClick={() => decide(x, true)}><Check className="h-3 w-3" /></button>
                                        <button className="btn-secondary" onClick={() => decide(x, false)}><X className="h-3 w-3" /></button></>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

const TABS = ['contracts', 'changes', 'pay', 'transfers'] as const;

export default function Lifecycle() {
    const t = useT();
    const [tab, setTab] = useState<(typeof TABS)[number]>('contracts');
    return (
        <AppLayout title={t('hr2.title')} subtitle={t('hr2.subtitle')}>
            <Head title={t('hr2.title')} />
            <div className="mb-5 flex gap-1 overflow-x-auto border-b">
                {TABS.map((k) => (
                    <button key={k} onClick={() => setTab(k)}
                        className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium ${tab === k ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>{t(`hr2.tabs.${k}`)}</button>
                ))}
            </div>
            {tab === 'contracts' && <ContractsTab />}
            {tab === 'changes' && <ChangesTab />}
            {tab === 'pay' && <PayTab />}
            {tab === 'transfers' && <TransfersTab />}
        </AppLayout>
    );
}
