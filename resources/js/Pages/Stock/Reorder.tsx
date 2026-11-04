import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import StockTabs from './StockTabs';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num, usePerms } from './ControlShared';

interface Line { stock_item_id: number; store_id: number; code: string; name: string; purchase_unit: string; on_hand: number; on_order: number; par_level: number; reorder_point: number; suggested_quantity: number; unit_price: number }
interface Group { supplier_id: number | null; supplier: string | null; lead_time_days: number; expected_date: string; estimated_total: number; lines: Line[] }

export default function Reorder() {
    const t = useT();
    const perms = usePerms();
    const canConvert = perms.includes('stockcontrol.manage') && perms.includes('purchasing.manage');
    const data = useApi<{ groups: Group[] }>('stock-control/reorder/suggestions');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

    async function convert(ids?: number[]) {
        try {
            const r = await api<{ orders: { number: string }[]; skipped: unknown[] }>('stock-control/reorder/convert', { method: 'POST', body: JSON.stringify(ids ? { supplier_ids: ids } : {}) });
            setMsg({ ok: true, text: t('stock2.reorder.created', { count: r.orders.length, numbers: r.orders.map((o) => o.number).join(', ') }) });
            data.reload();
        } catch (e) { setMsg({ ok: false, text: errMsg(e) }); }
    }
    const groups = data.data?.groups ?? [];

    return (
        <AppLayout title={t('stock2.reorder.title')} subtitle={t('stock2.reorder.subtitle')}
            actions={canConvert && groups.some((g) => g.supplier_id) ? <button type="button" className="btn-primary" onClick={() => convert()}>{t('stock2.reorder.convert_all')}</button> : undefined}>
            <Head title={t('stock2.reorder.title')} />
            <QueryStatus query={data} variant="cards" />
            <StockTabs current="reorder" />
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            {data.data && groups.length === 0 && <p className="text-sm text-slate-500">{t('stock2.reorder.empty')}</p>}
            {groups.map((g) => (
                <div key={g.supplier_id ?? 0} className="card mb-3 overflow-x-auto p-3 text-sm">
                    <div className="mb-2 flex flex-wrap items-center gap-3">
                        <h2 className="font-medium">{g.supplier ?? t('stock2.reorder.no_supplier')}</h2>
                        <span className="text-xs text-slate-500">{t('stock2.reorder.lead', { days: g.lead_time_days, date: g.expected_date })}</span>
                        <span className="ml-auto">{fcfa(g.estimated_total)}</span>
                        {canConvert && g.supplier_id && <button type="button" className="btn-secondary" onClick={() => convert([g.supplier_id as number])}>{t('stock2.reorder.convert')}</button>}
                    </div>
                    <table className="w-full table-auto">
                        <thead><tr className="text-left text-xs uppercase text-slate-500">
                            {['item', 'on_hand', 'on_order', 'point', 'par', 'order', 'price'].map((h) => <th key={h} className="px-2 py-1">{t(`stock2.reorder.col.${h}`)}</th>)}</tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {g.lines.map((l) => (
                                <tr key={`${l.store_id}-${l.stock_item_id}`}>
                                    <td className="px-2 py-1">{l.name}</td><td className="px-2 py-1">{num(l.on_hand)}</td><td className="px-2 py-1">{num(l.on_order)}</td>
                                    <td className="px-2 py-1">{num(l.reorder_point)}</td><td className="px-2 py-1">{num(l.par_level)}</td>
                                    <td className="px-2 py-1">{l.suggested_quantity} {l.purchase_unit}</td><td className="px-2 py-1">{fcfa(l.unit_price)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ))}
        </AppLayout>
    );
}
