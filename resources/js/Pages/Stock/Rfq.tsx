import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { errMsg, fcfa, num, usePerms } from './ControlShared';

interface Rfq { id: number; number: string; title: string; status: string; due_date: string | null }
interface Cell { supplier_id: number; unit_price: number | null; line_total: number | null; lead_time_days: number | null; lowest: boolean; awarded: boolean }
interface Row { id: number; item_name: string; unit: string; quantity: number; quotes: Cell[]; awarded_supplier_id: number | null }
interface Grid { rfq: Rfq; suppliers: { id: number; name: string; total: number; complete: boolean }[]; lines: Row[]; cheapest_complete_supplier_id: number | null }

export default function RfqPage() {
    const t = useT();
    const canEdit = usePerms().includes('stockops.rfq');
    const list = useApi<Rfq[]>('stock-ops/rfqs');
    const [sel, setSel] = useState<number | null>(null);
    const grid = useApi<Grid>(sel ? `stock-ops/rfqs/${sel}/grid` : null);
    const [msg, setMsg] = useState<string | null>(null);
    const [quote, setQuote] = useState<{ supplier: number; prices: Record<number, string> } | null>(null);

    async function act(path: string, body?: unknown) {
        setMsg(null);
        try {
            await api(`stock-ops/rfqs/${sel}/${path}`, { method: 'POST', body: JSON.stringify(body ?? {}) });
            grid.reload();
            list.reload();
        } catch (e) { setMsg(errMsg(e)); }
    }
    async function saveQuote() {
        if (!quote) return;
        const lines = Object.entries(quote.prices).filter(([, v]) => v !== '').map(([id, v]) => ({ rfq_line_id: Number(id), unit_price: Number(v) }));
        await act('quotes', { supplier_id: quote.supplier, lines });
        setQuote(null);
    }
    const g = grid.data;
    const status = g?.rfq.status;

    return (
        <AppLayout title={t('stock3.rfq.title')} subtitle={t('stock3.rfq.subtitle')}>
            <Head title={t('stock3.rfq.title')} />
            {msg && <p className="mb-3 text-sm text-red-700">{msg}</p>}
            <div className="mb-4 flex flex-wrap gap-2">
                {(list.data ?? []).map((r) => (
                    <button key={r.id} type="button" className={sel === r.id ? 'btn-primary' : 'btn-secondary'} onClick={() => setSel(r.id)}>{r.number} · {r.title} · {t(`stock3.rfq.status.${r.status}`)}</button>
                ))}
                {list.data && list.data.length === 0 && <p className="text-sm text-slate-500">{t('stock3.rfq.empty')}</p>}
            </div>
            {g && (
                <div className="card overflow-x-auto p-3 text-sm">
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                        <h2 className="font-medium">{g.rfq.number} · {g.rfq.title}</h2>
                        {canEdit && status === 'draft' && <button type="button" className="btn-primary" onClick={() => act('send')}>{t('stock3.rfq.send')}</button>}
                        {canEdit && status === 'awarded' && <button type="button" className="btn-primary" onClick={() => act('convert')}>{t('stock3.rfq.convert')}</button>}
                        {canEdit && ['draft', 'sent', 'awarded'].includes(status ?? '') && <button type="button" className="btn-secondary" onClick={() => act('cancel')}>{t('stock3.rfq.cancel')}</button>}
                    </div>
                    <table className="w-full table-auto">
                        <thead>
                            <tr className="text-left text-xs uppercase text-slate-500">
                                <th className="px-2 py-1">{t('stock3.rfq.item')}</th><th className="px-2 py-1">{t('stock3.rfq.qty')}</th>
                                {g.suppliers.map((s) => <th key={s.id} className="px-2 py-1">{s.name} <OpsPrintButton path={`rfq/${g.rfq.id}?supplier=${s.id}`} label={t('documents4.rfq')} />{canEdit && status === 'sent' && <button type="button" className="ml-2 underline" onClick={() => setQuote({ supplier: s.id, prices: {} })}>{t('stock3.rfq.quote')}</button>}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {g.lines.map((l) => (
                                <tr key={l.id}>
                                    <td className="px-2 py-1">{l.item_name}</td><td className="px-2 py-1">{num(l.quantity)} {l.unit}</td>
                                    {l.quotes.map((c) => (
                                        <td key={c.supplier_id} className={`px-2 py-1 ${c.lowest ? 'font-medium text-emerald-700' : ''}`}>
                                            {c.unit_price === null ? '-' : fcfa(c.unit_price)}
                                            {c.lead_time_days !== null && <span className="ml-1 text-xs text-slate-500">{c.lead_time_days} {t('stock3.rfq.days')}</span>}
                                            {canEdit && c.unit_price !== null && ['sent', 'awarded'].includes(status ?? '') && (
                                                <button type="button" className="ml-2 underline" onClick={() => act('award', { awards: [{ rfq_line_id: l.id, supplier_id: c.supplier_id }] })}>{c.awarded ? t('stock3.rfq.awarded') : t('stock3.rfq.award')}</button>
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                            <tr className="font-medium">
                                <td className="px-2 py-1" colSpan={2}>{t('stock3.rfq.total')}</td>
                                {g.suppliers.map((s) => (
                                    <td key={s.id} className="px-2 py-1">
                                        {s.complete ? fcfa(s.total) : t('stock3.rfq.incomplete')}
                                        {canEdit && s.complete && status === 'sent' && <button type="button" className="ml-2 underline" onClick={() => act('award', { supplier_id: s.id })}>{t('stock3.rfq.award_all')}</button>}
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}
            {g && quote && (
                <div className="card mt-3 p-3 text-sm">
                    <h3 className="mb-2 font-medium">{t('stock3.rfq.quote_for', { name: g.suppliers.find((s) => s.id === quote.supplier)?.name ?? '' })}</h3>
                    {g.lines.map((l) => (
                        <label key={l.id} className="mb-1 flex items-center gap-2">
                            <span className="w-32 sm:w-48">{l.item_name}</span>
                            <input className="input w-32" type="number" min={0} value={quote.prices[l.id] ?? ''} onChange={(e) => setQuote({ ...quote, prices: { ...quote.prices, [l.id]: e.target.value } })} />
                        </label>
                    ))}
                    <button type="button" className="btn-primary mt-2" onClick={saveQuote}>{t('stock3.rfq.save_quote')}</button>
                </div>
            )}
        </AppLayout>
    );
}
