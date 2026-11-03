import { api, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Loader2, Plus, Search, UserMinus, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import { Badge, Banner, Department, Employee, errMsg, Field, Paged, Position } from './shared';
import { useT } from '@/lib/i18n';

const emptyHire = {
    first_name: '', last_name: '', gender: '', date_of_birth: '', phone: '', email: '', address: '',
    cnps_number: '', tax_number: '', id_number: '', marital_status: '', children: '0',
    payment_method: 'bank', bank_name: '', bank_account: '', momo_number: '',
    department_id: '', position_id: '', hire_date: new Date().toISOString().slice(0, 10),
    kind: 'CDI', end_date: '', probation_end: '', base_salary: '', hours_per_week: '40',
};

const opt = (v: string) => (v === '' ? null : v);
const optInt = (v: string) => (v === '' ? null : Number(v));

export default function EmployeesTab() {
    const t = useT();
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [dept, setDept] = useState('');
    const [selected, setSelected] = useState<number | null>(null);
    const [hiring, setHiring] = useState(false);
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);

    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    if (dept) params.set('department_id', dept);
    const list = useApi<Paged<Employee>>(`hr/employees?${params}`);
    const depts = useApi<Department[]>('hr/departments');
    const positions = useApi<Position[]>('hr/positions');

    return (
        <div>
            <Banner ok={ok} err={err ?? list.error?.message} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <div className="relative min-w-[200px] flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input className="input pl-9" placeholder={t('hr.emp.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <select className="input w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('hr.common.all_statuses')}</option>
                    {['active', 'on_leave', 'suspended', 'terminated'].map((s) => <option key={s} value={s}>{t(`hr.status.${s}`)}</option>)}
                </select>
                <select className="input w-44" value={dept} onChange={(e) => setDept(e.target.value)}>
                    <option value="">{t('hr.common.all_departments')}</option>
                    {depts.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
                <button className="btn-primary" onClick={() => (setHiring(true), setSelected(null))}><Plus className="h-4 w-4" /> {t('hr.emp.hire')}</button>
            </div>

            {hiring && (
                <HireForm depts={depts.data ?? []} positions={positions.data ?? []} onClose={() => setHiring(false)}
                    onDone={(m) => (setOk(m), setErr(null), setHiring(false), list.reload())} onError={(m) => (setErr(m), setOk(null))} />
            )}
            {selected && (
                <EmployeeDetail id={selected} depts={depts.data ?? []} positions={positions.data ?? []} onClose={() => setSelected(null)}
                    onDone={(m) => (setOk(m), setErr(null), list.reload())} onError={(m) => (setErr(m), setOk(null))} />
            )}

            <div className="card overflow-x-auto p-0">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-3">{t('hr.common.no')}</th><th>{t('hr.common.name')}</th><th>{t('hr.common.department')}</th><th>{t('hr.emp.hired_on')}</th><th>{t('hr.common.base_salary')}</th><th>{t('hr.common.status')}</th></tr></thead>
                    <tbody>
                        {list.loading && <tr><td colSpan={6} className="p-6 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-slate-400" /></td></tr>}
                        {!list.loading && list.data?.data.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-slate-400">{t('hr.emp.none')}</td></tr>}
                        {list.data?.data.map((e) => (
                            <tr key={e.id} className="cursor-pointer border-t hover:bg-slate-50" onClick={() => (setSelected(e.id), setHiring(false))}>
                                <td className="p-3 font-mono text-xs">{e.number}</td>
                                <td className="font-medium">{e.last_name} {e.first_name}</td>
                                <td>{e.department?.name ?? '—'}</td>
                                <td>{formatDate(e.hire_date)}</td>
                                <td>{formatCurrency(e.base_salary)}</td>
                                <td><Badge s={e.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {list.data && <p className="p-3 text-xs text-slate-400">{t('hr.emp.count', { count: list.data.total })}{list.data.last_page > 1 ? t('hr.emp.first_50') : ''}</p>}
            </div>
        </div>
    );
}

interface FormProps { depts: Department[]; positions: Position[]; onClose: () => void; onDone: (m: string) => void; onError: (m: string) => void }

function HireForm({ depts, positions, onClose, onDone, onError }: FormProps) {
    const t = useT();
    const [f, setF] = useState(emptyHire);
    const [busy, setBusy] = useState(false);
    const set = (k: keyof typeof emptyHire) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        try {
            const body = {
                first_name: f.first_name, last_name: f.last_name, gender: opt(f.gender), date_of_birth: opt(f.date_of_birth),
                phone: opt(f.phone), email: opt(f.email), address: opt(f.address), cnps_number: opt(f.cnps_number), tax_number: opt(f.tax_number),
                id_number: opt(f.id_number), marital_status: opt(f.marital_status), children: Number(f.children || 0),
                payment_method: f.payment_method, bank_name: opt(f.bank_name), bank_account: opt(f.bank_account), momo_number: opt(f.momo_number),
                department_id: optInt(f.department_id), position_id: optInt(f.position_id), hire_date: f.hire_date,
                contract: {
                    kind: f.kind, start_date: f.hire_date, end_date: opt(f.end_date), probation_end: opt(f.probation_end),
                    base_salary: Number(f.base_salary || 0), hours_per_week: Number(f.hours_per_week || 40),
                },
            };
            const emp = await api<Employee>('hr/employees', { method: 'POST', body: JSON.stringify(body) });
            onDone(t('hr.emp.hired_msg', { name: `${emp.first_name} ${emp.last_name}`, number: emp.number }));
        } catch (x) {
            onError(errMsg(x));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="card mb-5">
            <div className="mb-4 flex items-center justify-between"><h3 className="font-semibold">{t('hr.emp.hire_title')}</h3><button type="button" onClick={onClose}><X className="h-4 w-4" /></button></div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field label={t('hr.emp.first_name_req')}><input className="input" required value={f.first_name} onChange={set('first_name')} /></Field>
                <Field label={t('hr.emp.last_name_req')}><input className="input" required value={f.last_name} onChange={set('last_name')} /></Field>
                <Field label={t('hr.emp.gender')}><select className="input" value={f.gender} onChange={set('gender')}><option value="">—</option><option value="M">M</option><option value="F">F</option></select></Field>
                <Field label={t('hr.emp.dob')}><input type="date" className="input" value={f.date_of_birth} onChange={set('date_of_birth')} /></Field>
                <Field label={t('hr.common.phone')}><input className="input" value={f.phone} onChange={set('phone')} /></Field>
                <Field label={t('hr.common.email')}><input type="email" className="input" value={f.email} onChange={set('email')} /></Field>
                <Field label={t('hr.common.address')}><input className="input" value={f.address} onChange={set('address')} /></Field>
                <Field label={t('hr.emp.id_number')}><input className="input" value={f.id_number} onChange={set('id_number')} /></Field>
                <Field label={t('hr.emp.cnps_number')}><input className="input" value={f.cnps_number} onChange={set('cnps_number')} /></Field>
                <Field label={t('hr.emp.tax_number_niu')}><input className="input" value={f.tax_number} onChange={set('tax_number')} /></Field>
                <Field label={t('hr.emp.marital_status')}><select className="input" value={f.marital_status} onChange={set('marital_status')}><option value="">—</option><option value="single">{t('hr.emp.marital.single')}</option><option value="married">{t('hr.emp.marital.married')}</option><option value="divorced">{t('hr.emp.marital.divorced')}</option><option value="widowed">{t('hr.emp.marital.widowed')}</option></select></Field>
                <Field label={t('hr.emp.children')}><input type="number" min={0} max={20} className="input" value={f.children} onChange={set('children')} /></Field>
                <Field label={t('hr.common.department')}><select className="input" value={f.department_id} onChange={set('department_id')}><option value="">—</option>{depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></Field>
                <Field label={t('hr.common.position')}><select className="input" value={f.position_id} onChange={set('position_id')}><option value="">—</option>{positions.filter((p) => !f.department_id || p.department_id === Number(f.department_id)).map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select></Field>
                <Field label={t('hr.emp.hire_date_req')}><input type="date" required className="input" value={f.hire_date} onChange={set('hire_date')} /></Field>
                <Field label={t('hr.emp.contract_req')}><select className="input" value={f.kind} onChange={set('kind')}>{['CDI', 'CDD', 'stage', 'journalier'].map((k) => <option key={k}>{k}</option>)}</select></Field>
                <Field label={f.kind === 'CDD' ? t('hr.emp.contract_end_req') : t('hr.emp.contract_end')}><input type="date" className="input" required={f.kind === 'CDD'} value={f.end_date} onChange={set('end_date')} /></Field>
                <Field label={t('hr.emp.probation_end')}><input type="date" className="input" value={f.probation_end} onChange={set('probation_end')} /></Field>
                <Field label={t('hr.emp.base_salary_month_req')}><input type="number" min={0} required className="input" value={f.base_salary} onChange={set('base_salary')} /></Field>
                <Field label={t('hr.emp.hours_week')}><input type="number" min={1} max={60} className="input" value={f.hours_per_week} onChange={set('hours_per_week')} /></Field>
                <Field label={t('hr.emp.payment_method')}><select className="input" value={f.payment_method} onChange={set('payment_method')}><option value="bank">{t('hr.emp.pay.bank')}</option><option value="mobile_money">{t('hr.emp.pay.mobile_money')}</option><option value="cash">{t('hr.emp.pay.cash')}</option></select></Field>
                {f.payment_method === 'bank' && <>
                    <Field label={t('hr.emp.pay.bank')}><input className="input" value={f.bank_name} onChange={set('bank_name')} /></Field>
                    <Field label={t('hr.emp.bank_account')}><input className="input" value={f.bank_account} onChange={set('bank_account')} /></Field>
                </>}
                {f.payment_method === 'mobile_money' && <Field label={t('hr.emp.momo_number')}><input className="input" value={f.momo_number} onChange={set('momo_number')} /></Field>}
            </div>
            <div className="mt-4 flex justify-end gap-2">
                <button type="button" className="btn-secondary" onClick={onClose}>{t('common.cancel')}</button>
                <button className="btn-primary" disabled={busy}>{busy && <Loader2 className="h-4 w-4 animate-spin" />} {t('hr.emp.hire')}</button>
            </div>
        </form>
    );
}

function EmployeeDetail({ id, depts, positions, onClose, onDone, onError }: FormProps & { id: number }) {
    const t = useT();
    const emp = useApi<Employee>(`hr/employees/${id}`);
    const advances = useApi<{ id: number; kind: string; amount: number; monthly_deduction: number; balance: number; granted_on: string; status: string }[]>(`hr/employees/${id}/advances`);
    const [panel, setPanel] = useState<'edit' | 'terminate' | 'advance' | null>(null);
    const [busy, setBusy] = useState(false);
    const [edit, setEdit] = useState<Record<string, string>>({});
    const [term, setTerm] = useState({ date: new Date().toISOString().slice(0, 10), reason: '' });
    const [adv, setAdv] = useState({ kind: 'advance', amount: '', monthly_deduction: '' });
    const e = emp.data;

    function openEdit() {
        if (!e) return;
        setEdit({
            phone: e.phone ?? '', email: e.email ?? '', address: e.address ?? '', department_id: e.department_id ? String(e.department_id) : '',
            position_id: e.position_id ? String(e.position_id) : '', base_salary: String(e.base_salary), payment_method: e.payment_method,
            bank_account: e.bank_account ?? '', momo_number: e.momo_number ?? '', status: e.status === 'terminated' ? 'active' : e.status,
            cnps_number: e.cnps_number ?? '', tax_number: e.tax_number ?? '',
        });
        setPanel('edit');
    }

    async function run(path: string, method: string, body: unknown, msg: string) {
        setBusy(true);
        try {
            await api(path, { method, body: JSON.stringify(body) });
            onDone(msg);
            setPanel(null);
            emp.reload();
            advances.reload();
        } catch (x) {
            onError(errMsg(x));
        } finally {
            setBusy(false);
        }
    }

    const se = (k: string) => (ev: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setEdit({ ...edit, [k]: ev.target.value });

    return (
        <div className="card mb-5">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="font-semibold">{e ? `${e.first_name} ${e.last_name} · ${e.number}` : t('hr.emp.employee')}</h3>
                <button onClick={onClose}><X className="h-4 w-4" /></button>
            </div>
            {emp.loading && <Loader2 className="h-5 w-5 animate-spin text-slate-400" />}
            {emp.error && <p className="text-sm text-red-600">{emp.error.message}</p>}
            {e && (
                <>
                    <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><dt className="text-slate-500">{t('hr.common.status')}</dt><dd><Badge s={e.status} /></dd></div>
                        <div><dt className="text-slate-500">{t('hr.common.department')}</dt><dd>{e.department?.name ?? '—'}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.common.position')}</dt><dd>{positions.find((p) => p.id === e.position_id)?.title ?? '—'}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.emp.hired_on')}</dt><dd>{formatDate(e.hire_date)}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.common.base_salary')}</dt><dd>{formatCurrency(e.base_salary)}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.emp.leave_balance')}</dt><dd>{t('hr.emp.days', { count: e.leave_balance ?? '—' })}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.emp.payment')}</dt><dd>{t(`hr.emp.pay.${e.payment_method}`)} {e.bank_account ?? e.momo_number ?? ''}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.emp.phone_email')}</dt><dd>{e.phone ?? '—'} {e.email ?? ''}</dd></div>
                        <div><dt className="text-slate-500">{t('hr.emp.cnps_niu')}</dt><dd>{e.cnps_number ?? '—'} / {e.tax_number ?? '—'}</dd></div>
                        {e.termination_date && <div className="col-span-2"><dt className="text-slate-500">{t('hr.status.terminated')}</dt><dd>{formatDate(e.termination_date)} — {e.termination_reason}</dd></div>}
                    </dl>
                    {e.contracts && e.contracts.length > 0 && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="table-auto w-full text-sm">
                                <thead><tr className="text-left text-slate-500"><th>{t('hr.common.contract')}</th><th>{t('hr.common.start')}</th><th>{t('hr.common.end')}</th><th>{t('hr.common.salary')}</th><th>{t('hr.common.status')}</th></tr></thead>
                                <tbody>{e.contracts.map((c) => <tr key={c.id} className="border-t"><td>{c.kind}</td><td>{formatDate(c.start_date)}</td><td>{c.end_date ? formatDate(c.end_date) : '—'}</td><td>{formatCurrency(c.base_salary)}</td><td>{c.status}</td></tr>)}</tbody>
                            </table>
                        </div>
                    )}
                    {advances.data && advances.data.length > 0 && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="table-auto w-full text-sm">
                                <thead><tr className="text-left text-slate-500"><th>{t('hr.emp.advance_loan')}</th><th>{t('hr.emp.granted')}</th><th>{t('hr.common.amount')}</th><th>{t('hr.emp.monthly')}</th><th>{t('hr.emp.remaining')}</th><th>{t('hr.common.status')}</th></tr></thead>
                                <tbody>{advances.data.map((a) => <tr key={a.id} className="border-t"><td>{a.kind}</td><td>{formatDate(a.granted_on)}</td><td>{formatCurrency(a.amount)}</td><td>{formatCurrency(a.monthly_deduction)}</td><td className="font-semibold">{formatCurrency(a.balance)}</td><td>{a.status}</td></tr>)}</tbody>
                            </table>
                        </div>
                    )}
                    {e.status !== 'terminated' && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button className="btn-secondary" onClick={openEdit}>{t('common.edit')}</button>
                            <button className="btn-secondary" onClick={() => setPanel('advance')}><Wallet className="h-4 w-4" /> {t('hr.emp.salary_advance')}</button>
                            <button className="btn-secondary text-red-600" onClick={() => setPanel('terminate')}><UserMinus className="h-4 w-4" /> {t('hr.emp.terminate')}</button>
                        </div>
                    )}

                    {panel === 'edit' && (
                        <form className="mt-4 border-t pt-4" onSubmit={(ev) => {
                            ev.preventDefault();
                            run(`hr/employees/${e.id}`, 'PATCH', {
                                phone: opt(edit.phone), email: opt(edit.email), address: opt(edit.address), department_id: optInt(edit.department_id),
                                position_id: optInt(edit.position_id), base_salary: Number(edit.base_salary || 0), payment_method: edit.payment_method,
                                bank_account: opt(edit.bank_account), momo_number: opt(edit.momo_number), status: edit.status,
                                cnps_number: opt(edit.cnps_number), tax_number: opt(edit.tax_number),
                            }, t('hr.emp.updated'));
                        }}>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <Field label={t('hr.common.phone')}><input className="input" value={edit.phone} onChange={se('phone')} /></Field>
                                <Field label={t('hr.common.email')}><input type="email" className="input" value={edit.email} onChange={se('email')} /></Field>
                                <Field label={t('hr.common.address')}><input className="input" value={edit.address} onChange={se('address')} /></Field>
                                <Field label={t('hr.common.status')}><select className="input" value={edit.status} onChange={se('status')}><option value="active">{t('hr.status.active')}</option><option value="on_leave">{t('hr.status.on_leave')}</option><option value="suspended">{t('hr.status.suspended')}</option></select></Field>
                                <Field label={t('hr.common.department')}><select className="input" value={edit.department_id} onChange={se('department_id')}><option value="">—</option>{depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></Field>
                                <Field label={t('hr.common.position')}><select className="input" value={edit.position_id} onChange={se('position_id')}><option value="">—</option>{positions.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select></Field>
                                <Field label={t('hr.emp.base_salary_fcfa')}><input type="number" min={0} className="input" value={edit.base_salary} onChange={se('base_salary')} /></Field>
                                <Field label={t('hr.emp.payment_method')}><select className="input" value={edit.payment_method} onChange={se('payment_method')}><option value="bank">{t('hr.emp.pay.bank')}</option><option value="mobile_money">{t('hr.emp.pay.mobile_money')}</option><option value="cash">{t('hr.emp.pay.cash')}</option></select></Field>
                                <Field label={t('hr.emp.bank_account')}><input className="input" value={edit.bank_account} onChange={se('bank_account')} /></Field>
                                <Field label={t('hr.emp.pay.mobile_money')}><input className="input" value={edit.momo_number} onChange={se('momo_number')} /></Field>
                                <Field label={t('hr.emp.cnps_number')}><input className="input" value={edit.cnps_number} onChange={se('cnps_number')} /></Field>
                                <Field label={t('hr.emp.tax_number')}><input className="input" value={edit.tax_number} onChange={se('tax_number')} /></Field>
                            </div>
                            <div className="mt-3 flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setPanel(null)}>{t('common.cancel')}</button><button className="btn-primary" disabled={busy}>{t('common.save')}</button></div>
                        </form>
                    )}

                    {panel === 'terminate' && (
                        <form className="mt-4 border-t pt-4" onSubmit={(ev) => { ev.preventDefault(); run(`hr/employees/${e.id}/terminate`, 'POST', term, t('hr.emp.terminated_msg')); }}>
                            <p className="mb-3 rounded bg-amber-50 p-2 text-sm text-amber-700">{t('hr.emp.terminate_warning')}</p>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <Field label={t('hr.common.date_req')}><input type="date" required className="input" value={term.date} onChange={(ev) => setTerm({ ...term, date: ev.target.value })} /></Field>
                                <div className="sm:col-span-2"><Field label={t('hr.common.reason_req')}><input required className="input" value={term.reason} onChange={(ev) => setTerm({ ...term, reason: ev.target.value })} /></Field></div>
                            </div>
                            <div className="mt-3 flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setPanel(null)}>{t('common.cancel')}</button><button className="btn-primary bg-red-600 hover:bg-red-700" disabled={busy}>{t('hr.emp.terminate')}</button></div>
                        </form>
                    )}

                    {panel === 'advance' && (
                        <form className="mt-4 border-t pt-4" onSubmit={(ev) => {
                            ev.preventDefault();
                            run(`hr/employees/${e.id}/advances`, 'POST', { kind: adv.kind, amount: Number(adv.amount), monthly_deduction: Number(adv.monthly_deduction) },
                                t('hr.emp.advance_granted', { kind: t(`hr.emp.${adv.kind}`), amount: formatCurrency(Number(adv.amount)) }));
                        }}>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <Field label={t('hr.common.kind')}><select className="input" value={adv.kind} onChange={(ev) => setAdv({ ...adv, kind: ev.target.value })}><option value="advance">{t('hr.emp.advance')}</option><option value="loan">{t('hr.emp.loan')}</option></select></Field>
                                <Field label={t('hr.common.amount_fcfa_req')}><input type="number" min={1} required className="input" value={adv.amount} onChange={(ev) => setAdv({ ...adv, amount: ev.target.value })} /></Field>
                                <Field label={t('hr.emp.monthly_deduction_req')}><input type="number" min={1} required className="input" value={adv.monthly_deduction} onChange={(ev) => setAdv({ ...adv, monthly_deduction: ev.target.value })} /></Field>
                            </div>
                            <div className="mt-3 flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setPanel(null)}>{t('common.cancel')}</button><button className="btn-primary" disabled={busy}>{t('hr.emp.grant')}</button></div>
                        </form>
                    )}
                </>
            )}
        </div>
    );
}
