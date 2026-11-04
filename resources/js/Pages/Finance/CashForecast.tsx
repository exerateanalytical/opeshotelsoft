import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Banner, Field, FinEmptyState } from './shared';
import { M, Table, Td, Th } from './coreShared';
import { AbNav } from './ab3Shared';

interface Week { week: number; from: string; to: string; in: Record<string, number>; out: Record<string, number>; total_in: number; total_out: number; net: number; opening: number; closing: number }
interface Forecast { weeks: Week[]; opening: number; closing: number; lowest: { week: number | null; balance: number }; first_negative_week: number | null; overdue_receivables_in_week_1: number; pending_approval_payables: number; notes: string[] }
const ROWS_IN = ['receivables', 'recurring_in', 'budget_in'];
const ROWS_OUT = ['payables', 'payables_pending', 'payroll', 'payroll_taxes', 'recurring_out', 'budget_out'];

export default function CashForecast() {
    const t = useT();
    const [pct, setPct] = useState(100);
    const [pending, setPending] = useState(true);
    const [budget, setBudget] = useState(false);
    const f = useApi<Forecast>(`accounting3/close/forecast?collection_pct=${pct}&include_pending=${pending ? 1 : 0}&include_budget=${budget ? 1 : 0}`, [pct, pending, budget]);
    const d = f.data;

    return (
        <AppLayout title={t('acc3.fc.title')} subtitle={t('acc3.fc.subtitle')}>
            <Head title={t('acc3.fc.title')} />
            <AbNav active="/finance/cash-forecast" />
            <Banner error={f.error?.message} />
            <div className="card mb-4 flex flex-wrap items-end gap-4 p-4">
                <Field label={t('acc3.fc.collection')}><input type="number" min={0} max={100} className="input w-24" value={pct} onChange={(e) => setPct(Number(e.target.value))} /></Field>
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5 shrink-0" checked={pending} onChange={(e) => setPending(e.target.checked)} />{t('acc3.fc.include_pending')}</label>
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5 shrink-0" checked={budget} onChange={(e) => setBudget(e.target.checked)} />{t('acc3.fc.include_budget')}</label>
            </div>
            <QueryStatus query={f} variant="table" />
            {d && d.weeks.length === 0 && <div className="card"><FinEmptyState k="forecast" /></div>}
            {d && d.weeks.length > 0 && (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                        {[[t('acc3.fc.opening'), M(d.opening)], [t('acc3.fc.closing'), M(d.closing)], [t('acc3.fc.lowest'), `${M(d.lowest.balance)} (${t('acc3.fc.week')} ${d.lowest.week ?? '-'})`],
                            [t('acc3.fc.negative'), d.first_negative_week ? `${t('acc3.fc.week')} ${d.first_negative_week}` : t('acc3.fc.none')]].map(([k, v]) => (
                            <div key={k} className="card p-3"><p className="text-xs text-slate-500">{k}</p><p className="text-base font-semibold">{v}</p></div>
                        ))}
                    </div>
                    <div className="card overflow-x-auto p-4">
                        <Table head={<><Th />{d.weeks.map((w) => <Th key={w.week} r>{t('acc3.fc.week')} {w.week}<br /><span className="font-normal">{w.from.slice(5)}</span></Th>)}</>}>
                            <tr><Td b>{t('acc3.fc.opening')}</Td>{d.weeks.map((w) => <Td key={w.week} r>{M(w.opening)}</Td>)}</tr>
                            {ROWS_IN.map((k) => <tr key={k}><Td>{t(`acc3.fc.row.${k}`)}</Td>{d.weeks.map((w) => <Td key={w.week} r>{w.in[k] ? M(w.in[k]) : ''}</Td>)}</tr>)}
                            {ROWS_OUT.map((k) => <tr key={k}><Td>{t(`acc3.fc.row.${k}`)}</Td>{d.weeks.map((w) => <Td key={w.week} r>{w.out[k] ? M(-w.out[k]) : ''}</Td>)}</tr>)}
                            <tr><Td b>{t('acc3.fc.net')}</Td>{d.weeks.map((w) => <Td key={w.week} r b>{M(w.net)}</Td>)}</tr>
                            <tr><Td b>{t('acc3.fc.closing')}</Td>{d.weeks.map((w) => <td key={w.week} className={`p-2 text-right font-semibold ${w.closing < 0 ? 'text-red-700' : ''}`}>{M(w.closing)}</td>)}</tr>
                        </Table>
                    </div>
                    <p className="mt-3 text-xs text-slate-500">{d.notes.join(' ')}</p>
                </>
            )}
        </AppLayout>
    );
}
