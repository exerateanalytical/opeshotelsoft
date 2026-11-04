import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import EmptyState from '@/Components/ui/EmptyState';
import StockTabs from './StockTabs';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { errMsg, fcfa, num, usePerms } from './ControlShared';

interface St { id: number; number: string; store_id: number; status: string; date: string }
interface SheetLine { stock_item_id: number; code: string; name: string; unit: string; counted: number | null; done: boolean }
interface Sheet { id: number; status: string; phase: string; lines: SheetLine[] }
interface RevLine { stock_item_id: number; name: string; unit: string; expected: number; final: number; variance_qty: number; variance_value: number; recounted: boolean }
interface Review { status: string; variance_value: number; lines: RevLine[] }

/** Mobile-first blind count: the counter sees names and units only, never the expected quantity. */
export default function Stocktake() {
    const t = useT();
    const perms = usePerms();
    const list = useApi<St[]>('stock-ops/stocktakes');
    const [sel, setSel] = useState<St | null>(null);
    const counting = sel && ['counting', 'recount'].includes(sel.status);
    const sheet = useApi<Sheet>(counting ? `stock-ops/stocktakes/${sel!.id}/sheet` : null);
    const review = useApi<Review>(sel && perms.includes('stocktake.review') && !counting ? `stock-ops/stocktakes/${sel.id}/review` : null);
    const stores = useApi<{ id: number; name: string }[] | { data: { id: number; name: string }[] }>('stock/stores');
    const [store, setStore] = useState('');
    const [vals, setVals] = useState<Record<number, string>>({});
    const [msg, setMsg] = useState<string | null>(null);

    const run = async (fn: () => Promise<unknown>) => { setMsg(null); try { await fn(); list.reload(); sheet.reload(); review.reload(); } catch (e) { setMsg(errMsg(e)); } };
    const pick = (s: St) => { setSel(s); setVals({}); };
    const post = (path: string, body?: unknown) => api(`stock-ops/stocktakes/${sel!.id}/${path}`, { method: 'POST', body: JSON.stringify(body ?? {}) });
    const refreshSel = async () => { const all = await api<St[]>('stock-ops/stocktakes'); setSel(all.find((s) => s.id === sel!.id) ?? null); };

    return (
        <AppLayout title={t('stock3.take.title')} subtitle={t('stock3.take.subtitle')}>
            <Head title={t('stock3.take.title')} />
            <QueryStatus query={list} variant="table" />
            <StockTabs current="stocktake" />
            {msg && <p className="mb-3 text-sm text-red-700">{msg}</p>}
            <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <select className="input w-full sm:w-48" value={store} onChange={(e) => setStore(e.target.value)} aria-label={t('stock3.take.store')}>
                    <option value="">{t('stock3.take.store')}</option>
                    {(Array.isArray(stores.data) ? stores.data : stores.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <button type="button" className="btn-primary" disabled={!store} onClick={() => run(async () => { await api('stock-ops/stocktakes', { method: 'POST', body: JSON.stringify({ store_id: Number(store) }) }); })}>{t('stock3.take.start')}</button>
                {(list.data ?? []).map((s) => <button key={s.id} type="button" className={sel?.id === s.id ? 'btn-primary' : 'btn-secondary'} onClick={() => pick(s)}>{s.number} · {t(`stock3.take.status.${s.status}`)}</button>)}
            </div>
            {list.data?.length === 0 && <div className="card mb-4"><EmptyState title={t('states3.empty.stocktakes.title')} description={t('states3.empty.stocktakes.text')} /></div>}
            {sel && (
                <div className="mb-3 flex flex-wrap gap-2">
                    <OpsPrintButton path={`stocktake-sheet/${sel.id}?blind=1`} label={t('documents4.blind_sheet')} />
                    {perms.includes('stocktake.review') && <OpsPrintButton path={`stocktake-sheet/${sel.id}`} label={t('documents4.count_sheet')} />}
                    {perms.includes('stocktake.review') && <OpsPrintButton path={`stocktake-variance/${sel.id}`} label={t('documents4.variance')} />}
                </div>
            )}
            {sel && counting && sheet.data && (
                <div className="card max-w-xl p-3 text-sm">
                    <h2 className="mb-2 font-medium">{t(`stock3.take.phase.${sheet.data.phase}`)}</h2>
                    {sheet.data.lines.map((l) => (
                        <label key={l.stock_item_id} className="mb-2 flex items-center gap-2">
                            <span className="flex-1">{l.name} <span className="text-xs text-slate-500">({l.unit})</span></span>
                            <input className="input w-28" inputMode="decimal" value={vals[l.stock_item_id] ?? (l.counted ?? '')} onChange={(e) => setVals({ ...vals, [l.stock_item_id]: e.target.value })} />
                        </label>
                    ))}
                    <div className="mt-3 flex gap-2">
                        <button type="button" className="btn-primary" onClick={() => run(async () => {
                            const counts = Object.entries(vals).filter(([, v]) => v !== '').map(([id, v]) => ({ stock_item_id: Number(id), quantity: Number(v.replace(',', '.')) }));
                            if (counts.length) await post('counts', { counts });
                            setVals({});
                            await refreshSel();
                        })}>{t('stock3.take.save')}</button>
                        {sel.status === 'counting' && <button type="button" className="btn-secondary" onClick={() => run(async () => { await post('close-count'); await refreshSel(); })}>{t('stock3.take.close')}</button>}
                    </div>
                </div>
            )}
            {sel && !counting && review.data && (
                <div className="card overflow-x-auto p-3 text-sm">
                    <p className="mb-2">{t('stock3.take.variance_total')}: <b>{fcfa(review.data.variance_value)}</b></p>
                    <table className="w-full table-auto">
                        <thead><tr className="text-left text-xs uppercase text-slate-500">{['item', 'expected', 'counted', 'variance', 'value'].map((h) => <th key={h} className="px-2 py-1">{t(`stock3.take.col.${h}`)}</th>)}</tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {review.data.lines.map((l) => (
                                <tr key={l.stock_item_id}><td className="px-2 py-1">{l.name}{l.recounted && ' *'}</td><td className="px-2 py-1">{num(l.expected)}</td><td className="px-2 py-1">{num(l.final)}</td>
                                    <td className="px-2 py-1">{num(l.variance_qty)}</td><td className="px-2 py-1">{fcfa(l.variance_value)}</td></tr>
                            ))}
                        </tbody>
                    </table>
                    {sel.status === 'review' && perms.includes('stocktake.approve') && <button type="button" className="btn-primary mt-3" onClick={() => run(async () => { await post('approve'); await refreshSel(); })}>{t('stock3.take.approve')}</button>}
                </div>
            )}
        </AppLayout>
    );
}
