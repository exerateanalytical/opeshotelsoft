import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num, Paged, Ref, today, usePerms } from './ControlShared';

interface Row { stock_item_id: number; code: string; name: string; unit: string; theoretical: number; actual: number; variance_qty: number; wastage: number; unexplained_qty: number; variance_pct: number | null; variance_value: number; unexplained_value: number; reasons: { reason: string; quantity: number }[] }
interface Report { store: string; rows: Row[]; totals: { theoretical_value: number; actual_value: number; variance_value: number; unexplained_value: number; variance_pct: number | null } }

export default function Variance() {
    const t = useT();
    const canManage = usePerms().includes('stockcontrol.manage');
    const outlets = useApi<Paged<Ref> | Ref[]>('outlets?per_page=100');
    const outletList = Array.isArray(outlets.data) ? outlets.data : outlets.data?.data ?? [];
    const [outlet, setOutlet] = useState('');
    const [from, setFrom] = useState(today().slice(0, 8) + '01');
    const [to, setTo] = useState(today());
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const report = useApi<Report>(outlet ? `stock-control/variance?outlet_id=${outlet}&from=${from}&to=${to}` : null, [outlet, from, to]);

    async function freeze() {
        try { await api('stock-control/variance/reviews', { method: 'POST', body: JSON.stringify({ outlet_id: Number(outlet), from, to }) }); setMsg({ ok: true, text: t('stock2.variance.frozen') }); } catch (e) { setMsg({ ok: false, text: errMsg(e) }); }
    }

    return (
        <AppLayout title={t('stock2.variance.title')} subtitle={t('stock2.variance.subtitle')}
            actions={canManage && outlet ? <button type="button" className="btn-secondary" onClick={freeze}>{t('stock2.variance.freeze')}</button> : undefined}>
            <Head title={t('stock2.variance.title')} />
            <div className="mb-3 flex flex-wrap gap-2">
                <select className="input" value={outlet} onChange={(e) => setOutlet(e.target.value)} aria-label={t('stock2.variance.pick_outlet')}>
                    <option value="">{t('stock2.variance.pick_outlet')}</option>
                    {outletList.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
                <input className="input" type="date" aria-label={t('stock.common.from')} value={from} onChange={(e) => setFrom(e.target.value)} />
                <input className="input" type="date" aria-label={t('stock.common.to')} value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            {report.error && <p className="mb-3 text-sm text-red-700">{report.error.message}</p>}
            {report.data && (
                <>
                    <div className="mb-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4 [&>*]:min-w-0">
                        {[['theoretical_value', report.data.totals.theoretical_value], ['actual_value', report.data.totals.actual_value], ['variance_value', report.data.totals.variance_value], ['unexplained_value', report.data.totals.unexplained_value]].map(([k, v]) => (
                            <div key={k as string} className="card p-3"><div className="text-xs text-slate-500">{t(`stock2.variance.${k}`)}</div><div className="font-medium">{fcfa(v as number)}</div></div>
                        ))}
                    </div>
                    <div className="card overflow-x-auto p-3">
                        <table className="w-full table-auto text-sm">
                            <thead><tr className="text-left text-xs uppercase text-slate-500">
                                {['item', 'theoretical', 'actual', 'variance', 'wastage', 'unexplained', 'value', 'reasons'].map((h) => <th key={h} className="px-2 py-2">{t(`stock2.variance.col.${h}`)}</th>)}</tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {report.data.rows.map((r) => (
                                    <tr key={r.stock_item_id}>
                                        <td className="px-2 py-2">{r.name}</td><td className="px-2 py-2">{num(r.theoretical)} {r.unit}</td><td className="px-2 py-2">{num(r.actual)} {r.unit}</td>
                                        <td className={`px-2 py-2 ${r.variance_value > 0 ? 'text-red-700' : ''}`}>{num(r.variance_qty)}{r.variance_pct !== null ? ` (${r.variance_pct} %)` : ''}</td>
                                        <td className="px-2 py-2">{num(r.wastage)}</td><td className="px-2 py-2">{num(r.unexplained_qty)}</td><td className="px-2 py-2">{fcfa(r.variance_value)}</td>
                                        <td className="px-2 py-2 text-xs">{r.reasons.map((x) => `${t(`stock2.reason.${x.reason}`)} ${num(x.quantity)}`).join(', ')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {report.data.rows.length === 0 && <p className="py-4 text-center text-sm text-slate-500">{t('stock2.common.nothing')}</p>}
                    </div>
                </>
            )}
        </AppLayout>
    );
}
