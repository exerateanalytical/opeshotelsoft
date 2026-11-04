import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import QueryStatus from '@/Components/ui/QueryStatus';
import { useT } from '@/lib/i18n';
import { Banner, Pill, Section, TalentTabs, msg, tget, tsend, unwrap } from './TalentShared';

interface Cell { budgeted: number | null; actual: number; variance: number | null; vacancies: number }
interface HC { months: string[]; rows: { department_id: number; department: string; open_posts: number; months: Record<string, Cell> }[] }
interface Pos { position_id: number; position: string; risk: string; incumbents: string[]; successors: { employee_id: number; name: string; readiness: string }[]; covered: boolean; at_risk: boolean }
interface Emp { id: number; first_name: string; last_name: string }
interface Position { id: number; title: string }

export default function TalentPlanning() {
    const t = useT();
    const main = useApi<HC>('hr/talent/headcount');
    const hc = main.data;
    const [succ, setSucc] = useState<Pos[]>([]);
    const [emps, setEmps] = useState<Emp[]>([]);
    const [positions, setPositions] = useState<Position[]>([]);
    const [err, setErr] = useState<string | null>(null);
    const [b, setB] = useState({ department_id: '', month: new Date().toISOString().slice(0, 7), budgeted: '' });
    const [k, setK] = useState({ position_id: '', risk: 'medium' });
    const [s, setS] = useState({ position_id: '', employee_id: '', readiness: 'now' });

    const loadAux = () => {
        tget<{ positions: Pos[] }>('succession').then((r) => setSucc(r.positions)).catch(() => undefined);
        api<Emp[] | { data: Emp[] }>('hr/employees?status=active').then((r) => setEmps(unwrap(r))).catch(() => undefined);
        api<Position[]>('hr/positions').then((r) => setPositions(unwrap(r as Position[]))).catch(() => undefined);
    };
    const load = () => { main.reload(); loadAux(); };
    useEffect(loadAux, []);
    const run = async (fn: () => Promise<unknown>) => { setErr(null); try { await fn(); load(); } catch (e) { setErr(msg(e)); } };

    return (
        <AppLayout title={t('talent.planning.title')} subtitle={t('talent.planning.subtitle')}>
            <Head title={t('talent.planning.title')} />
            <TalentTabs active="/hr/talent/planning" />
            <Banner error={err} />
            <QueryStatus query={main} variant="table" />
            <Section title={t('talent.headcount')}>
                {hc && hc.rows.length === 0 && <EmptyFor kind="generic" />}
                <div className="table-wrap"><table className="table-auto w-full text-sm">
                    <thead><tr className="text-left"><th className="p-2">{t('talent.department')}</th>{hc?.months.map((m) => <th key={m} className="p-2">{m}</th>)}<th className="p-2">{t('talent.open_posts')}</th></tr></thead>
                    <tbody>{hc?.rows.map((r) => (
                        <tr key={r.department_id} className="border-t border-slate-100"><td className="p-2">{r.department}</td>
                            {hc.months.map((m) => { const c = r.months[m]; return <td key={m} className="p-2">{c.actual} / {c.budgeted ?? '-'}{c.vacancies > 0 && <span className="text-amber-700"> (-{c.vacancies})</span>}</td>; })}
                            <td className="p-2">{r.open_posts}</td></tr>))}</tbody>
                </table></div>
                <p className="mt-1 text-sm text-slate-600">{t('talent.headcount_hint')}</p>
                <div className="mt-3 flex flex-wrap gap-2 text-sm">
                    <select className="select" aria-label={t('ui2.hr.department')} value={b.department_id} onChange={(e) => setB({ ...b, department_id: e.target.value })}><option value="" />{hc?.rows.map((r) => <option key={r.department_id} value={r.department_id}>{r.department}</option>)}</select>
                    <input className="input" type="month" aria-label={t('ui2.hr.month')} value={b.month} onChange={(e) => setB({ ...b, month: e.target.value })} />
                    <input className="input w-24" type="number" placeholder="0" aria-label={t('ui2.hr.budgeted')} value={b.budgeted} onChange={(e) => setB({ ...b, budgeted: e.target.value })} />
                    <button className="btn-primary" disabled={!b.department_id || b.budgeted === ''} onClick={() => run(() => tsend('headcount', { department_id: Number(b.department_id), month: b.month, budgeted: Number(b.budgeted) }, 'PUT'))}>{t('talent.set_budget')}</button>
                </div>
            </Section>
            <Section title={t('talent.succession')}>
                {succ.map((p) => (
                    <div key={p.position_id} className="mb-3 text-sm">
                        <p className="font-semibold">{p.position} <Pill tone={p.at_risk ? 'bad' : p.covered ? 'ok' : 'warn'}>{p.at_risk ? t('talent.at_risk') : p.covered ? t('talent.covered') : t('talent.not_ready')}</Pill> <Pill tone="muted">{t(`talent.risk.${p.risk}`)}</Pill></p>
                        <p className="text-slate-600">{t('talent.incumbents')}: {p.incumbents.join(', ') || '-'}</p>
                        <ul className="ml-4 list-disc">{p.successors.map((x) => <li key={x.employee_id}>{x.name} - {t(`talent.readiness.${x.readiness}`)}</li>)}</ul>
                    </div>))}
                <div className="flex flex-wrap gap-2 text-sm">
                    <select className="select" aria-label={t('ui2.hr.position')} value={k.position_id} onChange={(e) => setK({ ...k, position_id: e.target.value })}><option value="" />{positions.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select>
                    <select className="select" aria-label={t('ui2.hr.risk')} value={k.risk} onChange={(e) => setK({ ...k, risk: e.target.value })}>{['low', 'medium', 'high'].map((r) => <option key={r} value={r}>{t(`talent.risk.${r}`)}</option>)}</select>
                    <button className="btn-secondary" disabled={!k.position_id} onClick={() => run(() => tsend('key-positions', { position_id: Number(k.position_id), risk: k.risk }))}>{t('talent.mark_key')}</button>
                </div>
                <div className="mt-2 flex flex-wrap gap-2 text-sm">
                    <select className="select" aria-label={t('ui2.hr.position')} value={s.position_id} onChange={(e) => setS({ ...s, position_id: e.target.value })}><option value="" />{positions.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select>
                    <select className="select" aria-label={t('ui2.hr.employee')} value={s.employee_id} onChange={(e) => setS({ ...s, employee_id: e.target.value })}><option value="" />{emps.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}</select>
                    <select className="select" aria-label={t('ui2.hr.readiness')} value={s.readiness} onChange={(e) => setS({ ...s, readiness: e.target.value })}>{['now', '1_2y', '3y_plus'].map((r) => <option key={r} value={r}>{t(`talent.readiness.${r}`)}</option>)}</select>
                    <button className="btn-secondary" disabled={!s.position_id || !s.employee_id} onClick={() => run(() => tsend('successors', { position_id: Number(s.position_id), employee_id: Number(s.employee_id), readiness: s.readiness }))}>{t('talent.add_successor')}</button>
                </div>
            </Section>
        </AppLayout>
    );
}
