import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { fcfa, num, Paged, Ref, today } from './ControlShared';

interface Row { stock_item_id: number; code: string; name: string; category: string; unit: string; quantity: number; wac_unit_cost: number; wac_value: number; fifo_unit_cost: number; fifo_value: number; difference: number }
interface Report { as_of: string; rows: Row[]; totals: { wac_value: number; fifo_value: number; difference: number } }

export default function Valuation() {
    const t = useT();
    const [date, setDate] = useState(today());
    const [store, setStore] = useState('');
    const stores = useApi<Paged<Ref> | Ref[]>('stock/stores?per_page=100');
    const storeList = Array.isArray(stores.data) ? stores.data : stores.data?.data ?? [];
    const report = useApi<Report>(`stock-control/valuation?date=${date}${store ? `&store_id=${store}` : ''}`, [date, store]);

    return (
        <AppLayout title={t('stock2.valuation.title')} subtitle={t('stock2.valuation.subtitle')}>
            <Head title={t('stock2.valuation.title')} />
            <div className="mb-3 flex flex-wrap gap-2">
                <input className="input" aria-label={t('stock.common.date')} type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
                <select className="input" value={store} onChange={(e) => setStore(e.target.value)} aria-label={t('stock2.valuation.all_stores')}>
                    <option value="">{t('stock2.valuation.all_stores')}</option>
                    {storeList.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <OpsPrintButton path={`stock-valuation?as_of=${date}${store ? `&store_id=${store}` : ''}`} label={t('documents4.valuation')} />
            </div>
            {report.error && <p className="mb-3 text-sm text-red-700">{report.error.message}</p>}
            {report.data && (
                <>
                    <div className="mb-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3 [&>*]:min-w-0">
                        {(['wac_value', 'fifo_value', 'difference'] as const).map((k) => (
                            <div key={k} className="card p-3"><div className="text-xs text-slate-500">{t(`stock2.valuation.${k}`)}</div><div className="font-medium">{fcfa(report.data!.totals[k])}</div></div>
                        ))}
                    </div>
                    <div className="card overflow-x-auto p-3">
                        <table className="w-full table-auto text-sm">
                            <thead><tr className="text-left text-xs uppercase text-slate-500">
                                {['item', 'quantity', 'wac_cost', 'wac_value', 'fifo_cost', 'fifo_value'].map((h) => <th key={h} className="px-2 py-2">{t(`stock2.valuation.col.${h}`)}</th>)}</tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {report.data.rows.map((r) => (
                                    <tr key={r.stock_item_id}>
                                        <td className="px-2 py-2">{r.name}</td><td className="px-2 py-2">{num(r.quantity)} {r.unit}</td>
                                        <td className="px-2 py-2">{num(r.wac_unit_cost, 2)}</td><td className="px-2 py-2">{fcfa(r.wac_value)}</td>
                                        <td className="px-2 py-2">{num(r.fifo_unit_cost, 2)}</td><td className="px-2 py-2">{fcfa(r.fifo_value)}</td>
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
