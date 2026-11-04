import { useEffect, useState } from 'react';
import { activeHotelId, api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatDayShort } from '@/lib/utils';
import { useCan } from '@/lib/useCan';
import Modal from '@/Components/ui/Modal';
import Field from '@/Components/ui/Field';
import Alert from '@/Components/ui/Alert';
import QueryStatus from '@/Components/ui/QueryStatus';
import { addDays, monday, msg, wpost } from './WorkforceTabs';
import type { Paged } from './shared';

interface EmpLite { id: number; number: string; first_name: string; last_name: string; department_id?: number | null }
export interface EditShift { employee_id: number | null; department_id?: number | null; date: string; starts_at: string; ends_at: string; role: string | null; template_id?: number | null }
interface Tpl { name: string; starts_at: string; ends_at: string; share: number; min_staff: number }
interface Rule { driver: string; units_per_staff: number; min_staff: number; include_pos?: boolean }
interface DeptRow { department_id: number; code: string; name: string; rule: Rule | null; templates: Tpl[] }
interface ForecastDay { date: string; arrivals: number; departures: number; inhouse: number; departments: { department_id: number; driver: string; units: number; required: number }[] }

const hm = (s: string) => String(s ?? '').slice(0, 5);

/** GAPD: bulk edit and save all shifts of a draft roster (PUT workforce/rosters/{roster}/shifts). */
export function ShiftEditor({ rosterId, weekStart, shifts, open, onClose, onSaved }: { rosterId: number; weekStart: string; shifts: EditShift[]; open: boolean; onClose: () => void; onSaved: (r: unknown) => void }) {
    const t = useT();
    const emps = useApi<Paged<EmpLite>>(open ? 'hr/employees?status=active&per_page=500' : null, [open]);
    const [rows, setRows] = useState<EditShift[]>([]);
    const [err, setErr] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (open) { setRows(shifts.map((s) => ({ ...s, date: s.date.slice(0, 10), starts_at: hm(s.starts_at), ends_at: hm(s.ends_at) }))); setErr(null); } }, [open, shifts]);
    const set = (i: number, p: Partial<EditShift>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...p } : r)));
    async function save(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true); setErr(null);
        try {
            const body = rows.map((r) => ({ employee_id: r.employee_id || null, department_id: r.department_id ?? null, date: r.date, starts_at: r.starts_at, ends_at: r.ends_at, role: r.role || null, template_id: r.template_id ?? null }));
            onSaved(await wpost(`workforce/rosters/${rosterId}/shifts`, { shifts: body }, 'PUT'));
            onClose();
        } catch (x) { setErr(msg(x)); } finally { setBusy(false); }
    }
    return (
        <Modal open={open} onClose={onClose} size="xl" title={t('gapd.roster.edit_title')} formId="shift-edit"
            footer={<><button type="button" className="btn-secondary" onClick={onClose}>{t('gapd.close')}</button><button type="submit" className="btn-primary" disabled={busy}>{t('gapd.roster.save_all')}</button></>}>
            {err && <Alert variant="danger" className="mb-3">{err}</Alert>}
            <form id="shift-edit" onSubmit={save} className="space-y-2 text-sm">
                <div className="table-wrap">
                    <table className="table-auto table-cards w-full">
                        <thead><tr className="text-left text-slate-500"><th>{t('gapd.date')}</th><th>{t('gapd.start')}</th><th>{t('gapd.end')}</th><th>{t('gapd.employee')}</th><th>{t('gapd.role')}</th><th><span className="sr-only">{t('gapd.actions')}</span></th></tr></thead>
                        <tbody>
                            {rows.map((r, i) => (
                                <tr key={i}>
                                    <td data-label={t('gapd.date')}><input type="date" className="input" required aria-label={t('gapd.date')} value={r.date} onChange={(e) => set(i, { date: e.target.value })} /></td>
                                    <td data-label={t('gapd.start')}><input type="time" className="input" required aria-label={t('gapd.start')} value={r.starts_at} onChange={(e) => set(i, { starts_at: e.target.value })} /></td>
                                    <td data-label={t('gapd.end')}><input type="time" className="input" required aria-label={t('gapd.end')} value={r.ends_at} onChange={(e) => set(i, { ends_at: e.target.value })} /></td>
                                    <td data-label={t('gapd.employee')}>
                                        <select className="select" aria-label={t('gapd.employee')} value={r.employee_id ?? ''} onChange={(e) => set(i, { employee_id: e.target.value ? Number(e.target.value) : null })}>
                                            <option value="">{t('gapd.roster.open_shift')}</option>
                                            {emps.data?.data.map((x) => <option key={x.id} value={x.id}>{x.number} · {x.first_name} {x.last_name}</option>)}
                                        </select>
                                    </td>
                                    <td data-label={t('gapd.role')}><input className="input" maxLength={60} aria-label={t('gapd.role')} value={r.role ?? ''} onChange={(e) => set(i, { role: e.target.value })} /></td>
                                    <td><button type="button" className="btn-ghost btn-sm" onClick={() => setRows(rows.filter((_, j) => j !== i))}>{t('gapd.remove')}</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <button type="button" className="btn-secondary" onClick={() => setRows([...rows, { employee_id: null, date: weekStart.slice(0, 10), starts_at: '08:00', ends_at: '16:00', role: null }])}>{t('gapd.roster.add_shift')}</button>
            </form>
        </Modal>
    );
}

/** GAPD: labour forecast (GET workforce/forecast) and per-department staffing rules (GET/PUT workforce/dept-rules). */
export function ForecastRulesPanel() {
    const t = useT();
    const can = useCan();
    const [from, setFrom] = useState(monday());
    const to = addDays(from, 13);
    const fc = useApi<ForecastDay[]>(`workforce/forecast?from=${from}&to=${to}`, [from]);
    const rules = useApi<DeptRow[]>('workforce/dept-rules');
    const [edit, setEdit] = useState<DeptRow | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const names = new Map((rules.data ?? []).map((d) => [d.department_id, d.name]));
    const deptIds = [...new Set((fc.data ?? []).flatMap((d) => d.departments.map((x) => x.department_id)))];

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!edit) return;
        const r = edit.rule ?? { driver: 'none', units_per_staff: 10, min_staff: 0 };
        try {
            await wpost('workforce/dept-rules', { department_id: edit.department_id, driver: r.driver, units_per_staff: Number(r.units_per_staff), min_staff: Number(r.min_staff), templates: edit.templates.map((x) => ({ ...x, starts_at: hm(x.starts_at), ends_at: hm(x.ends_at), share: Number(x.share), min_staff: Number(x.min_staff) })) }, 'PUT');
            setOk(t('gapd.saved')); setErr(null); setEdit(null); rules.reload(); fc.reload();
        } catch (x) { setErr(msg(x)); }
    }
    const rule = edit?.rule ?? { driver: 'none', units_per_staff: 10, min_staff: 0 };
    const setRule = (p: Partial<Rule>) => edit && setEdit({ ...edit, rule: { ...rule, ...p } });
    const setTpl = (i: number, p: Partial<Tpl>) => edit && setEdit({ ...edit, templates: edit.templates.map((x, j) => (j === i ? { ...x, ...p } : x)) });

    return (
        <div className="space-y-4">
            {ok && <Alert variant="success" onDismiss={() => setOk(null)}>{ok}</Alert>}
            <section className="card p-4 text-sm">
                <div className="mb-2 flex flex-wrap items-end justify-between gap-3">
                    <h2 className="font-semibold">{t('gapd.fc.title')}</h2>
                    <Field label={t('gapd.fc.from')}><input type="date" className="input" value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} /></Field>
                </div>
                <QueryStatus query={fc} variant="table" rows={4} />
                {fc.data && (
                    <div className="table-wrap sticky-first">
                        <table className="table-auto w-full">
                            <caption className="sr-only">{t('gapd.fc.title')}</caption>
                            <thead><tr className="text-left text-slate-500"><th>{t('gapd.date')}</th><th className="num">{t('gapd.fc.arrivals')}</th><th className="num">{t('gapd.fc.departures')}</th><th className="num">{t('gapd.fc.inhouse')}</th>
                                {deptIds.map((id) => <th key={id} className="num">{names.get(id) ?? id}</th>)}</tr></thead>
                            <tbody>{fc.data.map((d) => (
                                <tr key={d.date} className="border-t"><td>{formatDayShort(d.date)}</td><td className="num">{d.arrivals}</td><td className="num">{d.departures}</td><td className="num">{d.inhouse}</td>
                                    {deptIds.map((id) => <td key={id} className="num">{d.departments.find((x) => x.department_id === id)?.required ?? 0}</td>)}</tr>))}</tbody>
                        </table>
                    </div>
                )}
                {fc.data && !deptIds.length && <p className="mt-2 text-slate-600">{t('gapd.fc.no_rules')}</p>}
            </section>
            <section className="card p-4 text-sm">
                <h2 className="mb-2 font-semibold">{t('gapd.rules.title')}</h2>
                <QueryStatus query={rules} variant="list" rows={3} />
                <ul className="divide-y divide-slate-100">
                    {rules.data?.map((d) => (
                        <li key={d.department_id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><span className="font-medium">{d.name}</span> <span className="text-slate-600">· {d.rule ? `${t(`gapd.driver.${d.rule.driver}`)}, 1/${d.rule.units_per_staff}, min ${d.rule.min_staff}` : t('gapd.rules.none')} · {d.templates.length} {t('gapd.rules.templates')}</span></span>
                            {can('workforce.manage') && <button type="button" className="btn-secondary btn-sm" onClick={() => { setErr(null); setEdit({ ...d, templates: d.templates.map((x) => ({ ...x })) }); }}>{t('gapd.edit')}</button>}
                        </li>
                    ))}
                </ul>
            </section>
            <Modal open={!!edit} onClose={() => setEdit(null)} size="lg" title={`${t('gapd.rules.edit')} ${edit?.name ?? ''}`} formId="rule-form"
                footer={<><button type="button" className="btn-secondary" onClick={() => setEdit(null)}>{t('gapd.close')}</button><button type="submit" className="btn-primary">{t('gapd.save')}</button></>}>
                {err && <Alert variant="danger" className="mb-3">{err}</Alert>}
                {edit && (
                    <form id="rule-form" onSubmit={save} className="space-y-3 text-sm">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field label={t('gapd.rules.driver')}><select className="select w-full" value={rule.driver} onChange={(e) => setRule({ driver: e.target.value })}>{['arrivals', 'departures', 'inhouse', 'none'].map((k) => <option key={k} value={k}>{t(`gapd.driver.${k}`)}</option>)}</select></Field>
                            <Field label={t('gapd.rules.units')}><input type="number" min={1} max={500} required className="input w-full" value={rule.units_per_staff} onChange={(e) => setRule({ units_per_staff: Number(e.target.value) })} /></Field>
                            <Field label={t('gapd.rules.min')}><input type="number" min={0} max={100} required className="input w-full" value={rule.min_staff} onChange={(e) => setRule({ min_staff: Number(e.target.value) })} /></Field>
                        </div>
                        <h3 className="font-semibold">{t('gapd.rules.templates')}</h3>
                        {edit.templates.map((x, i) => (
                            <div key={i} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-6">
                                <Field label={t('gapd.name')} className="col-span-2"><input className="input w-full" required maxLength={60} value={x.name} onChange={(e) => setTpl(i, { name: e.target.value })} /></Field>
                                <Field label={t('gapd.start')}><input type="time" className="input w-full" required value={hm(x.starts_at)} onChange={(e) => setTpl(i, { starts_at: e.target.value })} /></Field>
                                <Field label={t('gapd.end')}><input type="time" className="input w-full" required value={hm(x.ends_at)} onChange={(e) => setTpl(i, { ends_at: e.target.value })} /></Field>
                                <Field label={t('gapd.rules.share')}><input type="number" min={0} max={200} className="input w-full" value={x.share} onChange={(e) => setTpl(i, { share: Number(e.target.value) })} /></Field>
                                <button type="button" className="btn-ghost btn-sm" onClick={() => setEdit({ ...edit, templates: edit.templates.filter((_, j) => j !== i) })}>{t('gapd.remove')}</button>
                            </div>
                        ))}
                        <button type="button" className="btn-secondary" onClick={() => setEdit({ ...edit, templates: [...edit.templates, { name: '', starts_at: '07:00', ends_at: '15:00', share: 100, min_staff: 0 }] })}>{t('gapd.rules.add_template')}</button>
                    </form>
                )}
            </Modal>
        </div>
    );
}

/** GAPD: set the kiosk clock PIN of an employee (PUT workforce/employees/{e}/pin). */
export function KioskPinField({ employeeId }: { employeeId: number }) {
    const t = useT();
    const can = useCan();
    const [pin, setPin] = useState('');
    const [state, setState] = useState<{ ok?: string; err?: string } | null>(null);
    if (!can('workforce.manage')) return null;
    async function save(e: React.FormEvent) {
        e.preventDefault();
        try { await wpost(`workforce/employees/${employeeId}/pin`, { pin }, 'PUT'); setPin(''); setState({ ok: t('gapd.pin.saved') }); } catch (x) { setState({ err: msg(x) }); }
    }
    return (
        <form onSubmit={save} className="card space-y-2 p-4 text-sm">
            <h2 className="font-semibold">{t('gapd.pin.title')}</h2>
            {state?.ok && <Alert variant="success">{state.ok}</Alert>}
            {state?.err && <Alert variant="danger">{state.err}</Alert>}
            <div className="flex flex-wrap items-end gap-2">
                <Field label={t('gapd.pin.label')} hint={t('gapd.pin.hint')}><input className="input" type="password" inputMode="numeric" autoComplete="new-password" pattern="\d{4,8}" required value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} /></Field>
                <button className="btn-primary">{t('gapd.pin.save')}</button>
            </div>
        </form>
    );
}

function xsrf() { const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/); return m ? decodeURIComponent(m[1]) : ''; }

/** GAPD: view the clock-in photo attached to an anomaly (GET workforce/anomalies/{a}/photo). */
export function AnomalyPhotoButton({ anomalyId }: { anomalyId: number }) {
    const t = useT();
    const [url, setUrl] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);
    async function show() {
        setOpen(true); setErr(null);
        if (url) return;
        const hotel = activeHotelId();
        const res = await fetch(`/api/v1/workforce/anomalies/${anomalyId}/photo`, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) } });
        if (!res.ok) { const b = await res.json().catch(() => ({})); setErr(new ApiError({ status: res.status, title: res.statusText, ...b }).message || t('gapd.photo.none')); return; }
        setUrl(URL.createObjectURL(await res.blob()));
    }
    return (
        <>
            <button type="button" className="btn-secondary" onClick={show}>{t('gapd.photo.view')}</button>
            <Modal open={open} onClose={() => setOpen(false)} title={t('gapd.photo.title')} footer={<button type="button" className="btn-secondary" onClick={() => setOpen(false)}>{t('gapd.close')}</button>}>
                {err && <Alert variant="danger">{err}</Alert>}
                {url && <img src={url} alt={t('gapd.photo.alt')} className="mx-auto max-h-[60vh] rounded" />}
                {!url && !err && <p className="text-slate-600">{t('gapd.loading')}</p>}
            </Modal>
        </>
    );
}
