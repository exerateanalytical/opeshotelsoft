import { Plus, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import QueryStatus from '@/Components/ui/QueryStatus';

/* Purchasing and payables extras: requisitions, price lists, returns, wastage, payment runs, AP ageing. */

interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }
interface Ref { id: number; code: string; name: string; unit?: string }
type Notify = (ok: boolean, text: string) => void;
export interface ExtrasProps { items: Ref[]; stores: Ref[]; suppliers: Ref[]; notify: Notify }

interface ReqLine { id: number; stock_item_id: number; quantity: number; supplier_id: number | null; purchase_order_id: number | null }
interface Requisition { id: number; number: string; department: string; store_id: number; status: string; needed_by?: string | null; notes?: string | null; lines: ReqLine[] }
interface Price { id: number; supplier_id: number; stock_item_id: number; unit_price: number; min_quantity: number; valid_from: string; valid_to: string | null }
interface GRN { id: number; number: string; supplier_id: number; date: string; net_total: number; invoiced: boolean; lines: { id: number; stock_item_id: number; quantity: number; returned_quantity?: number; unit_price: number }[] }
interface Return { id: number; number: string; supplier_id: number; date: string; reason?: string | null; total: number; credit_status: string; credit_applied: number; lines: { item: string; quantity: number; value: number }[] }
interface DueInvoice { id: number; supplier_id: number; supplier_name?: string; reference: string; date: string; due_date: string | null; total: number; paid: number; due: number; status: string }
interface PayAccount { id: number; code: string; name: string }
interface Run { id: number; number: string; date: string; account: string; payments_count: number; total: number }
interface AgeRow { supplier_id: number; supplier: string; current: number; d1_30: number; d31_60: number; d61_90: number; d90_plus: number; credits: number; total: number }

const today = () => new Date().toISOString().slice(0, 10);
const monthStart = () => today().slice(0, 8) + '01';
const errMsg = (e: unknown) => {
    if (e instanceof ApiError) {
        const fields = e.problem.errors ? Object.values(e.problem.errors).flat().join(' ') : '';
        return [e.message, fields].filter(Boolean).join(' — ');
    }
    return String(e);
};
const post = (path: string, body: unknown = {}, method = 'POST') => api(path, { method, body: JSON.stringify(body) });
async function run(notify: Notify, fn: () => Promise<unknown>, ok: string, after?: () => void) {
    try { await fn(); notify(true, ok); after?.(); return true; } catch (e) { notify(false, errMsg(e)); return false; }
}
const nameOf = (list: Ref[], id: number | null | undefined) => (id ? list.find((r) => r.id === id)?.name ?? `#${id}` : '—');

const BADGE: Record<string, string> = {
    draft: 'badge-slate', submitted: 'badge-blue', approved: 'badge-green', rejected: 'badge-red', converted: 'badge-green',
    open: 'badge-yellow', applied: 'badge-green', receipt: 'badge-slate', partially_paid: 'badge-blue',
};
function Badge({ s }: { s: string }) {
    const t = useT();
    const key = `stock.status.${s}`;
    const label = t(key);
    return <span className={cn(BADGE[s] ?? 'badge-slate')}>{label === key ? s.replace(/_/g, ' ') : label}</span>;
}
function Field({ label, children }: { label: string; children: ReactNode }) {
    return <label className="label block">{label}<div className="mt-1">{children}</div></label>;
}
function Table({ head, children, empty, query }: { head: string[]; children: ReactNode; empty?: boolean; query?: { data: unknown; error: unknown; loading: boolean; reload: () => void } }) {
    const t = useT();
    return (
        <div className="overflow-x-auto">
            {query && <QueryStatus query={query} variant="table" rows={3} />}
            <table className="table-auto w-full text-sm">
                <thead><tr className="text-left text-xs uppercase text-slate-500">{head.map((h) => <th key={h} className="px-2 py-2">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-100">{children}</tbody>
            </table>
            {empty && !query?.error && <EmptyFor kind="stock" />}
        </div>
    );
}
function Select({ value, onChange, list, placeholder }: { value: string; onChange: (v: string) => void; list: Ref[]; placeholder?: string }) {
    const t = useT();
    return (
        <select className="select w-full" value={value} onChange={(e) => onChange(e.target.value)} aria-label={placeholder ?? t('stock.common.select')}>
            <option value="">{placeholder ?? t('stock.common.select')}</option>
            {list.map((r) => <option key={r.id} value={r.id}>{r.code} — {r.name}</option>)}
        </select>
    );
}

/* ---------- Requisitions ---------- */
type RLine = { stock_item_id: string; quantity: string; supplier_id: string };
const blank: RLine = { stock_item_id: '', quantity: '', supplier_id: '' };

export function Requisitions({ items, stores, suppliers, notify }: ExtrasProps) {
    const t = useT();
    const [status, setStatus] = useState('');
    const list = useApi<Page<Requisition>>(`purchasing/requisitions${status ? `?status=${status}` : ''}`, [status]);
    const [form, setForm] = useState({ department: '', store_id: '', needed_by: '', notes: '' });
    const [lines, setLines] = useState<RLine[]>([{ ...blank }]);
    const [selected, setSelected] = useState<number[]>([]);
    const setLine = (i: number, p: Partial<RLine>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...p } : l)));

    async function create() {
        const ok = await run(notify, () => post('purchasing/requisitions', {
            ...form, store_id: Number(form.store_id), needed_by: form.needed_by || null, notes: form.notes || null,
            lines: lines.filter((l) => l.stock_item_id && l.quantity).map((l) => ({ stock_item_id: Number(l.stock_item_id), quantity: Number(l.quantity), supplier_id: l.supplier_id ? Number(l.supplier_id) : null })),
        }), t('stock.req.saved'), list.reload);
        if (ok) setLines([{ ...blank }]);
    }
    const act = (r: Requisition, a: 'submit' | 'approve' | 'reject') => run(notify, () => post(`purchasing/requisitions/${r.id}/${a}`), t('stock.req.action_done', { number: r.number, action: t(`stock.req.act_${a}`) }), list.reload);
    async function convert() {
        const ok = await run(notify, () => post('purchasing/requisitions/convert', { requisition_ids: selected }), t('stock.req.converted'), list.reload);
        if (ok) setSelected([]);
    }

    return (
        <div className="space-y-4">
            <div className="card space-y-3 p-4">
                <h3 className="font-semibold">{t('stock.req.new')}</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                    <Field label={t('stock.req.department')}><input className="input w-full" value={form.department} onChange={(e) => setForm({ ...form, department: e.target.value })} placeholder={t('stock.req.department_ph')} /></Field>
                    <Field label={t('stock.req.deliver_to')}><Select value={form.store_id} onChange={(v) => setForm({ ...form, store_id: v })} list={stores} /></Field>
                    <Field label={t('stock.req.needed_by')}><input className="input w-full" type="date" value={form.needed_by} onChange={(e) => setForm({ ...form, needed_by: e.target.value })} /></Field>
                    <Field label={t('stock.common.notes')}><input className="input w-full" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                </div>
                {lines.map((l, i) => (
                    <div key={i} className="grid grid-cols-2 items-center gap-2 sm:grid-cols-12 [&>*]:min-w-0">
                        <div className="col-span-2 sm:col-span-5"><Select value={l.stock_item_id} onChange={(v) => setLine(i, { stock_item_id: v })} list={items} placeholder={t('stock.common.item_ph')} /></div>
                        <input className="input sm:col-span-2" type="number" step="any" placeholder={t('stock.common.qty')} aria-label={t('stock.common.qty')} value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />
                        <div className="sm:col-span-4"><Select value={l.supplier_id} onChange={(v) => setLine(i, { supplier_id: v })} list={suppliers} placeholder={t('stock.req.supplier_auto')} /></div>
                        <button className="btn-ghost btn-icon justify-self-end text-red-700 sm:col-span-1" aria-label={t('stock.common.remove_line')} onClick={() => setLines(lines.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></button>
                    </div>
                ))}
                <div className="flex flex-wrap gap-2">
                    <button className="btn-secondary" onClick={() => setLines([...lines, { ...blank }])}><Plus className="mr-1 inline h-4 w-4" />{t('stock.common.add_line')}</button>
                    <button className="btn-primary" onClick={create}>{t('stock.req.save')}</button>
                </div>
            </div>
            <div className="card p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Field label={t('stock.common.status')}>
                        <select className="select" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('stock4.aria.status')}>
                            <option value="">{t('stock.common.all')}</option>{['draft', 'submitted', 'approved', 'rejected', 'converted'].map((s) => <option key={s} value={s}>{t(`stock.status.${s}`)}</option>)}
                        </select>
                    </Field>
                    <button className="btn-primary" disabled={!selected.length} onClick={convert}>{t('stock.req.convert', { count: selected.length || '' })}</button>
                </div>
                <QueryStatus query={list} variant="table" rows={3} />
                <Table head={['', t('stock.common.number'), t('stock.req.department'), t('stock.common.store'), t('stock.common.lines'), t('stock.common.status'), '']} empty={!list.loading && !list.error && !list.data?.data.length}>
                    {(list.data?.data ?? []).map((r) => (
                        <tr key={r.id}>
                            <td className="px-2 py-1">{r.status === 'approved' && <input type="checkbox" className="h-5 w-5 shrink-0" aria-label={t('stock.common.select_n', { name: r.number })} checked={selected.includes(r.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, r.id] : selected.filter((x) => x !== r.id))} />}</td>
                            <td className="px-2 py-1 font-medium">{r.number} <OpsPrintButton path={`requisition/${r.id}`} label={t('documents4.requisition')} /></td><td className="px-2 py-1">{r.department}</td><td className="px-2 py-1">{nameOf(stores, r.store_id)}</td>
                            <td className="px-2 py-1">{r.lines.map((l) => `${nameOf(items, l.stock_item_id)} × ${Number(l.quantity)}`).join(', ')}</td>
                            <td className="px-2 py-1"><Badge s={r.status} /></td>
                            <td className="whitespace-nowrap px-2 py-1">
                                {r.status === 'draft' && <button className="btn-secondary" onClick={() => act(r, 'submit')}>{t('stock.common.submit')}</button>}
                                {r.status === 'submitted' && <span className="flex gap-1"><button className="btn-primary" onClick={() => act(r, 'approve')}>{t('stock.common.approve')}</button><button className="btn-secondary" onClick={() => act(r, 'reject')}>{t('stock.common.reject')}</button></span>}
                            </td>
                        </tr>
                    ))}
                </Table>
                <p className="mt-2 text-xs text-slate-500">{t('stock.req.hint')}</p>
            </div>
        </div>
    );
}

/* ---------- Price lists ---------- */
export function PriceLists({ items, suppliers, notify }: ExtrasProps) {
    const t = useT();
    const [supplierId, setSupplierId] = useState('');
    const list = useApi<Page<Price>>(`purchasing/prices${supplierId ? `?supplier_id=${supplierId}` : ''}`, [supplierId]);
    const empty = { supplier_id: '', stock_item_id: '', unit_price: '', min_quantity: '', valid_from: today(), valid_to: '' };
    const [form, setForm] = useState(empty);
    const [editing, setEditing] = useState<number | null>(null);
    const body = () => ({ supplier_id: Number(form.supplier_id), stock_item_id: Number(form.stock_item_id), unit_price: Number(form.unit_price), min_quantity: Number(form.min_quantity || 0), valid_from: form.valid_from, valid_to: form.valid_to || null });
    async function save() {
        const ok = await run(notify, () => (editing ? post(`purchasing/prices/${editing}`, body(), 'PUT') : post('purchasing/prices', body())), t('stock.price.saved'), list.reload);
        if (ok) { setForm(empty); setEditing(null); }
    }
    const active = (p: Price) => p.valid_from.slice(0, 10) <= today() && (!p.valid_to || p.valid_to.slice(0, 10) >= today());
    return (
        <div className="space-y-4">
            <div className="card space-y-3 p-4">
                <h3 className="font-semibold">{editing ? t('stock.price.edit') : t('stock.price.add')}</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-6 [&>*]:min-w-0">
                    <Field label={t('stock.common.supplier')}><Select value={form.supplier_id} onChange={(v) => setForm({ ...form, supplier_id: v })} list={suppliers} /></Field>
                    <Field label={t('stock.common.item')}><Select value={form.stock_item_id} onChange={(v) => setForm({ ...form, stock_item_id: v })} list={items} /></Field>
                    <Field label={t('stock.price.unit_price')}><input className="input w-full" type="number" value={form.unit_price} onChange={(e) => setForm({ ...form, unit_price: e.target.value })} /></Field>
                    <Field label={t('stock.price.min_qty')}><input className="input w-full" type="number" step="any" value={form.min_quantity} onChange={(e) => setForm({ ...form, min_quantity: e.target.value })} /></Field>
                    <Field label={t('stock.price.valid_from')}><input className="input w-full" type="date" value={form.valid_from} onChange={(e) => setForm({ ...form, valid_from: e.target.value })} /></Field>
                    <Field label={t('stock.price.valid_to')}><input className="input w-full" type="date" value={form.valid_to} onChange={(e) => setForm({ ...form, valid_to: e.target.value })} /></Field>
                </div>
                <div className="flex gap-2">
                    <button className="btn-primary" onClick={save}>{t('stock.common.save')}</button>
                    {editing && <button className="btn-secondary" onClick={() => { setEditing(null); setForm(empty); }}>{t('stock.common.cancel')}</button>}
                </div>
            </div>
            <div className="card p-4">
                <div className="mb-3 max-w-xs"><Field label={t('stock.common.supplier')}><Select value={supplierId} onChange={setSupplierId} list={suppliers} placeholder={t('stock.common.all_suppliers')} /></Field></div>
                <Table head={[t('stock.common.item'), t('stock.common.supplier'), t('stock.common.price'), t('stock.price.min_qty_short'), t('stock.price.valid'), '', '']} query={list} empty={!list.loading && !list.data?.data.length}>
                    {(list.data?.data ?? []).map((p) => (
                        <tr key={p.id}>
                            <td className="px-2 py-1">{nameOf(items, p.stock_item_id)}</td><td className="px-2 py-1">{nameOf(suppliers, p.supplier_id)}</td>
                            <td className="px-2 py-1">{formatCurrency(p.unit_price)}</td><td className="px-2 py-1">{Number(p.min_quantity)}</td>
                            <td className="whitespace-nowrap px-2 py-1">{formatDate(p.valid_from)} – {p.valid_to ? formatDate(p.valid_to) : t('stock.price.open_ended')}</td>
                            <td className="px-2 py-1">{active(p) ? <span className="badge-green">{t('stock.common.active')}</span> : <span className="badge-slate">{t('stock.common.inactive')}</span>}</td>
                            <td className="whitespace-nowrap px-2 py-1">
                                <button className="btn-secondary mr-1" onClick={() => { setEditing(p.id); setForm({ supplier_id: String(p.supplier_id), stock_item_id: String(p.stock_item_id), unit_price: String(p.unit_price), min_quantity: String(p.min_quantity), valid_from: p.valid_from.slice(0, 10), valid_to: p.valid_to?.slice(0, 10) ?? '' }); }}>{t('stock.common.edit')}</button>
                                <button className="btn-ghost btn-icon text-red-700" aria-label={t('stock.price.delete')} onClick={() => run(notify, () => post(`purchasing/prices/${p.id}`, {}, 'DELETE'), t('stock.price.removed'), list.reload)}><Trash2 className="inline h-4 w-4" /></button>
                            </td>
                        </tr>
                    ))}
                </Table>
                <p className="mt-2 text-xs text-slate-500">{t('stock.price.hint')}</p>
            </div>
        </div>
    );
}

/* ---------- Returns to supplier ---------- */
export function Returns({ items, suppliers, notify }: ExtrasProps) {
    const t = useT();
    const grns = useApi<Page<GRN>>('purchasing/receipts');
    const returns = useApi<Page<Return>>('purchasing/returns');
    const [grnId, setGrnId] = useState('');
    const [qty, setQty] = useState<Record<number, string>>({});
    const [reason, setReason] = useState('');
    const [applying, setApplying] = useState<Return | null>(null);
    const [invoiceId, setInvoiceId] = useState('');
    const invoices = useApi<DueInvoice[]>(applying ? `payables/invoices/due?supplier_id=${applying.supplier_id}` : null, [applying?.id]);
    const grn = grns.data?.data.find((g) => g.id === Number(grnId));

    async function submit() {
        if (!grn) return;
        const lines = grn.lines.filter((l) => Number(qty[l.id]) > 0).map((l) => ({ goods_receipt_line_id: l.id, quantity: Number(qty[l.id]) }));
        const ok = await run(notify, () => post(`purchasing/receipts/${grn.id}/returns`, { reason: reason || null, lines }), grn.invoiced ? t('stock.ret.posted_credit') : t('stock.ret.posted_reduced'), () => { returns.reload(); grns.reload(); });
        if (ok) { setQty({}); setReason(''); }
    }
    async function apply() {
        if (!applying) return;
        const ok = await run(notify, () => post(`payables/credit-notes/${applying.id}/apply`, { supplier_invoice_id: Number(invoiceId) }), t('stock.ret.credit_applied'), returns.reload);
        if (ok) { setApplying(null); setInvoiceId(''); }
    }
    return (
        <div className="space-y-4">
            <div className="card space-y-3 p-4">
                <h3 className="font-semibold">{t('stock.ret.title')}</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                    <Field label={t('stock.ret.grn')}>
                        <select className="select w-full" value={grnId} onChange={(e) => { setGrnId(e.target.value); setQty({}); }} aria-label={t('stock4.aria.grn')}>
                            <option value="">{t('stock.common.select')}</option>
                            {(grns.data?.data ?? []).map((g) => <option key={g.id} value={g.id}>{g.number} — {nameOf(suppliers, g.supplier_id)} — {formatDate(g.date)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('stock.common.reason')}><input className="input w-full" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                </div>
                {grn && (
                    <Table head={[t('stock.common.item'), t('stock.ret.received'), t('stock.ret.returned'), t('stock.ret.grn_cost'), t('stock.ret.return_qty')]}>
                        {grn.lines.map((l) => (
                            <tr key={l.id}>
                                <td className="px-2 py-1">{nameOf(items, l.stock_item_id)}</td><td className="px-2 py-1">{Number(l.quantity)}</td>
                                <td className="px-2 py-1">{Number(l.returned_quantity ?? 0)}</td><td className="px-2 py-1">{formatCurrency(l.unit_price)}</td>
                                <td className="px-2 py-1"><input className="input w-24" type="number" step="any" min="0" value={qty[l.id] ?? ''} onChange={(e) => setQty({ ...qty, [l.id]: e.target.value })} /></td>
                            </tr>
                        ))}
                    </Table>
                )}
                <button className="btn-primary" disabled={!grn} onClick={submit}>{t('stock.ret.post')}</button>
            </div>
            <div className="card p-4">
                <h3 className="mb-2 font-semibold">{t('stock.ret.list_title')}</h3>
                <Table head={[t('stock.common.number'), t('stock.common.date'), t('stock.common.supplier'), t('stock.common.lines'), t('stock.ret.credit'), t('stock.ret.applied'), t('stock.common.status'), '']} query={returns} empty={!returns.loading && !returns.data?.data.length}>
                    {(returns.data?.data ?? []).map((r) => (
                        <tr key={r.id}>
                            <td className="px-2 py-1 font-medium">{r.number} <OpsPrintButton path={`supplier-return/${r.id}`} label={t('documents4.supplier_return')} /></td><td className="px-2 py-1">{formatDate(r.date)}</td><td className="px-2 py-1">{nameOf(suppliers, r.supplier_id)}</td>
                            <td className="px-2 py-1">{r.lines.map((l) => `${l.item} × ${l.quantity}`).join(', ')}</td>
                            <td className="px-2 py-1">{formatCurrency(r.total)}</td><td className="px-2 py-1">{formatCurrency(r.credit_applied)}</td>
                            <td className="px-2 py-1"><Badge s={r.credit_status} /></td>
                            <td className="px-2 py-1">{r.credit_status === 'open' && <button className="btn-secondary" onClick={() => setApplying(r)}>{t('stock.ret.apply_to_invoice')}</button>}</td>
                        </tr>
                    ))}
                </Table>
                {applying && (
                    <div className="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
                        <Field label={t('stock.ret.apply_label', { number: applying.number })}>
                            <select className="select" value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} aria-label={t('stock.ret.select_invoice')}>
                                <option value="">{t('stock.ret.select_invoice')}</option>
                                {(invoices.data ?? []).map((i) => <option key={i.id} value={i.id}>{i.reference} — {t('stock.ret.due_amount', { amount: formatCurrency(i.due) })}</option>)}
                            </select>
                        </Field>
                        <button className="btn-primary" disabled={!invoiceId} onClick={apply}>{t('stock.ret.apply')}</button>
                        <button className="btn-secondary" onClick={() => setApplying(null)}>{t('stock4.common.close')}</button>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ---------- Wastage report ---------- */
interface WastageReport { total: number; rows: { reason: string; code: string; name: string; unit: string; quantity: number; value: number }[]; by_reason: { reason: string; value: number }[] }
export function Wastage() {
    const t = useT();
    const [from, setFrom] = useState(monthStart());
    const [to, setTo] = useState(today());
    const r = useApi<WastageReport>(`stock/reports/wastage?from=${from}&to=${to}`, [from, to]);
    return (
        <div className="card space-y-3 p-4">
            <div className="flex flex-wrap gap-3">
                <Field label={t('stock.common.from')}><input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                <Field label={t('stock.common.to')}><input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            </div>
            {r.error && <p className="text-sm text-red-700">{r.error.message}</p>}
            {r.data && (
                <>
                    <div className="flex flex-wrap gap-2">
                        {r.data.by_reason.map((b) => <span key={b.reason} className="badge-slate">{b.reason.replace(/_/g, ' ')}: {formatCurrency(b.value)}</span>)}
                        <span className="badge-red">{t('stock.common.total_amount', { amount: formatCurrency(r.data.total) })}</span>
                    </div>
                    <Table head={[t('stock.common.reason'), t('stock.common.item'), t('stock.common.quantity'), t('stock.common.value')]} empty={!r.data.rows.length}>
                        {r.data.rows.map((x, i) => (
                            <tr key={i}><td className="px-2 py-1">{x.reason.replace(/_/g, ' ')}</td><td className="px-2 py-1">{x.code} — {x.name}</td><td className="px-2 py-1">{x.quantity} {x.unit}</td><td className="px-2 py-1">{formatCurrency(x.value)}</td></tr>
                        ))}
                    </Table>
                </>
            )}
        </div>
    );
}

/* ---------- Payment run ---------- */
export function PaymentRun({ suppliers, notify }: ExtrasProps) {
    const t = useT();
    const [dueBy, setDueBy] = useState(today());
    const [supplierId, setSupplierId] = useState('');
    const qs = new URLSearchParams();
    if (dueBy) qs.set('due_by', dueBy);
    if (supplierId) qs.set('supplier_id', supplierId);
    const due = useApi<DueInvoice[]>(`payables/invoices/due?${qs}`, [dueBy, supplierId]);
    const accounts = useApi<PayAccount[]>('payables/payment-accounts');
    const runs = useApi<Page<Run>>('payables/payment-runs');
    const [selected, setSelected] = useState<number[]>([]);
    const [account, setAccount] = useState('');
    const [reference, setReference] = useState('');
    const rows = due.data ?? [];
    const total = rows.filter((i) => selected.includes(i.id)).reduce((s, i) => s + i.due, 0);
    async function pay() {
        const ok = await run(notify, () => post('payables/payment-runs', { invoice_ids: selected, account, reference: reference || null }), t('stock.pay.paid', { count: selected.length, amount: formatCurrency(total) }), () => { due.reload(); runs.reload(); });
        if (ok) setSelected([]);
    }
    return (
        <div className="space-y-4">
            <div className="card space-y-3 p-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                    <Field label={t('stock.pay.due_by')}><input className="input w-full" type="date" value={dueBy} onChange={(e) => setDueBy(e.target.value)} /></Field>
                    <Field label={t('stock.common.supplier')}><Select value={supplierId} onChange={setSupplierId} list={suppliers} placeholder={t('stock.common.all_suppliers')} /></Field>
                    <Field label={t('stock.pay.pay_from')}>
                        <select className="select w-full" value={account} onChange={(e) => setAccount(e.target.value)} aria-label={t('stock.pay.treasury_ph')}>
                            <option value="">{t('stock.pay.treasury_ph')}</option>
                            {(accounts.data ?? []).map((a) => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('stock.common.reference')}><input className="input w-full" value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
                </div>
                <Table head={['', t('stock.common.supplier'), t('stock.pay.invoice'), t('stock.common.date'), t('stock.pay.due_date'), t('stock.common.total'), t('stock.pay.paid_col'), t('stock.pay.due')]} query={due} empty={!due.loading && !rows.length}>
                    {rows.map((i) => (
                        <tr key={i.id}>
                            <td className="px-2 py-1"><input type="checkbox" className="h-5 w-5 shrink-0" aria-label={t('stock.common.select_n', { name: i.reference })} checked={selected.includes(i.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, i.id] : selected.filter((x) => x !== i.id))} /></td>
                            <td className="px-2 py-1">{i.supplier_name ?? nameOf(suppliers, i.supplier_id)}</td><td className="px-2 py-1 font-medium">{i.reference}</td>
                            <td className="px-2 py-1">{formatDate(i.date)}</td><td className="px-2 py-1">{i.due_date ? formatDate(i.due_date) : '—'}</td>
                            <td className="px-2 py-1">{formatCurrency(i.total)}</td><td className="px-2 py-1">{formatCurrency(i.paid)}</td><td className="px-2 py-1 font-medium">{formatCurrency(i.due)}</td>
                        </tr>
                    ))}
                </Table>
                <div className="flex flex-wrap items-center gap-3">
                    <button className="btn-secondary" disabled={!rows.length} onClick={() => setSelected(selected.length === rows.length ? [] : rows.map((r) => r.id))}>{selected.length === rows.length && rows.length ? t('stock.pay.clear_all') : t('stock.pay.select_all')}</button>
                    <button className="btn-primary" disabled={!selected.length || !account} onClick={pay}>{t('stock.pay.pay_selected', { count: selected.length })}</button>
                    <span className="text-sm text-slate-600">{t('stock.common.total')}: <strong>{formatCurrency(total)}</strong></span>
                </div>
            </div>
            <div className="card p-4">
                <h3 className="mb-2 font-semibold">{t('stock.pay.past_runs')}</h3>
                <Table head={[t('stock.common.number'), t('stock.common.date'), t('stock.pay.account'), t('stock.pay.payments'), t('stock.common.total')]} query={runs} empty={!runs.loading && !runs.data?.data.length}>
                    {(runs.data?.data ?? []).map((r) => (
                        <tr key={r.id}><td className="px-2 py-1 font-medium">{r.number}</td><td className="px-2 py-1">{formatDate(r.date)}</td><td className="px-2 py-1">{r.account}</td><td className="px-2 py-1">{r.payments_count}</td><td className="px-2 py-1">{formatCurrency(r.total)}</td></tr>
                    ))}
                </Table>
            </div>
        </div>
    );
}

/* ---------- AP ageing ---------- */
export function Ageing() {
    const t = useT();
    const [asOf, setAsOf] = useState(today());
    const r = useApi<{ rows: AgeRow[]; totals: Omit<AgeRow, 'supplier_id' | 'supplier'> }>(`payables/ageing?as_of=${asOf}`, [asOf]);
    const cols: [keyof Omit<AgeRow, 'supplier_id' | 'supplier'>, string][] = [['current', t('stock.age.current')], ['d1_30', '1–30'], ['d31_60', '31–60'], ['d61_90', '61–90'], ['d90_plus', '90+'], ['credits', t('stock.age.credits')], ['total', t('stock.common.total')]];
    return (
        <div className="card space-y-3 p-4">
            <div className="max-w-xs"><Field label={t('stock.age.as_of')}><input className="input w-full" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} /></Field></div>
            {r.error && <p className="text-sm text-red-700">{r.error.message}</p>}
            <Table head={[t('stock.common.supplier'), ...cols.map((c) => c[1])]} query={r} empty={!r.loading && !r.data?.rows.length}>
                {(r.data?.rows ?? []).map((x) => (
                    <tr key={x.supplier_id}><td className="px-2 py-1">{x.supplier}</td>{cols.map(([k]) => <td key={k} className="px-2 py-1 text-right">{formatCurrency(x[k])}</td>)}</tr>
                ))}
                {r.data && r.data.rows.length > 0 && (
                    <tr className="font-semibold"><td className="px-2 py-1">{t('stock.common.total')}</td>{cols.map(([k]) => <td key={k} className="px-2 py-1 text-right">{formatCurrency(r.data!.totals[k])}</td>)}</tr>
                )}
            </Table>
        </div>
    );
}
