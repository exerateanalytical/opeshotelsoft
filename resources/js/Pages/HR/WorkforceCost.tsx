import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { LastUpdated } from '@/Components/ui/ApiState';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { WorkforceTabs, monday, addDays } from './WorkforceTabs';

interface Row { date?: string; department: string | null; planned_cost: number; actual_cost: number; revenue: number; labour_pct: number | null }
interface Out { rows: (Row & { date: string })[]; departments: Row[]; totals: Row }
const n = (v: number) => new Intl.NumberFormat('fr-FR').format(v);

export default function WorkforceCost() {
    const t = useT();
    const [from, setFrom] = useState(monday());
    const [to, setTo] = useState(addDays(monday(), 6));
    const d = useApi<Out>(`workforce/dashboard?from=${from}&to=${to}`);
    return (
        <AppLayout title={t('workforce.cost.title')} subtitle={t('workforce.cost.subtitle')}>
            <Head title={t('workforce.cost.title')} />
            <QueryStatus query={d} variant="detail" />
            <LastUpdated at={d.updatedAt} />
            <WorkforceTabs active="/hr/workforce/cost" />
            <div className="card mb-4 flex flex-wrap gap-3 p-4 text-sm"><input type="date" className="input" aria-label={t('ui2.common.from')} value={from} onChange={(e) => setFrom(e.target.value)} /><input type="date" className="input" aria-label={t('ui2.common.to')} value={to} onChange={(e) => setTo(e.target.value)} /></div>
            {d.data && d.data.rows.length === 0 && d.data.departments.length === 0 && <div className="card"><EmptyFor kind="generic" /></div>}
            {d.data && (<>
                <div className="mb-4 grid gap-3 text-sm sm:grid-cols-4">
                    {([['planned_cost', d.data.totals.planned_cost], ['actual_cost', d.data.totals.actual_cost], ['revenue', d.data.totals.revenue]] as const).map(([k, v]) => <div key={k} className="card p-4"><div className="text-slate-500">{t(`workforce.cost.${k}`)}</div><div className="text-lg font-semibold">{n(v)}</div></div>)}
                    <div className="card p-4"><div className="text-slate-500">{t('workforce.cost.labour_pct')}</div><div className="text-lg font-semibold">{d.data.totals.labour_pct ?? '-'} %</div></div>
                </div>
                <div className="card mb-4 overflow-x-auto p-4 text-sm"><h2 className="mb-2 font-semibold">{t('workforce.cost.by_department')}</h2>
                    <div className="table-wrap"><table className="table-auto w-full"><thead><tr className="text-left text-slate-500"><th>{t('workforce.cost.department')}</th><th>{t('workforce.cost.planned_cost')}</th><th>{t('workforce.cost.actual_cost')}</th><th>{t('workforce.cost.revenue')}</th><th>%</th></tr></thead>
                        <tbody>{d.data.departments.map((r, i) => <tr key={i}><td>{r.department ?? '-'}</td><td>{n(r.planned_cost)}</td><td>{n(r.actual_cost)}</td><td>{n(r.revenue)}</td><td>{r.labour_pct ?? '-'}</td></tr>)}</tbody></table></div></div>
                <div className="card overflow-x-auto p-4 text-sm"><h2 className="mb-2 font-semibold">{t('workforce.cost.by_day')}</h2>
                    <div className="table-wrap"><table className="table-auto w-full"><thead><tr className="text-left text-slate-500"><th>{t('workforce.date')}</th><th>{t('workforce.cost.department')}</th><th>{t('workforce.cost.planned_cost')}</th><th>{t('workforce.cost.actual_cost')}</th><th>{t('workforce.cost.revenue')}</th><th>%</th></tr></thead>
                        <tbody>{d.data.rows.map((r, i) => <tr key={i}><td>{r.date}</td><td>{r.department ?? '-'}</td><td>{n(r.planned_cost)}</td><td>{n(r.actual_cost)}</td><td>{n(r.revenue)}</td><td>{r.labour_pct ?? '-'}</td></tr>)}</tbody></table></div></div>
            </>)}
        </AppLayout>
    );
}
