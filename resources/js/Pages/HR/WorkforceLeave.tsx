import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { WorkforceTabs, wpost, msg } from './WorkforceTabs';

interface Row { leave_type_id: number; code: string; name: string; policy: { accrual_per_month: number; max_balance: number | null; carry_over_max: number; carry_over_expiry_months: number | null } | null }

export default function WorkforceLeave() {
    const t = useT();
    const list = useApi<Row[]>('workforce/leave/policies');
    const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
    const [info, setInfo] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const act = async (fn: () => Promise<unknown>) => { setError(null); setInfo(null); try { setInfo(JSON.stringify(await fn())); list.reload(); } catch (e) { setError(msg(e)); } };
    const save = (r: Row, patch: Record<string, number | null>) => act(() => wpost('workforce/leave/policies', { accrual_per_month: 1.5, carry_over_max: 0, ...r.policy, ...patch, leave_type_id: r.leave_type_id }, 'PUT'));
    const val = (v: number | null | undefined) => (v === null || v === undefined ? '' : v);
    return (
        <AppLayout title={t('workforce.leave.title')} subtitle={t('workforce.leave.subtitle')}>
            <Head title={t('workforce.leave.title')} />
            <QueryStatus query={list} variant="table" />
            <WorkforceTabs active="/hr/workforce/leave" />
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            {info && <div className="mb-4 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm">{info}</div>}
            {list.data?.length === 0 && <div className="card mb-4"><EmptyFor kind="generic" /></div>}
            <div className="card mb-4 overflow-x-auto p-4 text-sm"><table className="w-full"><thead><tr className="text-left text-slate-500"><th>{t('workforce.leave.type')}</th><th>{t('workforce.leave.accrual')}</th><th>{t('workforce.leave.cap')}</th><th>{t('workforce.leave.carry')}</th><th>{t('workforce.leave.expiry')}</th></tr></thead>
                <tbody>{list.data?.map((r) => (<tr key={r.leave_type_id}><td>{r.name}</td>
                    <td><input type="number" step="0.25" className="input w-24" aria-label={`${r.name}: ${t('workforce.leave.accrual')}`} defaultValue={val(r.policy?.accrual_per_month)} onBlur={(e) => e.target.value && save(r, { accrual_per_month: Number(e.target.value) })} /></td>
                    <td><input type="number" className="input w-24" aria-label={`${r.name}: ${t('workforce.leave.cap')}`} defaultValue={val(r.policy?.max_balance)} onBlur={(e) => r.policy && save(r, { max_balance: e.target.value ? Number(e.target.value) : null })} /></td>
                    <td><input type="number" className="input w-24" aria-label={`${r.name}: ${t('workforce.leave.carry')}`} defaultValue={val(r.policy?.carry_over_max)} onBlur={(e) => e.target.value && save(r, { carry_over_max: Number(e.target.value) })} /></td>
                    <td><input type="number" className="input w-24" aria-label={`${r.name}: ${t('workforce.leave.expiry')}`} defaultValue={val(r.policy?.carry_over_expiry_months)} onBlur={(e) => r.policy && save(r, { carry_over_expiry_months: e.target.value ? Number(e.target.value) : null })} /></td></tr>))}</tbody></table></div>
            <div className="card flex flex-wrap items-end gap-3 p-4 text-sm">
                <label>{t('workforce.leave.month')}<input type="month" className="input block" value={month} onChange={(e) => setMonth(e.target.value)} /></label>
                <button className="btn-primary" onClick={() => act(() => wpost('workforce/leave/accrue', { month }))}>{t('workforce.leave.accrue')}</button>
                <button className="btn-secondary" onClick={() => act(() => wpost('workforce/leave/year-end', { year: Number(month.slice(0, 4)) }))}>{t('workforce.leave.year_end')}</button>
                <button className="btn-secondary" onClick={() => act(() => wpost('workforce/leave/expire'))}>{t('workforce.leave.expire')}</button>
            </div>
        </AppLayout>
    );
}
