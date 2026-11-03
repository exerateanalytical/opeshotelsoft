import { activeHotelId, api, ApiError, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { AlertTriangle, Check, Download, Upload } from 'lucide-react';
import { useState } from 'react';
import { Banner, Employee, errMsg, Field, Paged, Position } from './shared';
import { useT } from '@/lib/i18n';

interface Doc { id: number; employee_id: number; type: string; title: string | null; original_name: string; size: number; expires_on: string | null; created_at: string; expired?: boolean; employee?: { id: number; number: string; first_name: string; last_name: string } }
interface Task { id: number; title: string; owner_role: string | null; due_on: string | null; completed_at: string | null }
interface Template { id: number; position_id: number; tasks: { title: string; owner_role: string | null; due_days?: number }[] }
export interface OrgNode { id: number; number: string; name: string; position: string | null; reports: OrgNode[] }

const DOC_TYPES = [['contract', 'hr.rec.doc.contract'], ['id', 'hr.rec.doc.id'], ['diploma', 'hr.rec.doc.diploma'], ['other', 'hr.rec.doc.other']] as const;

function useMsg() {
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    return { ok, err, good: (m: string) => (setOk(m), setErr(null)), bad: (e: unknown) => (setErr(errMsg(e)), setOk(null)) };
}

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Multipart / binary requests (api() always sends JSON). */
async function raw(path: string, init: RequestInit = {}) {
    const hotel = activeHotelId();
    const res = await fetch(`/api/v1/${path}`, {
        ...init, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}), ...(init.method === 'POST' ? { 'Idempotency-Key': crypto.randomUUID() } : {}) },
    });
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new ApiError({ status: res.status, title: res.statusText, ...body });
    }
    return res;
}

async function download(doc: Doc) {
    const res = await raw(`hr/documents/${doc.id}/download`);
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url; a.download = doc.original_name; a.click();
    URL.revokeObjectURL(url);
}

export function ExpiryAlerts() {
    const t = useT();
    const alerts = useApi<Doc[]>('hr/documents/expiring?days=30');
    if (!alerts.data?.length) return null;
    return (
        <div className="card mb-5">
            <h3 className="mb-2 flex items-center gap-2 font-semibold"><AlertTriangle className="h-4 w-4 text-amber-600" /> {t('hr.rec.expiring')}</h3>
            <ul className="divide-y text-sm">
                {alerts.data.map((d) => (
                    <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{d.employee ? `${d.employee.first_name} ${d.employee.last_name}` : ''} · {d.title ?? d.type}</span>
                        <span className={`badge ${d.expired ? 'badge-red' : 'badge-yellow'} text-xs`}>{d.expired ? t('hr.rec.expired') : t('hr.rec.expires')} {d.expires_on && formatDate(d.expires_on)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function RecordsTab() {
    const t = useT();
    const list = useApi<Paged<Employee>>('hr/employees');
    const [id, setId] = useState<number | null>(null);
    return (
        <div>
            <ExpiryAlerts />
            <div className="card mb-5">
                <Field label={t('hr.emp.employee')}>
                    <select className="input" value={id ?? ''} onChange={(e) => setId(e.target.value ? Number(e.target.value) : null)}>
                        <option value="">{t('hr.rec.choose_employee')}</option>
                        {list.data?.data.map((e) => <option key={e.id} value={e.id}>{e.last_name} {e.first_name} · {e.number}</option>)}
                    </select>
                </Field>
            </div>
            {id && <EmployeeRecord key={id} id={id} employees={list.data?.data ?? []} />}
            <OnboardingTemplates />
        </div>
    );
}

function EmployeeRecord({ id, employees }: { id: number; employees: Employee[] }) {
    const t = useT();
    const emp = useApi<Employee & { reports_to: number | null; payslip_language: string }>(`hr/employees/${id}`);
    const docs = useApi<Doc[]>(`hr/employees/${id}/documents`);
    const tasks = useApi<Task[]>(`hr/employees/${id}/onboarding`);
    const m = useMsg();
    const [up, setUp] = useState<{ type: string; title: string; expires_on: string; file: File | null }>({ type: 'contract', title: '', expires_on: '', file: null });
    const [busy, setBusy] = useState(false);

    async function upload(e: React.FormEvent) {
        e.preventDefault();
        if (!up.file) return;
        setBusy(true);
        const fd = new FormData();
        fd.append('file', up.file); fd.append('type', up.type);
        if (up.title) fd.append('title', up.title);
        if (up.expires_on) fd.append('expires_on', up.expires_on);
        try {
            await raw(`hr/employees/${id}/documents`, { method: 'POST', body: fd });
            m.good(t('hr.rec.uploaded')); setUp({ type: up.type, title: '', expires_on: '', file: null }); docs.reload();
            (e.target as HTMLFormElement).reset();
        } catch (x) { m.bad(x); } finally { setBusy(false); }
    }
    async function patch(body: Record<string, unknown>, msg: string) {
        try { await api(`hr/employees/${id}`, { method: 'PATCH', body: JSON.stringify(body) }); m.good(msg); emp.reload(); } catch (x) { m.bad(x); }
    }
    async function complete(tk: Task) {
        try { await api(`hr/onboarding-tasks/${tk.id}/complete`, { method: 'POST' }); m.good(t('hr.rec.task_done', { title: tk.title })); tasks.reload(); } catch (x) { m.bad(x); }
    }

    const e = emp.data;
    return (
        <div className="mb-5">
            <Banner ok={m.ok} err={m.err ?? emp.error?.message} />
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div className="card">
                    <h3 className="mb-3 font-semibold">{t('hr.rec.reporting_payslip')}</h3>
                    {e && (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label={t('hr.rec.reports_to')}>
                                <select className="input" value={e.reports_to ?? ''} onChange={(ev) => patch({ reports_to: ev.target.value ? Number(ev.target.value) : null }, t('hr.rec.reporting_saved'))}>
                                    <option value="">{t('hr.rec.nobody')}</option>
                                    {employees.filter((x) => x.id !== id).map((x) => <option key={x.id} value={x.id}>{x.last_name} {x.first_name}</option>)}
                                </select>
                            </Field>
                            <Field label={t('hr.rec.payslip_language')}>
                                <select className="input" value={e.payslip_language ?? 'fr'} onChange={(ev) => patch({ payslip_language: ev.target.value }, t('hr.rec.language_saved'))}>
                                    <option value="fr">Français</option><option value="en">English</option>
                                </select>
                            </Field>
                        </div>
                    )}
                    <h3 className="mb-2 mt-5 font-semibold">{t('hr.rec.onboarding_checklist')}</h3>
                    {!tasks.data?.length && <p className="text-sm text-slate-500">{t('hr.rec.no_tasks')}</p>}
                    <ul className="divide-y text-sm">
                        {tasks.data?.map((tk) => (
                            <li key={tk.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span className={tk.completed_at ? 'text-slate-400 line-through' : ''}>{tk.title}{tk.owner_role && <span className="ml-2 text-xs text-slate-500">{tk.owner_role}</span>}</span>
                                {tk.completed_at ? <span className="badge badge-green text-xs">{t('hr.rec.done')}</span>
                                    : <button className="btn-secondary" onClick={() => complete(tk)}><Check className="h-4 w-4" /> {t('hr.rec.complete')}{tk.due_on && t('hr.rec.due', { date: formatDate(tk.due_on) })}</button>}
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="card">
                    <h3 className="mb-3 font-semibold">{t('hr.rec.documents')}</h3>
                    <form onSubmit={upload} className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <select className="input" value={up.type} onChange={(ev) => setUp({ ...up, type: ev.target.value })}>{DOC_TYPES.map(([k, l]) => <option key={k} value={k}>{t(l)}</option>)}</select>
                        <input className="input" placeholder={t('hr.rec.title_optional')} value={up.title} onChange={(ev) => setUp({ ...up, title: ev.target.value })} />
                        <Field label={t('hr.rec.expiry_date')}><input type="date" className="input" value={up.expires_on} onChange={(ev) => setUp({ ...up, expires_on: ev.target.value })} /></Field>
                        <Field label={t('hr.rec.file_label')}><input type="file" required className="input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(ev) => setUp({ ...up, file: ev.target.files?.[0] ?? null })} /></Field>
                        <button className="btn-primary sm:col-span-2" disabled={busy || !up.file}><Upload className="h-4 w-4" /> {t('hr.rec.upload')}</button>
                    </form>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th>{t('hr.common.type')}</th><th>{t('hr.rec.file')}</th><th>{t('hr.rec.expires')}</th><th /></tr></thead>
                            <tbody>{docs.data?.map((d) => (
                                <tr key={d.id} className="border-t">
                                    <td className="py-2">{(() => { const dt = DOC_TYPES.find(([k]) => k === d.type)?.[1]; return dt ? t(dt) : d.type; })()}</td>
                                    <td className="max-w-[10rem] truncate">{d.title ?? d.original_name}</td>
                                    <td>{d.expires_on ? formatDate(d.expires_on) : '—'}</td>
                                    <td className="text-right"><button className="btn-secondary" aria-label={t('hr.rec.download')} onClick={() => download(d).catch(m.bad)}><Download className="h-4 w-4" /></button></td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}

function OnboardingTemplates() {
    const t = useT();
    const positions = useApi<Position[]>('hr/positions');
    const templates = useApi<Template[]>('hr/onboarding-templates');
    const m = useMsg();
    const [pos, setPos] = useState<number | null>(null);
    const [rows, setRows] = useState<{ title: string; owner_role: string; due_days: string }[]>([]);

    function pick(id: number | null) {
        setPos(id);
        const tpl = templates.data?.find((x) => x.position_id === id);
        setRows((tpl?.tasks ?? []).map((x) => ({ title: x.title, owner_role: x.owner_role ?? '', due_days: String(x.due_days ?? 0) })));
    }
    async function save(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api(`hr/onboarding-templates/${pos}`, { method: 'PUT', body: JSON.stringify({ tasks: rows.filter((r) => r.title).map((r) => ({ title: r.title, owner_role: r.owner_role || null, due_days: Number(r.due_days || 0) })) }) });
            m.good(t('hr.rec.template_saved')); templates.reload();
        } catch (x) { m.bad(x); }
    }

    return (
        <form onSubmit={save} className="card">
            <h3 className="mb-3 font-semibold">{t('hr.rec.templates')}</h3>
            <Banner ok={m.ok} err={m.err} />
            <Field label={t('hr.common.position')}>
                <select className="input" value={pos ?? ''} onChange={(e) => pick(e.target.value ? Number(e.target.value) : null)}>
                    <option value="">{t('hr.rec.choose_position')}</option>
                    {positions.data?.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                </select>
            </Field>
            {pos && <>
                <div className="mt-3 space-y-2">
                    {rows.map((r, i) => (
                        <div key={i} className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_12rem_6rem]">
                            <input className="input" placeholder={t('hr.rec.task')} value={r.title} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} />
                            <input className="input" placeholder={t('hr.rec.owner_role_ph')} value={r.owner_role} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, owner_role: e.target.value } : x)))} />
                            <input className="input" type="number" min={0} placeholder={t('hr.common.days')} value={r.due_days} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, due_days: e.target.value } : x)))} />
                        </div>
                    ))}
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" className="btn-secondary" onClick={() => setRows([...rows, { title: '', owner_role: '', due_days: '0' }])}>{t('hr.rec.add_task')}</button>
                    <button className="btn-primary">{t('hr.rec.save_template')}</button>
                </div>
            </>}
        </form>
    );
}

export function OrgChartTab() {
    const t = useT();
    const chart = useApi<{ department_id: number | null; department: string; employees: OrgNode[] }[]>('hr/org-chart');
    return (
        <div>
            <Banner err={chart.error?.message} />
            {chart.data?.length === 0 && <p className="text-sm text-slate-500">{t('hr.rec.no_employees')}</p>}
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                {chart.data?.map((d) => (
                    <div key={d.department_id ?? 0} className="card">
                        <h3 className="mb-3 font-semibold">{d.department}</h3>
                        <ul className="space-y-1">{d.employees.map((n) => <Node key={n.id} n={n} />)}</ul>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Node({ n }: { n: OrgNode }) {
    return (
        <li>
            <div className="rounded border border-slate-200 px-3 py-2 text-sm">
                <span className="font-medium">{n.name}</span>
                <span className="ml-2 text-xs text-slate-500">{n.position ?? n.number}</span>
            </div>
            {n.reports.length > 0 && <ul className="ml-4 mt-1 space-y-1 border-l border-slate-200 pl-3">{n.reports.map((c) => <Node key={c.id} n={c} />)}</ul>}
        </li>
    );
}
