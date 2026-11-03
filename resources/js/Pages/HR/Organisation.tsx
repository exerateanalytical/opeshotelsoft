import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Check, ChevronLeft, ChevronRight, LogIn, LogOut, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { Badge, Banner, Department, Employee, errMsg, Field, Paged, Position } from './shared';
import { useLocale, useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';

function useMsg() {
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    return { ok, err, good: (m: string) => (setOk(m), setErr(null)), bad: (e: unknown) => (setErr(errMsg(e)), setOk(null)) };
}

export function OrgTab() {
    const t = useT();
    const depts = useApi<Department[]>('hr/departments');
    const positions = useApi<Position[]>('hr/positions');
    const m = useMsg();
    const [d, setD] = useState({ code: '', name: '', cost_center: '' });
    const [p, setP] = useState({ department_id: '', title: '', category: '' });

    async function saveDept(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api('hr/departments', { method: 'PUT', body: JSON.stringify({ ...d, cost_center: d.cost_center || null }) });
            m.good(t('hr.org.dept_saved', { code: d.code })); setD({ code: '', name: '', cost_center: '' }); depts.reload();
        } catch (x) { m.bad(x); }
    }
    async function savePos(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api('hr/positions', { method: 'POST', body: JSON.stringify({ department_id: Number(p.department_id), title: p.title, category: p.category || null }) });
            m.good(t('hr.org.pos_created', { title: p.title })); setP({ department_id: p.department_id, title: '', category: '' }); positions.reload();
        } catch (x) { m.bad(x); }
    }

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? depts.error?.message} />
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div className="card">
                    <h3 className="mb-3 font-semibold">{t('hr.org.departments')}</h3>
                    <form onSubmit={saveDept} className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-4">
                        <input className="input" required maxLength={12} placeholder={t('hr.common.code')} value={d.code} onChange={(e) => setD({ ...d, code: e.target.value })} />
                        <input className="input" required placeholder={t('hr.common.name')} value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} />
                        <input className="input" placeholder={t('hr.org.cost_center')} value={d.cost_center} onChange={(e) => setD({ ...d, cost_center: e.target.value })} />
                        <button className="btn-primary"><Plus className="h-4 w-4" /> {t('common.save')}</button>
                    </form>
                    <p className="mb-2 text-xs text-slate-500">{t('hr.org.dept_hint')}</p>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th>{t('hr.common.code')}</th><th>{t('hr.common.name')}</th><th>{t('hr.org.cost_center')}</th></tr></thead>
                            <tbody>{depts.data?.map((x) => (
                                <tr key={x.id} className="cursor-pointer border-t hover:bg-slate-50" onClick={() => setD({ code: x.code, name: x.name, cost_center: x.cost_center ?? '' })}>
                                    <td className="py-2 font-mono text-xs">{x.code}</td><td>{x.name}</td><td>{x.cost_center ?? '—'}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                </div>
                <div className="card">
                    <h3 className="mb-3 font-semibold">{t('hr.org.positions')}</h3>
                    <form onSubmit={savePos} className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-4">
                        <select className="input" required value={p.department_id} onChange={(e) => setP({ ...p, department_id: e.target.value })}>
                            <option value="">{t('hr.org.department_pick')}</option>{depts.data?.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </select>
                        <input className="input" required placeholder={t('hr.org.title')} value={p.title} onChange={(e) => setP({ ...p, title: e.target.value })} />
                        <input className="input" placeholder={t('hr.org.category_echelon')} value={p.category} onChange={(e) => setP({ ...p, category: e.target.value })} />
                        <button className="btn-primary"><Plus className="h-4 w-4" /> {t('common.add')}</button>
                    </form>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th>{t('hr.org.title')}</th><th>{t('hr.common.department')}</th><th>{t('hr.org.category')}</th></tr></thead>
                            <tbody>{positions.data?.map((x) => (
                                <tr key={x.id} className="border-t"><td className="py-2">{x.title}</td><td>{depts.data?.find((y) => y.id === x.department_id)?.name ?? '—'}</td><td>{x.category ?? '—'}</td></tr>
                            ))}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}

interface EmpRef { id: number; number: string; first_name: string; last_name: string; full_name: string }
interface Shift { id: number; employee_id: number; date: string; starts_at: string; ends_at: string; role: string | null; employee: EmpRef | null }
interface Attendance { id: number; employee_id: number; date: string; clock_in: string; clock_out: string | null; worked_minutes: number | null; overtime_minutes: number | null; source: string; employee: EmpRef | null }
interface ClockStatus { clocked_in: boolean; since: string | null }

const iso = (d: Date) => d.toISOString().slice(0, 10);
function monday(d: Date) { const x = new Date(d); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; }

export function ShiftsTab() {
    const t = useT();
    const locale = useLocale();
    const [start, setStart] = useState(() => monday(new Date()));
    const days = Array.from({ length: 7 }, (_, i) => { const x = new Date(start); x.setDate(x.getDate() + i); return iso(x); });
    const shifts = useApi<Shift[]>(`hr/shifts?from=${days[0]}&to=${days[6]}`);
    const emps = useApi<Paged<Employee>>('hr/employees?status=active');
    const m = useMsg();
    const [f, setF] = useState({ employee_id: '', date: days[0], starts_at: '07:00', ends_at: '15:00', role: '' });
    const [clockBusy, setClockBusy] = useState(false);
    const status = useApi<ClockStatus>('hr/clock/status');
    const att = useApi<Paged<Attendance>>(`hr/attendance?from=${days[0]}&to=${days[6]}`);

    async function add(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api('hr/shifts', { method: 'PUT', body: JSON.stringify({ shifts: [{ ...f, employee_id: Number(f.employee_id), role: f.role || null }] }) });
            m.good(t('hr.shift.saved')); shifts.reload();
        } catch (x) { m.bad(x); }
    }
    async function clock(dir: 'in' | 'out') {
        setClockBusy(true);
        try {
            const a = await api<{ clock_in: string; clock_out: string | null; worked_minutes: number; overtime_minutes: number }>(`hr/clock/${dir}`, { method: 'POST', body: '{}' });
            m.good(dir === 'in' ? t('hr.shift.clocked_in_at', { time: new Date(a.clock_in).toLocaleTimeString() }) : t('hr.shift.clocked_out', { worked: `${Math.floor(a.worked_minutes / 60)}h${String(a.worked_minutes % 60).padStart(2, '0')}`, overtime: a.overtime_minutes ? t('hr.shift.overtime_min', { min: a.overtime_minutes }) : '' }));
            status.reload(); att.reload();
        } catch (x) { m.bad(x); } finally { setClockBusy(false); }
    }
    const shift = (n: number) => { const x = new Date(start); x.setDate(x.getDate() + n); setStart(x); };
    const people = emps.data?.data ?? [];
    const byEmp = new Map<number, Shift[]>();
    const who = new Map<number, EmpRef>();
    shifts.data?.forEach((s) => { byEmp.set(s.employee_id, [...(byEmp.get(s.employee_id) ?? []), s]); if (s.employee) who.set(s.employee_id, s.employee); });
    const label = (id: number) => { const w = who.get(id); return w ? `${w.last_name} ${w.first_name}` : `#${id}`; };
    const rows = [...byEmp.keys()].map((id) => ({ id, name: label(id) })).sort((a, b) => a.name.localeCompare(b.name));
    const linked = !status.error;
    const hm = (t: string) => t.slice(0, 5);

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? shifts.error?.message} />
            <div className="card mb-5 flex flex-wrap items-center gap-3">
                <span className="text-sm font-medium text-slate-700">{t('hr.shift.my_attendance')}</span>
                {linked && status.data && (status.data.clocked_in
                    ? <><span className="text-sm text-emerald-700">{t('hr.shift.clocked_in_since', { time: status.data.since ? new Date(status.data.since).toLocaleTimeString() : '—' })}</span>
                        <button className="btn-secondary" disabled={clockBusy} onClick={() => clock('out')}><LogOut className="h-4 w-4" /> {t('hr.shift.clock_out')}</button></>
                    : <><span className="text-sm text-slate-500">{t('hr.shift.not_clocked')}</span>
                        <button className="btn-primary" disabled={clockBusy} onClick={() => clock('in')}><LogIn className="h-4 w-4" /> {t('hr.shift.clock_in')}</button></>)}
                {!linked && <span className="text-xs text-slate-500">{status.error?.message}</span>}
            </div>
            <form onSubmit={add} className="card mb-5 grid grid-cols-1 gap-2 sm:grid-cols-6">
                <select className="input sm:col-span-2" required value={f.employee_id} onChange={(e) => setF({ ...f, employee_id: e.target.value })}>
                    <option value="">{t('hr.shift.employee_pick')}</option>{people.map((p) => <option key={p.id} value={p.id}>{p.last_name} {p.first_name}</option>)}
                </select>
                <input type="date" className="input" required value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} />
                <div className="flex flex-wrap gap-1"><input type="time" className="input" required value={f.starts_at} onChange={(e) => setF({ ...f, starts_at: e.target.value })} /><input type="time" className="input" required value={f.ends_at} onChange={(e) => setF({ ...f, ends_at: e.target.value })} /></div>
                <input className="input" placeholder={t('hr.shift.role_ph')} value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })} />
                <button className="btn-primary"><Plus className="h-4 w-4" /> {t('hr.shift.add')}</button>
            </form>
            <div className="mb-3 flex items-center gap-2">
                <button className="btn-secondary" onClick={() => shift(-7)}><ChevronLeft className="h-4 w-4" /></button>
                <span className="text-sm font-medium">{t('hr.shift.week_of', { date: formatDate(days[0]) })}</span>
                <button className="btn-secondary" onClick={() => shift(7)}><ChevronRight className="h-4 w-4" /></button>
                <button className="btn-secondary" onClick={() => setStart(monday(new Date()))}>{t('hr.shift.this_week')}</button>
            </div>
            <div className="card overflow-x-auto p-0">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-3">{t('hr.emp.employee')}</th>{days.map((d) => <th key={d} className="whitespace-nowrap px-2">{new Date(d).toLocaleDateString(locale, { weekday: 'short', day: 'numeric' })}</th>)}</tr></thead>
                    <tbody>
                        {rows.length === 0 && <tr><td colSpan={8} className="p-6 text-center text-slate-500">{t('hr.shift.none')}</td></tr>}
                        {rows.map((p) => (
                            <tr key={p.id} className="border-t align-top">
                                <td className="p-3 whitespace-nowrap font-medium">{p.name}</td>
                                {days.map((d) => (
                                    <td key={d} className="px-2 py-2">
                                        {byEmp.get(p.id)!.filter((s) => s.date.slice(0, 10) === d).map((s) => (
                                            <div key={s.id} className="mb-1 whitespace-nowrap rounded bg-emerald-50 px-1.5 py-0.5 text-xs text-emerald-700">{hm(s.starts_at)}–{hm(s.ends_at)}{s.role ? ` ${s.role}` : ''}</div>
                                        ))}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="card mt-5 overflow-x-auto">
                <h3 className="mb-3 font-semibold">{t('hr.shift.attendance_week')}</h3>
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th>{t('hr.emp.employee')}</th><th>{t('hr.common.date')}</th><th>{t('hr.shift.in')}</th><th>{t('hr.shift.out')}</th><th>{t('hr.shift.worked')}</th><th>{t('hr.shift.overtime')}</th></tr></thead>
                    <tbody>
                        {att.data?.data.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-slate-500">{t('hr.shift.no_attendance')}</td></tr>}
                        {att.data?.data.map((a) => (
                            <tr key={a.id} className="border-t">
                                <td className="py-2">{a.employee ? `${a.employee.last_name} ${a.employee.first_name}` : `#${a.employee_id}`}</td>
                                <td>{formatDate(a.date)}</td><td>{new Date(a.clock_in).toLocaleTimeString()}</td>
                                <td>{a.clock_out ? new Date(a.clock_out).toLocaleTimeString() : '—'}</td>
                                <td>{a.worked_minutes != null ? `${Math.floor(a.worked_minutes / 60)}h${String(a.worked_minutes % 60).padStart(2, '0')}` : '—'}</td>
                                <td>{a.overtime_minutes ? t('hr.common.minutes', { min: a.overtime_minutes }) : '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

interface LeaveType { id: number; code: string; name: string; paid: boolean }
interface Leave { id: number; employee: { id: number; number: string; first_name: string; last_name: string } | null; leave_type_id: number; start_date: string; end_date: string; days: number; status: string; reason: string | null }

export function LeaveTab() {
    const t = useT();
    const [status, setStatus] = useState('pending');
    const list = useApi<Paged<Leave>>(`hr/leave-requests${status ? `?status=${status}` : ''}`);
    const types = useApi<LeaveType[]>('hr/leave-types');
    const emps = useApi<Paged<Employee>>('hr/employees?status=active');
    const m = useMsg();
    const [open, setOpen] = useState(false);
    const [f, setF] = useState({ employee_id: '', leave_type_id: '', start_date: '', end_date: '', reason: '' });

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api('hr/leave-requests', { method: 'POST', body: JSON.stringify({ ...f, employee_id: f.employee_id ? Number(f.employee_id) : null, leave_type_id: Number(f.leave_type_id), reason: f.reason || null }) });
            m.good(t('hr.leave.submitted')); setOpen(false); list.reload();
        } catch (x) { m.bad(x); }
    }
    async function decide(l: Leave, approve: boolean) {
        try {
            await api(`hr/leave-requests/${l.id}/decide`, { method: 'POST', body: JSON.stringify({ approve }) });
            m.good(approve ? t('hr.leave.approved') : t('hr.leave.rejected')); list.reload();
        } catch (x) { m.bad(x); }
    }

    return (
        <div>
            <Banner ok={m.ok} err={m.err ?? list.error?.message} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <select className="input w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('common.all')}</option>{['pending', 'approved', 'rejected', 'cancelled'].map((s) => <option key={s} value={s}>{t(`hr.status.${s}`)}</option>)}
                </select>
                <div className="flex-1" />
                <button className="btn-primary" onClick={() => setOpen(true)}><Plus className="h-4 w-4" /> {t('hr.leave.new_request')}</button>
            </div>
            {open && (
                <form onSubmit={submit} className="card mb-5">
                    <div className="mb-3 flex items-center justify-between"><h3 className="font-semibold">{t('hr.leave.new_title')}</h3><button type="button" onClick={() => setOpen(false)}><X className="h-4 w-4" /></button></div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <Field label={t('hr.emp.employee')}><select className="input" value={f.employee_id} onChange={(e) => setF({ ...f, employee_id: e.target.value })}><option value="">{t('hr.leave.myself')}</option>{emps.data?.data.map((p) => <option key={p.id} value={p.id}>{p.last_name} {p.first_name}</option>)}</select></Field>
                        <Field label={t('hr.leave.type_req')}><select className="input" required value={f.leave_type_id} onChange={(e) => setF({ ...f, leave_type_id: e.target.value })}><option value="">—</option>{types.data?.map((lt) => <option key={lt.id} value={lt.id}>{lt.name}{lt.paid ? '' : t('hr.leave.unpaid')}</option>)}</select></Field>
                        <Field label={t('hr.common.from_req')}><input type="date" className="input" required value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} /></Field>
                        <Field label={t('hr.common.to_req')}><input type="date" className="input" required min={f.start_date} value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} /></Field>
                        <Field label={t('hr.common.reason')}><input className="input" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
                    </div>
                    <div className="mt-3 flex justify-end"><button className="btn-primary">{t('hr.common.submit')}</button></div>
                </form>
            )}
            <div className="card overflow-x-auto p-0">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-3">{t('hr.emp.employee')}</th><th>{t('hr.common.type')}</th><th>{t('hr.common.from')}</th><th>{t('hr.common.to')}</th><th>{t('hr.common.days')}</th><th>{t('hr.common.reason')}</th><th>{t('hr.common.status')}</th><th></th></tr></thead>
                    <tbody>
                        {list.data?.data.length === 0 && <tr><td colSpan={8} className="p-6 text-center text-slate-500">{t('hr.leave.none')}</td></tr>}
                        {list.data?.data.map((l) => (
                            <tr key={l.id} className="border-t">
                                <td className="p-3">{l.employee ? `${l.employee.last_name} ${l.employee.first_name}` : '—'}</td>
                                <td>{types.data?.find((t) => t.id === l.leave_type_id)?.name ?? '—'}</td>
                                <td>{formatDate(l.start_date)}</td><td>{formatDate(l.end_date)}</td><td>{l.days}</td>
                                <td className="max-w-[200px] truncate">{l.reason ?? ''}</td>
                                <td><Badge s={l.status} /></td>
                                <td className="whitespace-nowrap pr-3">{l.status === 'approved' && <OpsPrintButton path={`leave-slip/${l.id}`} label={t('documents4.leave_slip')} />}{l.status === 'pending' && <>
                                    <button className="btn-primary mr-1 px-2 py-1 text-xs" onClick={() => decide(l, true)}><Check className="h-3 w-3" /> {t('hr.common.approve')}</button>
                                    <button className="btn-secondary px-2 py-1 text-xs text-red-600" onClick={() => decide(l, false)}><X className="h-3 w-3" /> {t('hr.common.reject')}</button>
                                </>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
