import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { PeopleTabs } from './Index';
import { EmployeeRef, errorText, post } from './lib';
import Alert from '@/Components/ui/Alert';

interface Cycle { id: number; name: string; period_start: string; period_end: string; status: string; scale: number; reviews_count?: number }
interface Objective { id: number; title: string; weight: number; self_score: number | null; manager_score: number | null }
interface Review { id: number; status: string; self_rating: number | null; manager_rating: number | null; calibrated_rating: number | null; employee: EmployeeRef | null; manager_employee: EmployeeRef | null; objectives?: Objective[]; objectives_count?: number }
interface DeptSummary { department: string | null; reviews: number; completed: number; avg_self: number | null; avg_manager: number | null; avg_final: number | null; distribution: Record<string, number> }

export default function PeopleReviews() {
    const t = useT();
    const cycles = useApi<Cycle[]>('people/cycles');
    const [cycleId, setCycleId] = useState<number | null>(null);
    const detail = useApi<{ cycle: Cycle; reviews: Review[] }>(cycleId ? `people/cycles/${cycleId}` : null);
    const summary = useApi<{ departments: DeptSummary[] }>(cycleId ? `people/cycles/${cycleId}/calibration-summary` : null);
    const [form, setForm] = useState({ name: '', period_start: '', period_end: '' });
    const [open, setOpen] = useState<Review | null>(null);
    const [objectives, setObjectives] = useState<{ title: string; weight: string }[]>([]);
    const [scores, setScores] = useState<Record<number, string>>({});
    const [error, setError] = useState<string | null>(null);

    const run = async (fn: () => Promise<unknown>) => {
        setError(null);
        try { await fn(); cycles.reload(); detail.reload(); summary.reload(); } catch (e) { setError(errorText(e)); }
    };
    const loadReview = async (r: Review) => {
        const full = await (await import('@/lib/api')).api<Review>(`people/reviews/${r.id}`);
        setOpen(full);
        setObjectives(full.objectives?.length ? full.objectives.map((o) => ({ title: o.title, weight: String(o.weight) })) : [{ title: '', weight: '100' }]);
        setScores({});
    };
    const cycle = detail.data?.cycle;

    return (
        <AppLayout title={t('people.reviews.title')} subtitle={t('people.reviews.subtitle')}>
            <Head title={t('people.reviews.title')} />
            <QueryStatus query={cycles} variant="table" />
            <PeopleTabs active="/people/reviews" />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}

            <form className="card mb-4 grid gap-3 p-4 text-sm sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); run(() => post('people/cycles', form)); }}>
                <label>{t('people.reviews.cycle_name')}<input className="input w-full" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
                <label>{t('people.reviews.start')}<input type="date" className="input w-full" required value={form.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} /></label>
                <label>{t('people.reviews.end')}<input type="date" className="input w-full" required value={form.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} /></label>
                <div className="flex items-end"><button className="btn-primary">{t('people.reviews.new_cycle')}</button></div>
            </form>

            <div className="card mb-4 table-wrap">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('people.reviews.cycle_name')}</th><th>{t('people.reviews.period')}</th><th>{t('people.status')}</th><th>{t('people.reviews.count')}</th><th /></tr></thead>
                    <tbody>
                        {cycles.data?.length === 0 && <tr><td colSpan={8} className="p-0"><EmptyFor kind="events" /></td></tr>}
                        {cycles.data?.map((c) => (
                            <tr key={c.id} className={`border-t border-slate-100 ${cycleId === c.id ? 'bg-slate-50' : ''}`}>
                                <td className="p-2"><button className="underline" onClick={() => setCycleId(c.id)}>{c.name}</button></td>
                                <td>{formatDate(c.period_start)} – {formatDate(c.period_end)}</td>
                                <td>{t(`people.cycle_status.${c.status}`)}</td>
                                <td>{c.reviews_count}</td>
                                <td className="space-x-2 p-2 text-right">
                                    {['draft', 'open'].includes(c.status) && <button className="btn-secondary" onClick={() => run(() => post(`people/cycles/${c.id}/open`))}>{t('people.reviews.open')}</button>}
                                    {c.status === 'open' && <button className="btn-secondary" onClick={() => run(() => post(`people/cycles/${c.id}/calibration`))}>{t('people.reviews.calibration')}</button>}
                                    {c.status === 'calibration' && <button className="btn-secondary" onClick={() => run(() => post(`people/cycles/${c.id}/close`))}>{t('people.reviews.close')}</button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {cycle && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="card table-wrap">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th className="p-2">{t('people.employee')}</th><th>{t('people.status')}</th><th>{t('people.reviews.self')}</th><th>{t('people.reviews.manager')}</th><th>{t('people.reviews.final')}</th></tr></thead>
                            <tbody>
                                {detail.data?.reviews.map((r) => (
                                    <tr key={r.id} className="cursor-pointer border-t border-slate-100" onClick={() => loadReview(r)}>
                                        <td className="p-2">{r.employee?.name}</td><td>{t(`people.review_status.${r.status}`)}</td>
                                        <td>{r.self_rating ?? '-'}</td><td>{r.manager_rating ?? '-'}</td><td>{r.calibrated_rating ?? r.manager_rating ?? '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="card p-4 text-sm">
                        <h3 className="mb-2 font-semibold">{t('people.reviews.summary')}</h3>
                        <div className="table-wrap"><table className="table-auto w-full">
                            <thead><tr className="text-left text-slate-500"><th>{t('people.department')}</th><th>{t('people.reviews.completed')}</th><th>{t('people.reviews.self')}</th><th>{t('people.reviews.manager')}</th><th>{t('people.reviews.final')}</th><th>{t('people.reviews.distribution')}</th></tr></thead>
                            <tbody>
                                {summary.data?.departments.map((d, i) => (
                                    <tr key={i} className="border-t border-slate-100">
                                        <td className="py-1">{d.department ?? '-'}</td><td>{d.completed}/{d.reviews}</td><td>{d.avg_self ?? '-'}</td><td>{d.avg_manager ?? '-'}</td><td>{d.avg_final ?? '-'}</td>
                                        <td className="text-xs text-slate-500">{Object.entries(d.distribution).map(([k, v]) => `${k}:${v}`).join(' ')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table></div>
                    </div>
                </div>
            )}

            {open && cycle && (
                <div className="card mt-4 p-4 text-sm">
                    <h3 className="mb-2 font-semibold">{open.employee?.name} — {t(`people.review_status.${open.status}`)}</h3>
                    {open.status === 'pending' && (
                        <div className="mb-3 space-y-2">
                            <p className="text-slate-500">{t('people.reviews.weights_hint')}</p>
                            {objectives.map((o, i) => (
                                <div key={i} className="flex gap-2">
                                    <input className="input flex-1" placeholder={t('people.reviews.objective')} value={o.title} onChange={(e) => setObjectives(objectives.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} />
                                    <input className="input w-20" type="number" min={1} max={100} value={o.weight} onChange={(e) => setObjectives(objectives.map((x, j) => (j === i ? { ...x, weight: e.target.value } : x)))} />
                                </div>
                            ))}
                            <button className="btn-secondary mr-2" onClick={() => setObjectives([...objectives, { title: '', weight: '0' }])}>{t('people.reviews.add_objective')}</button>
                            <button className="btn-primary" onClick={() => run(async () => { await post(`people/reviews/${open.id}/objectives`, { objectives: objectives.map((o) => ({ title: o.title, weight: Number(o.weight) })) }, 'PUT'); await loadReview(open); })}>{t('people.save')}</button>
                        </div>
                    )}
                    {open.objectives?.map((o) => (
                        <div key={o.id} className="flex items-center justify-between border-t border-slate-100 py-1">
                            <span>{o.title} ({o.weight} %) <span className="text-slate-500">{o.self_score ?? '-'} / {o.manager_score ?? '-'}</span></span>
                            {['pending', 'self_done'].includes(open.status) && <input className="input w-20" type="number" min={1} max={cycle.scale} value={scores[o.id] ?? ''} onChange={(e) => setScores({ ...scores, [o.id]: e.target.value })} />}
                        </div>
                    ))}
                    <div className="mt-3 space-x-2">
                        {open.status === 'pending' && !!open.objectives?.length && <button className="btn-primary" onClick={() => run(async () => { await post(`people/reviews/${open.id}/self`, { scores }); await loadReview(open); })}>{t('people.reviews.submit_self')}</button>}
                        {open.status === 'self_done' && <button className="btn-primary" onClick={() => run(async () => { await post(`people/reviews/${open.id}/manager`, { scores }); await loadReview(open); })}>{t('people.reviews.submit_manager')}</button>}
                        {open.status === 'manager_done' && cycle.status === 'calibration' && (
                            <button className="btn-secondary" onClick={() => { const v = window.prompt(t('people.reviews.calibrated_rating')); if (v) run(() => post(`people/reviews/${open.id}/calibrate`, { rating: Number(v) })); }}>{t('people.reviews.calibrate')}</button>
                        )}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
