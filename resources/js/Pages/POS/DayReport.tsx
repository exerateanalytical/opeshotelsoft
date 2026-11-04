import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Outlet } from './shared';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import Alert from '@/Components/ui/Alert';

interface Report {
    date: string; checks: number; covers: number; gross: number; discounts: number; net: number; voids: number;
    by_settlement: Record<string, number> | []; payments: Record<string, number> | [];
}

export default function DayReport({ outlets }: { outlets: Outlet[] }) {
    const t = useT();
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [outlet, setOutlet] = useState('');
    const r = useApi<Report>(`pos/reports/day?date=${date}${outlet ? `&outlet_id=${outlet}` : ''}`);
    const d = r.data;
    const stats: [string, string][] = d ? [
        [t('pos.report.checks'), String(d.checks)], [t('pos.report.covers'), String(d.covers)], [t('pos.report.gross'), formatCurrency(d.gross)],
        [t('pos.report.discounts'), formatCurrency(d.discounts)], [t('pos.report.net'), formatCurrency(d.net)], [t('pos.report.voided_lines'), String(d.voids)],
    ] : [];
    const table = (title: string, rows: Record<string, number> | []) => (
        <div className="card p-4 overflow-x-auto">
            <h3 className="font-semibold text-slate-700 mb-2">{title}</h3>
            <table className="table-auto w-full text-sm">
                <tbody>
                    {Object.entries(rows).map(([k, v]) => <tr key={k} className="border-t"><td className="py-2">{k.replace('_', ' ')}</td><td className="text-right">{formatCurrency(Number(v))}</td></tr>)}
                    {Object.keys(rows).length === 0 && <tr><td className="text-slate-500 py-2">{t('pos.report.nothing_yet')}</td></tr>}
                </tbody>
            </table>
        </div>
    );
    return (
        <div>
            <div className="flex flex-wrap gap-2 mb-4">
                <input type="date" className="input" aria-label={t('fin.col.date')} value={date} onChange={(e) => setDate(e.target.value)} />
                <select className="select w-full sm:w-48" value={outlet} onChange={(e) => setOutlet(e.target.value)} aria-label={t('pos.common.all_outlets')}>
                    <option value="">{t('pos.common.all_outlets')}</option>
                    {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
                <OpsPrintButton path={`pos-day-report?date=${date}${outlet ? `&outlet_id=${outlet}` : ''}`} label={t('documents4.day_report')} />
            </div>
            {r.loading && !d && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            {r.error && <Alert variant="danger" className="mb-4">{r.error.message}</Alert>}
            {d && (
                <>
                    <p className="text-sm text-slate-500 mb-3">{t('pos.report.settled_for', { date: formatDate(d.date) })}</p>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4 [&>*]:min-w-0">
                        {stats.map(([k, v]) => <div key={k} className="card p-4"><div className="text-xs text-slate-500">{k}</div><div className="text-lg font-semibold text-slate-800">{v}</div></div>)}
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 [&>*]:min-w-0">
                        {table(t('pos.report.by_settlement'), d.by_settlement)}
                        {table(t('pos.report.by_payment'), d.payments)}
                    </div>
                </>
            )}
        </div>
    );
}
