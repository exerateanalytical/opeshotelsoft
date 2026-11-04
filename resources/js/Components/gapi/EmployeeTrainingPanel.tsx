import { useState } from 'react';
import Alert from '@/Components/ui/Alert';
import Button from '@/Components/ui/Button';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import Skeleton from '@/Components/ui/Skeleton';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useCan } from '@/lib/useCan';
import { useT } from '@/lib/i18n';
import { formatDate } from '@/lib/utils';

interface TrainingRow { session_id: number; course: string; date: string; attendance: string; result: string | null; score: number | null; cost: number | null }
interface Cert { id: number; employee_id: number; type: string; issued_on: string; expires_on: string | null; status: string; reference: string | null }
interface Session { id: number; course_id: number; starts_on: string; status: string; location: string | null; capacity: number | null; attendances: { employee_id: number }[] }
interface Catalogue { skills: { id: number; name: string }[]; cert_types: { id: number; name: string }[]; courses: { id: number; title: string }[] }

const post = (path: string, body: unknown) => api(path, { method: 'POST', body: JSON.stringify(body) });
const msg = (e: unknown) => (e instanceof Error ? e.message : String(e));

function csv(rows: (string | number | null)[][]): string {
    return rows.map((r) => r.map((c) => { const s = c == null ? '' : String(c); return /[",;\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; }).join(',')).join('\n');
}

/** GAPI: training records, certifications and skill updates for one employee (talent.view to read, talent.manage to change). */
export default function EmployeeTrainingPanel({ employeeId, employeeName }: { employeeId: number; employeeName?: string }) {
    const t = useT();
    const can = useCan();
    const manage = can('talent.manage');
    const records = useApi<TrainingRow[]>(`hr/talent/employees/${employeeId}/training`);
    const certs = useApi<Cert[]>('hr/talent/certifications');
    const cat = useApi<Catalogue>(manage ? 'hr/talent/catalogue' : null);
    const sessions = useApi<Session[]>(manage ? 'hr/talent/sessions' : null);
    const [modal, setModal] = useState<null | 'enrol' | 'cert' | 'skill'>(null);
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const [form, setForm] = useState<Record<string, string>>({});
    const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

    const mine = (certs.data ?? []).filter((c) => c.employee_id === employeeId);
    const courseTitle = (id: number) => cat.data?.courses.find((c) => c.id === id)?.title ?? `#${id}`;
    const openSessions = (sessions.data ?? []).filter((s) => s.status === 'planned' && !s.attendances.some((a) => a.employee_id === employeeId));

    const open = (m: 'enrol' | 'cert' | 'skill') => { setErr(null); setForm({}); setModal(m); };
    const submit = async (e: React.FormEvent) => {
        e.preventDefault(); setBusy(true); setErr(null);
        try {
            if (modal === 'enrol') await post(`hr/talent/sessions/${form.session_id}/enroll`, { employee_ids: [employeeId] });
            if (modal === 'cert') await post(`hr/talent/employees/${employeeId}/certifications`, { cert_type_id: Number(form.cert_type_id), issued_on: form.issued_on, expires_on: form.expires_on || null, reference: form.reference || null, issuer: form.issuer || null });
            if (modal === 'skill') await post(`hr/talent/employees/${employeeId}/skills`, { skill_id: Number(form.skill_id), level: Number(form.level), assessed_on: form.assessed_on || null });
            toast.success(t('gapi.training.saved'));
            setModal(null); records.reload(); certs.reload(); sessions.reload();
        } catch (x) { setErr(msg(x)); } finally { setBusy(false); }
    };

    const exportCsv = () => {
        const head = [t('gapi.training.course'), t('gapi.training.date'), t('gapi.training.attendance'), t('gapi.training.result'), t('gapi.training.score'), t('gapi.training.cost')];
        const body = (records.data ?? []).map((r) => [r.course, r.date, t(`gapi.att.${r.attendance}`), r.result ? t(`gapi.res.${r.result}`) : '', r.score, r.cost]);
        const certRows = mine.map((c) => [`${t('gapi.training.cert')}: ${c.type}`, c.issued_on, '', c.expires_on ?? '', '', '']);
        const blob = new Blob(['﻿' + csv([head, ...body, ...certRows])], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `training-${(employeeName ?? String(employeeId)).replace(/\W+/g, '-').toLowerCase()}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    };

    const rows = records.data ?? [];
    return (
        <div className="grid gap-4">
            <div className="btn-row">
                {manage && <Button variant="primary" onClick={() => open('enrol')}>{t('gapi.training.enrol')}</Button>}
                {manage && <Button variant="secondary" onClick={() => open('cert')}>{t('gapi.training.add_cert')}</Button>}
                {manage && <Button variant="secondary" onClick={() => open('skill')}>{t('gapi.training.set_skill')}</Button>}
                <Button variant="ghost" onClick={exportCsv} disabled={!rows.length && !mine.length}>{t('gapi.export_csv')}</Button>
            </div>

            <section className="card p-4" aria-labelledby="gapi-tr-h">
                <h3 id="gapi-tr-h" className="l1-section-title mb-3">{t('gapi.training.records')}</h3>
                {records.loading && !records.data && <Skeleton className="h-24" />}
                {records.error && <Alert variant="danger">{records.error.message} <button className="underline" onClick={records.reload}>{t('gapi.retry')}</button></Alert>}
                {records.data && rows.length === 0 && <EmptyState title={t('gapi.training.empty')} description={t('gapi.training.empty_hint')} action={manage ? <Button onClick={() => open('enrol')}>{t('gapi.training.enrol')}</Button> : undefined} />}
                {rows.length > 0 && (
                    <div className="table-wrap"><table className="table-std table-cards">
                        <caption className="sr-only">{t('gapi.training.records')}</caption>
                        <thead><tr><th>{t('gapi.training.course')}</th><th>{t('gapi.training.date')}</th><th>{t('gapi.training.attendance')}</th><th>{t('gapi.training.result')}</th><th className="num">{t('gapi.training.score')}</th></tr></thead>
                        <tbody>{rows.map((r) => (
                            <tr key={r.session_id}>
                                <td data-label={t('gapi.training.course')}>{r.course}</td>
                                <td data-label={t('gapi.training.date')}>{formatDate(r.date)}</td>
                                <td data-label={t('gapi.training.attendance')}><span className={`badge-${r.attendance === 'attended' ? 'green' : r.attendance === 'absent' ? 'red' : 'blue'}`}>{t(`gapi.att.${r.attendance}`)}</span></td>
                                <td data-label={t('gapi.training.result')}>{r.result ? t(`gapi.res.${r.result}`) : '—'}</td>
                                <td data-label={t('gapi.training.score')} className="num">{r.score ?? '—'}</td>
                            </tr>))}</tbody>
                    </table></div>
                )}
            </section>

            <section className="card p-4" aria-labelledby="gapi-cert-h">
                <h3 id="gapi-cert-h" className="l1-section-title mb-3">{t('gapi.training.certs')}</h3>
                {certs.loading && !certs.data && <Skeleton className="h-16" />}
                {certs.error && <Alert variant="danger">{certs.error.message}</Alert>}
                {certs.data && mine.length === 0 && <p className="text-sm text-slate-500">{t('gapi.training.no_certs')}</p>}
                {mine.length > 0 && (
                    <ul className="divide-y divide-slate-100 text-sm">{mine.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{c.type}{c.reference ? ` · ${c.reference}` : ''}</span>
                            <span className="flex items-center gap-2 text-slate-700">{formatDate(c.issued_on)} → {c.expires_on ? formatDate(c.expires_on) : '—'}
                                <span className={`badge-${c.status === 'expired' ? 'red' : c.status === 'expiring' ? 'yellow' : 'green'}`}>{t(`gapi.cert.${c.status}`)}</span></span>
                        </li>))}</ul>
                )}
            </section>

            <Modal open={modal !== null} onClose={() => setModal(null)} title={modal ? t(`gapi.training.modal_${modal}`) : ''} formId="gapi-training-form"
                footer={<><Button variant="secondary" onClick={() => setModal(null)}>{t('gapi.cancel')}</Button><Button type="submit" loading={busy}>{t('gapi.save')}</Button></>}>
                <form id="gapi-training-form" onSubmit={submit} className="grid gap-3">
                    {err && <Alert variant="danger">{err}</Alert>}
                    {modal === 'enrol' && (openSessions.length === 0
                        ? <p className="text-sm text-slate-700">{t('gapi.training.no_sessions')}</p>
                        : <Field label={t('gapi.training.session')} required><select className="select" required value={form.session_id ?? ''} onChange={set('session_id')}>
                            <option value="">—</option>{openSessions.map((s) => <option key={s.id} value={s.id}>{courseTitle(s.course_id)} · {formatDate(s.starts_on)}{s.location ? ` · ${s.location}` : ''}</option>)}</select></Field>)}
                    {modal === 'cert' && <>
                        <Field label={t('gapi.training.cert_type')} required><select className="select" required value={form.cert_type_id ?? ''} onChange={set('cert_type_id')}>
                            <option value="">—</option>{cat.data?.cert_types.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
                        <Field label={t('gapi.training.issued_on')} required><input type="date" className="input" required value={form.issued_on ?? ''} onChange={set('issued_on')} /></Field>
                        <Field label={t('gapi.training.expires_on')}><input type="date" className="input" value={form.expires_on ?? ''} onChange={set('expires_on')} /></Field>
                        <Field label={t('gapi.training.reference')}><input className="input" value={form.reference ?? ''} onChange={set('reference')} /></Field>
                        <Field label={t('gapi.training.issuer')}><input className="input" value={form.issuer ?? ''} onChange={set('issuer')} /></Field>
                    </>}
                    {modal === 'skill' && <>
                        <Field label={t('gapi.training.skill')} required><select className="select" required value={form.skill_id ?? ''} onChange={set('skill_id')}>
                            <option value="">—</option>{cat.data?.skills.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                        <Field label={t('gapi.training.level')} required><select className="select" required value={form.level ?? ''} onChange={set('level')}>
                            <option value="">—</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                        <Field label={t('gapi.training.assessed_on')}><input type="date" className="input" value={form.assessed_on ?? ''} onChange={set('assessed_on')} /></Field>
                    </>}
                </form>
            </Modal>
        </div>
    );
}
