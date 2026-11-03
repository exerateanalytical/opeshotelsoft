import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { fcfa, num } from './ControlShared';

interface Item { menu_item_id: number; name: string; quantity: number; popularity_pct: number; net_unit_price: number; unit_cost: number; cost_pct: number | null; unit_margin: number; total_margin: number; quadrant: string; cost_known: boolean; suggestions: { code: string }[] }
interface Report { from: string; to: string; summary: { items: number; revenue: number; avg_unit_margin: number; by_quadrant: Record<string, number> }; items: Item[] }

const QUADS = ['star', 'plowhorse', 'puzzle', 'dog'];

export default function MenuEngineering() {
    const t = useT();
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const qs = [from && `from=${from}`, to && `to=${to}`].filter(Boolean).join('&');
    const rep = useApi<Report>(`stock-ops/menu-engineering${qs ? `?${qs}` : ''}`);
    const r = rep.data;

    return (
        <AppLayout title={t('stock3.menu.title')} subtitle={t('stock3.menu.subtitle')}>
            <Head title={t('stock3.menu.title')} />
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <input className="input w-full sm:w-40" type="date" aria-label={t('stock.common.from')} value={from} onChange={(e) => setFrom(e.target.value)} />
                <input className="input w-full sm:w-40" type="date" aria-label={t('stock.common.to')} value={to} onChange={(e) => setTo(e.target.value)} />
                {r && <span className="text-slate-500">{r.from} → {r.to} · {t('stock3.menu.avg_margin')}: {fcfa(r.summary.avg_unit_margin)}</span>}
            </div>
            {r && (
                <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
                    {QUADS.map((q) => <div key={q} className="card p-3 text-sm"><p className="text-xs uppercase text-slate-500">{t(`stock3.menu.q.${q}`)}</p><p className="text-xl font-medium">{r.summary.by_quadrant[q] ?? 0}</p></div>)}
                </div>
            )}
            {r && r.items.length === 0 && <p className="text-sm text-slate-500">{t('stock3.menu.empty')}</p>}
            {r && r.items.length > 0 && (
                <div className="card overflow-x-auto p-3 text-sm">
                    <table className="w-full table-auto">
                        <thead><tr className="text-left text-xs uppercase text-slate-500">{['dish', 'qty', 'pop', 'price', 'cost', 'cost_pct', 'margin', 'quadrant', 'advice'].map((h) => <th key={h} className="px-2 py-1">{t(`stock3.menu.col.${h}`)}</th>)}</tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {r.items.map((i) => (
                                <tr key={i.menu_item_id}>
                                    <td className="px-2 py-1">{i.name}</td><td className="px-2 py-1">{num(i.quantity, 0)}</td><td className="px-2 py-1">{num(i.popularity_pct, 1)} %</td>
                                    <td className="px-2 py-1">{fcfa(i.net_unit_price)}</td><td className="px-2 py-1">{i.cost_known ? fcfa(i.unit_cost) : '-'}</td>
                                    <td className="px-2 py-1">{i.cost_pct === null || !i.cost_known ? '-' : `${num(i.cost_pct, 1)} %`}</td><td className="px-2 py-1">{fcfa(i.unit_margin)}</td>
                                    <td className="px-2 py-1 font-medium">{t(`stock3.menu.q.${i.quadrant}`)}</td>
                                    <td className="px-2 py-1">{i.suggestions.map((s) => t(`stock3.menu.suggest.${s.code}`)).join(' ')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
