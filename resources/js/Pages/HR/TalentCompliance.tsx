import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, Pill, Section, TalentTabs, msg, tget, tsend, unwrap } from './TalentShared';

interface Req { id: number; kind: string; status: string; employee: string; payload: Record<string, string>; created_at: string }
interface Check { code: string; severity: string; items: { employee_id: number; name: string; detail: string }[] }
interface Review { required: boolean; checks: Check[]; acknowledged: string[]; pending: string[]; reviewed_at: string | null }
interface Run { id: number; period: string; status: string }
interface Emp { id: number; number: string; first_name: string; last_name: string }
interface Settings { payroll_review_required: boolean; variance_pct: number; overtime_multiple: number; applicant_retention_months: number; pool_retention_months: number; leaver_retention_months: number }

export default function TalentCompliance() {
    const t = useT();
    const [reqs, setReqs] = useState<Req[]>([]);
    const [runs, setRuns] = useState<Run[]>([]);
    const [runId, setRunId] = useState('');
    const [review, setReview] = useState<Review | null>(null);
    const [emps, setEmps] = useState<Emp[]>([]);
    const [eligible, setEligible] = useState<Emp[]>([]);
    const [settings, setSettings] = useState<Settings | null>(null);
    const [emp, setEmp] = useState('');
    const [err, setErr] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    const load = () => {
        tget<Req[]>('requests?status=pending').then(setReqs).catch(() => undefined);
        api<Run[] | { data: Run[] }>('payroll/runs').then((r) => setRuns(unwrap(r))).catch(() => undefined);
        api<Emp[] | { data: Emp[] }>('hr/employees').then((r) => setEmps(unwrap(r))).catch(() => undefined);
        tget<{ employees: Emp[] }>('privacy/eligible').then((r) => setEligible(r.employees)).catch(() => undefined);
        tget<Settings>('settings').then(setSettings).catch(() => undefined);
    };
    useEffect(load, []);
    useEffect(() => { if (runId) tget<Review>(`payroll/${runId}/checks`).then(setReview).catch((e) => setErr(msg(e))); else setReview(null); }, [runId]);
    const run = async (fn: () => Promise<unknown>, done?: string) => { setErr(null); setOk(null); try { await fn(); if (done) setOk(done); load(); if (runId) tget<Review>(`payroll/${runId}/checks`).then(setReview); } catch (e) { setErr(msg(e)); } };

    return (
        <AppLayout title={t('talent.compliance.title')} subtitle={t('talent.compliance.subtitle')}>
            <Head title={t('talent.compliance.title')} />
            <TalentTabs active="/hr/talent/compliance" />
            <Banner error={err} ok={ok} />

            <Section title={t('talent.staff_requests')}>
                {!reqs.length && <p className="text-sm text-slate-500">{t('talent.none')}</p>}
                {reqs.map((r) => (
                    <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2 text-sm">
                        <span>{r.employee} - {t(`talent.kind.${r.kind}`)} {Object.values(r.payload).join(' ')}</span>
                        <span className="flex gap-2">
                            <button className="btn-primary" onClick={() => run(() => tsend(`requests/${r.id}/decide`, { approve: true }))}>{t('talent.approve')}</button>
                            <button className="btn-secondary" onClick={() => run(() => tsend(`requests/${r.id}/decide`, { approve: false }))}>{t('talent.reject')}</button>
                        </span>
                    </div>))}
            </Section>

            <Section title={t('talent.payroll_checks')}>
                <select className="input mb-3" value={runId} onChange={(e) => setRunId(e.target.value)}><option value="">{t('talent.choose_run')}</option>{runs.map((r) => <option key={r.id} value={r.id}>{r.period} ({r.status})</option>)}</select>
                {review && (
                    <div className="text-sm">
                        <p className="mb-2">{review.required ? t('talent.review_required') : t('talent.review_advisory')}</p>
                        {review.checks.map((c) => (
                            <div key={c.code} className="mb-2 border-t border-slate-100 pt-2">
                                <p className="font-semibold">{t(`talent.check.${c.code}`)} <Pill tone={c.items.length ? (c.severity === 'error' ? 'bad' : 'warn') : 'ok'}>{c.items.length}</Pill>
                                    {review.acknowledged.includes(c.code) && <Pill tone="ok">{t('talent.acknowledged')}</Pill>}</p>
                                <ul className="ml-4 list-disc">{c.items.map((i, n) => <li key={n}>{i.name} {i.detail}</li>)}</ul>
                            </div>))}
                        {review.pending.length > 0 && <button className="btn-primary" onClick={() => run(() => tsend(`payroll/${runId}/acknowledge`, { codes: review.pending }), t('talent.review_saved'))}>{t('talent.acknowledge_all')}</button>}
                        {review.pending.length === 0 && <Pill tone="ok">{t('talent.review_complete')}</Pill>}
                    </div>)}
                {settings && (
                    <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.payroll_review_required} onChange={(e) => run(() => tsend('settings', { payroll_review_required: e.target.checked }, 'PUT'))} />{t('talent.require_review')}</label>)}
            </Section>

            <Section title={t('talent.privacy')}>
                <div className="mb-3 flex flex-wrap gap-2 text-sm">
                    <select className="input" value={emp} onChange={(e) => setEmp(e.target.value)}><option value="" />{emps.map((e) => <option key={e.id} value={e.id}>{e.number} {e.first_name} {e.last_name}</option>)}</select>
                    <a className={`btn-secondary ${emp ? '' : 'pointer-events-none opacity-50'}`} href={`/api/v1/hr/talent/privacy/employees/${emp}/export`} target="_blank" rel="noreferrer">JSON</a>
                    <a className={`btn-secondary ${emp ? '' : 'pointer-events-none opacity-50'}`} href={`/api/v1/hr/talent/privacy/employees/${emp}/export?format=xlsx`}>Excel</a>
                </div>
                <p className="mb-1 text-sm font-semibold">{t('talent.erasure_eligible')}</p>
                {!eligible.length && <p className="text-sm text-slate-500">{t('talent.none')}</p>}
                {eligible.map((e) => (
                    <p key={e.id} className="flex items-center justify-between border-t border-slate-100 py-2 text-sm">{e.number} {e.first_name} {e.last_name}
                        <button className="btn-secondary" onClick={() => { if (confirm(t('talent.erase_confirm'))) run(() => tsend(`privacy/employees/${e.id}/erase`), t('talent.erased')); }}>{t('talent.erase')}</button></p>))}
                <button className="btn-secondary mt-3" onClick={() => run(async () => { const r = (await tsend('applicants/purge-expired')) as { anonymised: number }; setOk(t('talent.purged', { count: r.anonymised })); })}>{t('talent.purge_applicants')}</button>
            </Section>
        </AppLayout>
    );
}
