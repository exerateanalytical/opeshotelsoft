import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, FinEmptyState, Loading } from './shared';
import { Table, Td, Th } from './coreShared';
import { AbNav } from './ab3Shared';
import { useCan } from '@/lib/useCan';
import CloseTemplatesPanel, { AssignTaskModal } from './CloseTemplatesPanel';

interface Task { id: number; title: string; owner_name: string | null; owner_role: string | null; due_date: string; required: boolean; auto_check: string | null; check_result: { ok: boolean; detail: string } | null; status: string; signer_name: string | null; signed_at: string | null; overdue: boolean }
interface Run { id: number; year: number; month: number; status: string; progress: { total: number; done: number; open_required: number }; tasks: Task[] }
interface RunRow { id: number; year: number; month: number; status: string; progress: { total: number; done: number } }

export default function MonthClose() {
    const t = useT();
    const now = new Date();
    const [ym, setYm] = useState(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
    const [year, month] = ym.split('-').map(Number);
    const runs = useApi<RunRow[]>('accounting3/close/runs');
    const run = useApi<Run>(`accounting3/close/runs/${year}/${month}`, [ym]);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [override, setOverride] = useState<Record<number, string>>({});
    const can = useCan();
    const [assign, setAssign] = useState<{ id: number; title: string } | null>(null);
    const exists = runs.data?.some((r) => r.year === year && r.month === month);

    const act = async (fn: () => Promise<unknown>, msg = t('acc3.saved')) => {
        setError(null); setOk(null);
        try { await fn(); setOk(msg); runs.reload(); run.reload(); } catch (e) { setError(errMsg(e)); }
    };
    const r = exists ? run.data : null;

    return (
        <AppLayout title={t('acc3.cl.title')} subtitle={t('acc3.cl.subtitle')}>
            <Head title={t('acc3.cl.title')} />
            <QueryStatus query={runs} variant="table" />
            <AbNav active="/finance/month-close" />
            <Banner error={error} success={ok} />
            <div className="card mb-4 p-4">
                <div className="flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.cl.month')}><input type="month" className="input" value={ym} onChange={(e) => setYm(e.target.value)} /></Field>
                    {!exists && !runs.loading && <button className="btn-primary" onClick={() => act(() => api('accounting3/close/runs', { method: 'POST', body: JSON.stringify({ year, month }) }), t('acc3.cl.started'))}>{t('acc3.cl.start')}</button>}
                    {r && <p className="pb-2 text-sm text-slate-600">{t('acc3.cl.progress', { done: r.progress.done, total: r.progress.total })} - {r.status === 'complete' ? t('acc3.cl.complete') : t('acc3.cl.open_status')}</p>}
                </div>
                {r?.status === 'complete' && <p className="mt-2 text-sm text-emerald-700">{t('acc3.cl.lock_hint')}</p>}
            </div>
            <Loading on={runs.loading || (!!exists && run.loading)} />
            {runs.data && !exists && <div className="card"><FinEmptyState k="close" action={<button type="button" className="btn-primary" onClick={() => act(() => api('accounting3/close/runs', { method: 'POST', body: JSON.stringify({ year, month }) }), t('acc3.cl.started'))}>{t('acc3.cl.start')}</button>} /></div>}
            {r && (
                <div className="card p-4">
                    <Table head={<><Th>{t('acc3.cl.task')}</Th><Th>{t('acc3.cl.owner')}</Th><Th>{t('acc3.cl.due')}</Th><Th>{t('acc3.cl.check')}</Th><Th>{t('acc3.cl.signoff')}</Th><Th /></>}>
                        {r.tasks.map((k) => (
                            <tr key={k.id}>
                                <Td>{k.title}{!k.required && <span className="ml-1 text-xs text-slate-500">({t('acc3.cl.optional')})</span>}</Td>
                                <Td>
                                    <span className="flex flex-wrap items-center gap-2">
                                        {k.owner_name ?? t(`acc3.cl.role.${k.owner_role}`)}
                                        {k.status === 'pending' && r.status !== 'complete' && can('accounting.close_manage') && <button type="button" className="btn-ghost btn-sm" aria-label={`${t('gapb.assign.action')}: ${k.title}`} onClick={() => setAssign({ id: k.id, title: k.title })}>{t('gapb.assign.action')}</button>}
                                    </span>
                                </Td>
                                <td className={`p-2 ${k.overdue ? 'font-semibold text-red-700' : ''}`}>{k.due_date}</td>
                                <Td>
                                    {k.auto_check ? (
                                        <span className="flex items-center gap-2">
                                            <span className={k.check_result ? (k.check_result.ok ? 'text-emerald-700' : 'text-red-700') : 'text-slate-500'}>{k.check_result ? k.check_result.detail : '-'}</span>
                                            {k.status === 'pending' && <button className="btn-secondary" onClick={() => act(() => api(`accounting3/close/tasks/${k.id}/check`, { method: 'POST' }), t('acc3.cl.checked'))}>{t('acc3.cl.run_check')}</button>}
                                        </span>
                                    ) : '-'}
                                </Td>
                                <Td>{k.status === 'done' ? `${k.signer_name} ${k.signed_at?.slice(0, 10)}` : ''}</Td>
                                <Td r>
                                    {k.status === 'pending' && r.status !== 'complete' && (
                                        <span className="flex justify-end gap-1">
                                            {k.check_result && !k.check_result.ok && <input className="input w-40" placeholder={t('acc3.cl.override')} aria-label={t('acc3.cl.override')} value={override[k.id] ?? ''} onChange={(e) => setOverride({ ...override, [k.id]: e.target.value })} />}
                                            <button className="btn-primary" onClick={() => act(() => api(`accounting3/close/tasks/${k.id}/sign`, { method: 'POST', body: JSON.stringify({ override_reason: override[k.id] || null }) }), t('acc3.cl.signed'))}>{t('acc3.cl.sign')}</button>
                                        </span>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </Table>
                </div>
            )}
            <CloseTemplatesPanel />
            <AssignTaskModal task={assign} onClose={() => setAssign(null)} onDone={() => { setAssign(null); run.reload(); }} />
        </AppLayout>
    );
}
