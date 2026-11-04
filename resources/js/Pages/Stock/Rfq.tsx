import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { errMsg, fcfa, num, Ref, usePerms } from './ControlShared';
import { useFormErrors, useList } from './StockForms';
import StockTabs from './StockTabs';
import RfqDetail from './RfqDetail';

interface Rfq { id: number; number: string; title: string; status: string; due_date: string | null }
interface Cell { supplier_id: number; unit_price: number | null; line_total: number | null; lead_time_days: number | null; lowest: boolean; awarded: boolean }
interface Row { id: number; item_name: string; unit: string; quantity: number; quotes: Cell[]; awarded_supplier_id: number | null }
interface Grid { rfq: Rfq; suppliers: { id: number; name: string; total: number; complete: boolean }[]; lines: Row[]; cheapest_complete_supplier_id: number | null }
interface DraftLine { stock_item_id: string; quantity: string }

function NewRfq({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: number) => void }) {
    const t = useT();
    const stores = useList<Ref>(open ? 'stock/stores?per_page=200&sort=code' : null);
    const items = useList<Ref>(open ? 'stock/items?per_page=500&sort=name' : null);
    const suppliers = useList<Ref>(open ? 'suppliers?per_page=500&sort=name' : null);
    const [title, setTitle] = useState('');
    const [storeId, setStoreId] = useState('');
    const [due, setDue] = useState('');
    const [notes, setNotes] = useState('');
    const [picked, setPicked] = useState<number[]>([]);
    const [lines, setLines] = useState<DraftLine[]>([{ stock_item_id: '', quantity: '' }]);
    const [send, setSend] = useState(true);
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    const setLine = (i: number, p: Partial<DraftLine>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...p } : l)));

    async function save() {
        const e: Record<string, string> = {};
        if (!title.trim()) e.title = t('stock4.err.required');
        if (!storeId) e.store_id = t('stock4.err.required');
        if (picked.length === 0) e.supplier_ids = t('stock4.rfq.err_suppliers');
        if (lines.some((l) => !l.stock_item_id || !(Number(l.quantity) > 0))) e.lines = t('stock4.rfq.err_lines');
        f.setErrors(e);
        if (Object.keys(e).length) return;
        setBusy(true);
        try {
            const created = await api<{ id: number }>('stock-ops/rfqs', { method: 'POST', body: JSON.stringify({
                title, store_id: Number(storeId), due_date: due || null, notes: notes || null, supplier_ids: picked,
                lines: lines.map((l) => ({ stock_item_id: Number(l.stock_item_id), quantity: Number(l.quantity) })),
            }) });
            if (send) await api(`stock-ops/rfqs/${created.id}/send`, { method: 'POST', body: '{}' });
            toast.success(t(send ? 'stock4.rfq.created_sent' : 'stock4.rfq.created'));
            setTitle(''); setLines([{ stock_item_id: '', quantity: '' }]); setPicked([]); setNotes(''); setDue('');
            onCreated(created.id);
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }

    return (
        <Modal open={open} onClose={onClose} title={t('stock4.rfq.new')} size="lg"
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t(send ? 'stock4.rfq.save_send' : 'stock4.common.save')}</Button></>}>
            <div className="grid gap-3 sm:grid-cols-2">
                {f.general && <p role="alert" className="field-error sm:col-span-2">{f.general}</p>}
                <Field label={t('stock4.rfq.f_title')} required error={f.under('title')} className="sm:col-span-2"><input className="input" maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} data-autofocus /></Field>
                <Field label={t('stock4.rfq.f_store')} required error={f.under('store_id')}>
                    <select className="select" value={storeId} onChange={(e) => setStoreId(e.target.value)}><option value="">{t('stock4.common.choose')}</option>{stores.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}</select>
                </Field>
                <Field label={t('stock4.rfq.f_due')} error={f.under('due_date')}><input className="input" type="date" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
                <fieldset className="sm:col-span-2">
                    <legend className="label">{t('stock4.rfq.f_suppliers')} <span className="text-red-700" aria-hidden="true">*</span></legend>
                    <div className="grid gap-1 sm:grid-cols-2">
                        {suppliers.rows.map((s) => (
                            <label key={s.id} className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5 shrink-0" checked={picked.includes(s.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, s.id] : picked.filter((x) => x !== s.id))} />{s.name}</label>
                        ))}
                    </div>
                    {f.under('supplier_ids') && <p role="alert" className="field-error">{f.under('supplier_ids')}</p>}
                </fieldset>
                <div className="sm:col-span-2">
                    <p className="label">{t('stock4.rfq.f_lines')} <span className="text-red-700" aria-hidden="true">*</span></p>
                    {lines.map((l, i) => (
                        <div key={i} className="mb-2 grid grid-cols-[1fr_6rem_auto] items-center gap-2">
                            <select className="select" aria-label={t('stock4.common.item')} value={l.stock_item_id} onChange={(e) => setLine(i, { stock_item_id: e.target.value })}><option value="">{t('stock4.common.item')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}</select>
                            <input className="input" type="number" min="0" step="any" aria-label={t('stock4.common.qty')} placeholder={t('stock4.common.qty')} value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />
                            <Button variant="ghost" iconOnly icon={Trash2} disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, j) => j !== i))}>{t('stock4.common.remove_line')}</Button>
                        </div>
                    ))}
                    {f.under('lines') && <p role="alert" className="field-error">{f.under('lines')}</p>}
                    <Button variant="secondary" size="sm" icon={Plus} onClick={() => setLines([...lines, { stock_item_id: '', quantity: '' }])}>{t('stock4.common.add_line')}</Button>
                </div>
                <Field label={t('stock4.rfq.f_notes')} className="sm:col-span-2"><textarea className="textarea" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
                <label className="flex min-h-11 items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" className="h-5 w-5 shrink-0" checked={send} onChange={(e) => setSend(e.target.checked)} />{t('stock4.rfq.send_now')}</label>
            </div>
        </Modal>
    );
}

export default function RfqPage() {
    const t = useT();
    const canEdit = usePerms().includes('stockops.rfq');
    const list = useApi<Rfq[]>('stock-ops/rfqs');
    const [sel, setSel] = useState<number | null>(null);
    const grid = useApi<Grid>(sel ? `stock-ops/rfqs/${sel}/grid` : null);
    const [msg, setMsg] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [quote, setQuote] = useState<{ supplier: number; prices: Record<number, string>; lead: Record<number, string> } | null>(null);
    const [quoteBusy, setQuoteBusy] = useState(false);
    const qf = useFormErrors();

    async function act(path: string, body?: unknown, ok?: string) {
        setMsg(null);
        try {
            const r = await api<{ purchase_orders?: unknown[] }>(`stock-ops/rfqs/${sel}/${path}`, { method: 'POST', body: JSON.stringify(body ?? {}) });
            if (path === 'convert') toast.success(t('stock4.rfq.converted', { count: r?.purchase_orders?.length ?? 0 }));
            else if (ok) toast.success(ok);
            grid.reload();
            list.reload();
        } catch (e) { setMsg(errMsg(e)); }
    }
    async function saveQuote() {
        if (!quote) return;
        const lines = Object.entries(quote.prices).filter(([, v]) => v !== '').map(([id, v]) => ({ rfq_line_id: Number(id), unit_price: Math.round(Number(v)), lead_time_days: quote.lead[Number(id)] ? Number(quote.lead[Number(id)]) : null }));
        if (lines.length === 0 || lines.some((l) => !(l.unit_price >= 0))) { qf.setErrors({ lines: t('stock4.rfq.err_quote') }); return; }
        setQuoteBusy(true);
        qf.clear();
        try {
            await api(`stock-ops/rfqs/${sel}/quotes`, { method: 'POST', body: JSON.stringify({ supplier_id: quote.supplier, lines }) });
            toast.success(t('stock4.rfq.quote_saved'));
            setQuote(null);
            grid.reload();
        } catch (e) { qf.fail(e); } finally { setQuoteBusy(false); }
    }
    const g = grid.data;
    const status = g?.rfq.status;
    const supplierName = g?.suppliers.find((s) => s.id === quote?.supplier)?.name ?? '';

    return (
        <AppLayout title={t('stock3.rfq.title')} subtitle={t('stock3.rfq.subtitle')} actions={canEdit ? <Button icon={Plus} onClick={() => setCreating(true)}>{t('stock4.rfq.new')}</Button> : undefined}>
            <Head title={t('stock3.rfq.title')} />
            <QueryStatus query={list} variant="list" />
            <StockTabs current="rfq" />
            {msg && <p role="alert" className="mb-3 text-sm text-red-700">{msg}</p>}
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
                        <Button size="sm" variant="secondary" onClick={() => setDetailId(g.rfq.id)}>{t('gapc.rfq.detail')}</Button>
                        {canEdit && status === 'draft' && <Button size="sm" onClick={() => act('send', {}, t('stock4.rfq.sent'))}>{t('stock3.rfq.send')}</Button>}
                        {canEdit && status === 'awarded' && <Button size="sm" variant="accent" onClick={() => act('convert')}>{t('stock3.rfq.convert')}</Button>}
                        {canEdit && ['draft', 'sent', 'awarded'].includes(status ?? '') && <Button size="sm" variant="secondary" onClick={() => act('cancel', {}, t('stock4.rfq.cancelled'))}>{t('stock3.rfq.cancel')}</Button>}
                    </div>
                    <table className="w-full table-auto">
                        <thead>
                            <tr className="text-left text-xs uppercase text-slate-500">
                                <th className="px-2 py-1">{t('stock3.rfq.item')}</th><th className="px-2 py-1">{t('stock3.rfq.qty')}</th>
                                {g.suppliers.map((s) => <th key={s.id} className="px-2 py-1">{s.name} <OpsPrintButton path={`rfq/${g.rfq.id}?supplier=${s.id}`} label={t('documents4.rfq')} />{canEdit && status === 'sent' && <Button size="sm" variant="secondary" className="ml-2" onClick={() => { qf.clear(); setQuote({ supplier: s.id, prices: {}, lead: {} }); }}>{t('stock3.rfq.quote')}</Button>}</th>)}
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
                                                <Button size="sm" variant={c.awarded ? 'accent' : 'secondary'} className="ml-2" onClick={() => act('award', { awards: [{ rfq_line_id: l.id, supplier_id: c.supplier_id }] }, t('stock4.rfq.award_saved'))}>{c.awarded ? t('stock3.rfq.awarded') : t('stock3.rfq.award')}</Button>
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
                                        {canEdit && s.complete && status === 'sent' && <Button size="sm" variant="secondary" className="ml-2" onClick={() => act('award', { supplier_id: s.id }, t('stock4.rfq.award_saved'))}>{t('stock3.rfq.award_all')}</Button>}
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}
            <RfqDetail rfqId={detailId} onClose={() => setDetailId(null)} grid={g ?? null} />
            <NewRfq open={creating} onClose={() => setCreating(false)} onCreated={(id) => { list.reload(); setSel(id); }} />
            <Modal open={!!(g && quote)} onClose={() => setQuote(null)} title={t('stock3.rfq.quote_for', { name: supplierName })}
                footer={<><Button variant="secondary" onClick={() => setQuote(null)}>{t('stock4.common.cancel')}</Button><Button loading={quoteBusy} onClick={saveQuote}>{t('stock3.rfq.save_quote')}</Button></>}>
                {g && quote && (
                    <div className="grid gap-3">
                        {qf.general && <p role="alert" className="field-error">{qf.general}</p>}
                        {qf.under('lines') && <p role="alert" className="field-error">{qf.under('lines')}</p>}
                        {g.lines.map((l, i) => (
                            <div key={l.id} className="grid grid-cols-2 gap-2">
                                <p className="col-span-2 text-sm font-medium">{l.item_name} <span className="font-normal text-slate-500">({num(l.quantity)} {l.unit})</span></p>
                                <Field label={t('stock4.rfq.unit_price')} error={qf.errors[`lines.${i}.unit_price`]}><input className="input" type="number" min={0} step={1} inputMode="numeric" value={quote.prices[l.id] ?? ''} onChange={(e) => setQuote({ ...quote, prices: { ...quote.prices, [l.id]: e.target.value } })} /></Field>
                                <Field label={t('stock4.rfq.lead_time')} error={qf.errors[`lines.${i}.lead_time_days`]}><input className="input" type="number" min={0} step={1} inputMode="numeric" value={quote.lead[l.id] ?? ''} onChange={(e) => setQuote({ ...quote, lead: { ...quote.lead, [l.id]: e.target.value } })} /></Field>
                            </div>
                        ))}
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}
