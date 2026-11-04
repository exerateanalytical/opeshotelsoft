import AppLayout from '@/Layouts/AppLayout';
import StockTabs from './StockTabs';
import { Head } from '@inertiajs/react';
import { AlertTriangle, Loader2, Plus, Trash2, X } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { Ageing, PaymentRun, PriceLists, Requisitions, Returns, Wastage } from './Extras';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import Alert from '@/Components/ui/Alert';

interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }
interface Balance { store_id: number; store: string; stock_item_id: number; code: string; name: string; unit: string; quantity: number; avg_cost: number; value: number; reorder_level: number }
interface Reorder { id: number; code: string; name: string; unit: string; reorder_level: number; reorder_quantity: number; preferred_supplier_id: number | null; on_hand: number }
interface Item { id: number; code: string; name: string; category: string; unit: string; purchase_unit?: string | null; vat_rate?: number; reorder_level?: number; reorder_quantity?: number; preferred_supplier_id?: number | null; avg_cost?: number; active?: boolean }
interface Store { id: number; code: string; name: string; kind?: string; department?: string | null; active?: boolean }
interface Supplier { id: number; code: string; name: string; tax_id?: string | null; contact_name?: string | null; email?: string | null; phone?: string | null; address?: string | null; payment_method?: string | null; momo_number?: string | null; payment_terms_days?: number; active?: boolean }
interface Movement { id: number; date: string; kind: string; stock_item_id: number; store_id: number; quantity: number; value?: number; reference?: string | null; reason?: string | null }
interface POLine { id: number; stock_item_id: number; quantity: number; received_quantity: number; unit_price: number; vat_rate: number }
interface PO { id: number; number: string; status: string; supplier_id: number; store_id: number; order_date: string; expected_date?: string | null; total: number; net_total: number; requested_by?: number; supplier?: { id: number; name: string }; lines: POLine[] }
interface GRN { id: number; number: string; purchase_order_id: number; supplier_id: number; store_id: number; date: string; delivery_note?: string | null; net_total: number; invoiced: boolean; lines: { id: number; stock_item_id: number; quantity: number; unit_price: number }[] }
interface Invoice { id: number; supplier_id: number; reference: string; date: string; due_date?: string | null; net_total: number; tax_total: number; total: number; paid?: number; status: string; match_variance?: number; goods_receipt_ids?: number[] }
interface CountLine { id: number; stock_item_id: number; expected: number; counted: number | null }
interface Count { id: number; store_id: number; date: string; status: string; lines: CountLine[]; lines_count?: number }
interface PayAccount { id: number; code: string; name: string }
type Line = { stock_item_id: string; quantity: string; unit_price?: string };

const TABS = [['balances', 'stock.tab.balances'], ['items', 'stock.tab.items'], ['movements', 'stock.tab.movements'], ['transfer', 'stock.tab.transfer'], ['counts', 'stock.tab.counts'], ['suppliers', 'stock.tab.suppliers'], ['requisitions', 'stock.tab.requisitions'], ['prices', 'stock.tab.prices'], ['orders', 'stock.tab.orders'], ['receipts', 'stock.tab.receipts'], ['returns', 'stock.tab.returns'], ['wastage', 'stock.tab.wastage'], ['invoices', 'stock.tab.invoices'], ['payrun', 'stock.tab.payrun'], ['ageing', 'stock.tab.ageing']] as const;
type Tab = (typeof TABS)[number][0];

const BADGE: Record<string, string> = {
    draft: 'badge-slate', submitted: 'badge-blue', approved: 'badge-green',
    partially_received: 'badge-yellow', received: 'badge-green', cancelled: 'badge-red',
    pending: 'badge-yellow', partially_paid: 'badge-blue', paid: 'badge-green',
    open: 'badge-yellow', posted: 'badge-green',
};
const WASTAGE_REASONS = ['expired', 'spoiled', 'breakage', 'damaged', 'theft', 'kitchen_error', 'guest_complaint', 'other'];
const CATEGORIES = ['food', 'beverage', 'supplies', 'amenities', 'linen', 'cleaning', 'maintenance'];
const STORE_KINDS = ['main', 'kitchen', 'bar', 'housekeeping', 'maintenance', 'minibar'];
const today = () => new Date().toISOString().slice(0, 10);
const errMsg = (e: unknown) => {
    if (e instanceof ApiError) {
        const fields = e.problem.errors ? Object.values(e.problem.errors).flat().join(' ') : '';
        return [e.message, fields].filter(Boolean).join(' — ');
    }
    return String(e);
};

function Badge({ s }: { s: string }) {
    const t = useT();
    const key = `stock.status.${s}`;
    const label = t(key);
    return <span className={BADGE[s] ?? 'badge-slate'}>{label === key ? s.replace(/_/g, ' ') : label}</span>;
}
function Banner({ msg, onClose }: { msg: { ok: boolean; text: string } | null; onClose: () => void }) {
    if (!msg) return null;
    return (
        <Alert variant={msg.ok ? 'success' : 'danger'} className="mb-4" onDismiss={onClose}>
            <span>{msg.text}</span>
        </Alert>
    );
}
function Field({ label, children }: { label: string; children: ReactNode }) {
    return <label className="block text-xs font-medium text-slate-600">{label}<div className="mt-1">{children}</div></label>;
}
function Table({ head, children, empty }: { head: string[]; children: ReactNode; empty?: boolean }) {
    const t = useT();
    return (
        <div className="overflow-x-auto">
            <table className="table-auto w-full text-sm">
                <thead><tr className="text-left text-xs uppercase text-slate-500">{head.map((h) => <th key={h} className="px-2 py-2">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-100">{children}</tbody>
            </table>
            {empty && <p className="py-6 text-center text-sm text-slate-500">{t('stock.common.nothing')}</p>}
        </div>
    );
}
function Loading({ on }: { on: boolean }) {
    return on ? <Loader2 className="h-4 w-4 animate-spin text-slate-500" /> : null;
}

type Notify = (ok: boolean, text: string) => void;
interface Refs { items: Item[]; stores: Store[]; suppliers: Supplier[]; reloadRefs: () => void; notify: Notify }

async function run(notify: Notify, fn: () => Promise<unknown>, ok: string, after?: () => void) {
    try {
        await fn();
        notify(true, ok);
        after?.();
        return true;
    } catch (e) {
        notify(false, errMsg(e));
        return false;
    }
}
const post = (path: string, body: unknown = {}, method = 'POST') => api(path, { method, body: JSON.stringify(body) });

function LinesEditor({ lines, setLines, items, price }: { lines: Line[]; setLines: (l: Line[]) => void; items: Item[]; price?: boolean }) {
    const t = useT();
    const set = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    return (
        <div className="space-y-2">
            {lines.map((l, i) => (
                <div key={i} className="grid grid-cols-2 items-center gap-2 sm:grid-cols-12 [&>*]:min-w-0">
                    <select className={cn('select col-span-2', price ? 'sm:col-span-6' : 'sm:col-span-8')} value={l.stock_item_id} onChange={(e) => set(i, { stock_item_id: e.target.value })} aria-label={t('stock.common.item_ph')}>
                        <option value="">{t('stock.common.item_ph')}</option>
                        {items.map((it) => <option key={it.id} value={it.id}>{it.code} — {it.name} ({it.unit})</option>)}
                    </select>
                    <input className={cn('input', price ? 'sm:col-span-2' : 'sm:col-span-3')} type="number" step="any" placeholder={t('stock.common.qty')} aria-label={t('stock.common.qty')} value={l.quantity} onChange={(e) => set(i, { quantity: e.target.value })} />
                    {price && <input className="input sm:col-span-3" type="number" placeholder={t('stock.po.price_ph')} aria-label={t('stock.po.price_ph')} value={l.unit_price ?? ''} onChange={(e) => set(i, { unit_price: e.target.value })} />}
                    <button className="btn-ghost btn-icon justify-self-end text-red-700 sm:col-span-1" aria-label={t('stock.common.remove_line')} onClick={() => setLines(lines.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></button>
                </div>
            ))}
            <button className="btn-secondary btn-sm" onClick={() => setLines([...lines, { stock_item_id: '', quantity: '', unit_price: '' }])}><Plus className="mr-1 inline h-3 w-3" />{t('stock.common.add_line')}</button>
        </div>
    );
}
const toLines = (lines: Line[], price = false) => lines.filter((l) => l.stock_item_id && l.quantity).map((l) => ({
    stock_item_id: Number(l.stock_item_id), quantity: Number(l.quantity), ...(price && l.unit_price !== '' && l.unit_price !== undefined ? { unit_price: Number(l.unit_price) } : {}),
}));

/* ---------- Balances ---------- */
function Balances({ stores, items }: Refs) {
    const t = useT();
    const [storeId, setStoreId] = useState('');
    const [category, setCategory] = useState('');
    const qs = new URLSearchParams();
    if (storeId) qs.set('store_id', storeId);
    if (category) qs.set('category', category);
    const bal = useApi<Balance[]>(`stock/balances?${qs}`);
    const reorder = useApi<Reorder[]>('stock/reorder');
    const total = (bal.data ?? []).reduce((s, b) => s + Number(b.value), 0);
    void items;
    return (
        <div className="space-y-4">
            {(reorder.data?.length ?? 0) > 0 && (
                <div className="card border-amber-200 bg-amber-50 p-4">
                    <h3 className="mb-2 flex items-center gap-2 font-semibold text-amber-800"><AlertTriangle className="h-4 w-4" />{t('stock.bal.reorder_alerts', { count: reorder.data!.length })}</h3>
                    <Table head={[t('stock.common.item'), t('stock.bal.on_hand'), t('stock.bal.reorder_level'), t('stock.bal.suggested_qty')]}>
                        {reorder.data!.map((r) => (
                            <tr key={r.id}><td className="px-2 py-1">{r.code} — {r.name}</td><td className="px-2 py-1 text-red-700">{Number(r.on_hand)} {r.unit}</td><td className="px-2 py-1">{Number(r.reorder_level)}</td><td className="px-2 py-1">{Number(r.reorder_quantity)}</td></tr>
                        ))}
                    </Table>
                </div>
            )}
            <div className="card p-4">
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <select className="select w-full sm:w-auto sm:max-w-xs" value={storeId} onChange={(e) => setStoreId(e.target.value)} aria-label={t('stock.common.all_stores')}>
                        <option value="">{t('stock.common.all_stores')}</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
                    </select>
                    <select className="select w-full sm:w-auto sm:max-w-xs" value={category} onChange={(e) => setCategory(e.target.value)} aria-label={t('stock.common.all_categories')}>
                        <option value="">{t('stock.common.all_categories')}</option>{CATEGORIES.map((c) => <option key={c} value={c}>{t(`stock.cat.${c}`)}</option>)}
                    </select>
                    <Loading on={bal.loading} />
                    <span className="ml-auto text-sm text-slate-600">{t('stock.bal.total_value')} <b>{formatCurrency(total)}</b></span>
                </div>
                {bal.error && <p className="text-sm text-red-700">{bal.error.message}</p>}
                <Table head={[t('stock.common.store'), t('stock.common.item'), t('stock.common.quantity'), t('stock.common.avg_cost'), t('stock.common.value'), '']} empty={!bal.loading && !bal.data?.length}>
                    {(bal.data ?? []).map((b) => {
                        const low = Number(b.reorder_level) > 0 && Number(b.quantity) <= Number(b.reorder_level);
                        return (
                            <tr key={`${b.store_id}-${b.stock_item_id}`} className={low ? 'bg-amber-50/60' : ''}>
                                <td className="px-2 py-1">{b.store}</td><td className="px-2 py-1">{b.code} — {b.name}</td>
                                <td className="px-2 py-1">{Number(b.quantity)} {b.unit}</td><td className="px-2 py-1">{formatCurrency(Number(b.avg_cost))}</td>
                                <td className="px-2 py-1">{formatCurrency(Number(b.value))}</td>
                                <td className="px-2 py-1">{low && <span className="text-xs text-amber-700">{t('stock.bal.low')}</span>}</td>
                            </tr>
                        );
                    })}
                </Table>
            </div>
        </div>
    );
}

/* ---------- Generic CRUD ---------- */
type FieldDef = { key: string; label: string; type?: 'text' | 'number' | 'select' | 'bool'; options?: [string, string][] };
function Crud<T extends { id: number; active?: boolean }>({ title, plural, path, fields, cols, notify, onChange }: { title: string; plural: string; path: string; fields: FieldDef[]; cols: [string, (r: T) => ReactNode][]; notify: Notify; onChange: () => void }) {
    const t = useT();
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState<Record<string, string> | null>(null);
    const [editId, setEditId] = useState<number | null>(null);
    const list = useApi<Page<T>>(`${path}?per_page=25&page=${page}${q ? `&q=${encodeURIComponent(q)}` : ''}`);
    const blank = () => Object.fromEntries(fields.map((f) => [f.key, f.type === 'bool' ? '1' : f.type === 'select' ? f.options![0][0] : '']));
    function open(r?: T) {
        setEditId(r?.id ?? null);
        setEditing(r ? Object.fromEntries(fields.map((f) => { const v = (r as Record<string, unknown>)[f.key]; return [f.key, f.type === 'bool' ? (v ? '1' : '0') : v == null ? '' : String(v)]; })) : blank());
    }
    async function save() {
        if (!editing) return;
        const body: Record<string, unknown> = {};
        for (const f of fields) {
            const v = editing[f.key];
            if (f.type === 'bool') body[f.key] = v === '1';
            else if (v === '') { if (editId) body[f.key] = null; }
            else body[f.key] = f.type === 'number' ? Number(v) : v;
        }
        await run(notify, () => post(editId ? `${path}/${editId}` : path, body, editId ? 'PUT' : 'POST'), t('stock.crud.saved', { name: title }), () => { setEditing(null); list.reload(); onChange(); });
    }
    async function remove(r: T) {
        if (!confirm(t('stock.crud.confirm_delete', { name: title.toLowerCase() }))) return;
        await run(notify, () => api(`${path}/${r.id}`, { method: 'DELETE' }), t('stock.crud.deleted', { name: title }), () => { list.reload(); onChange(); });
    }
    return (
        <div className="card p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <h3 className="font-semibold">{plural}</h3>
                <input className="input w-full sm:w-48" placeholder={t('stock.common.search')} aria-label={t('stock.common.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                <Loading on={list.loading} />
                <button className="btn-primary ml-auto" onClick={() => open()}><Plus className="mr-1 inline h-4 w-4" />{t('stock.crud.new', { name: title.toLowerCase() })}</button>
            </div>
            {editing && (
                <div className="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
                        {fields.map((f) => (
                            <Field key={f.key} label={f.label}>
                                {f.type === 'select' || f.type === 'bool' ? (
                                    <select className="select" value={editing[f.key]} onChange={(e) => setEditing({ ...editing, [f.key]: e.target.value })}>
                                        {(f.type === 'bool' ? [['1', t('stock.common.yes')], ['0', t('stock.common.no')]] : f.options!).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                ) : (
                                    <input className="input" type={f.type === 'number' ? 'number' : 'text'} step="any" value={editing[f.key]} onChange={(e) => setEditing({ ...editing, [f.key]: e.target.value })} />
                                )}
                            </Field>
                        ))}
                    </div>
                    <div className="mt-3 flex gap-2"><button className="btn-primary" onClick={save}>{editId ? t('stock.common.update') : t('stock.common.create')}</button><button className="btn-secondary" onClick={() => setEditing(null)}>{t('stock.common.cancel')}</button></div>
                </div>
            )}
            {list.error && <p className="text-sm text-red-700">{list.error.message}</p>}
            <Table head={[...cols.map((c) => c[0]), '']} empty={!list.loading && !list.data?.data.length}>
                {(list.data?.data ?? []).map((r) => (
                    <tr key={r.id} className={r.active === false ? 'text-slate-500' : ''}>
                        {cols.map(([h, fn]) => <td key={h} className="px-2 py-1">{fn(r)}</td>)}
                        <td className="whitespace-nowrap px-2 py-1 text-right">
                            <button className="btn-secondary btn-sm" onClick={() => open(r)}>{t('stock.common.edit')}</button>{' '}
                            <button className="btn-ghost btn-sm text-red-700" onClick={() => remove(r)}>{t('stock.common.delete')}</button>
                        </td>
                    </tr>
                ))}
            </Table>
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center gap-2 text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('stock.common.prev')}</button>
                    <span>{list.data.current_page} / {list.data.last_page}</span>
                    <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('stock.common.next')}</button>
                </div>
            )}
        </div>
    );
}

function ItemsStores({ suppliers, notify, reloadRefs }: Refs) {
    const t = useT();
    const supOpts: [string, string][] = [['', '—'], ...suppliers.map((s) => [String(s.id), s.name] as [string, string])];
    return (
        <div className="space-y-4">
            <Crud<Item> title={t('stock.common.item')} plural={t('stock.crud.items')} path="stock/items" notify={notify} onChange={reloadRefs}
                fields={[{ key: 'code', label: t('stock.common.code') }, { key: 'name', label: t('stock.common.name') }, { key: 'category', label: t('stock.common.category'), type: 'select', options: CATEGORIES.map((c) => [c, t(`stock.cat.${c}`)]) },
                    { key: 'unit', label: t('stock.common.unit') }, { key: 'purchase_unit', label: t('stock.item.purchase_unit') }, { key: 'vat_rate', label: t('stock.item.vat'), type: 'number' },
                    { key: 'reorder_level', label: t('stock.bal.reorder_level'), type: 'number' }, { key: 'reorder_quantity', label: t('stock.item.reorder_qty'), type: 'number' },
                    { key: 'preferred_supplier_id', label: t('stock.item.preferred_supplier'), type: 'select', options: supOpts }, { key: 'active', label: t('stock.common.active'), type: 'bool' }]}
                cols={[[t('stock.common.code'), (r) => r.code], [t('stock.common.name'), (r) => r.name], [t('stock.common.category'), (r) => t(`stock.cat.${r.category}`)], [t('stock.common.unit'), (r) => r.unit], [t('stock.common.avg_cost'), (r) => formatCurrency(Number(r.avg_cost ?? 0))], [t('stock.item.reorder'), (r) => Number(r.reorder_level ?? 0)]]} />
            <Crud<Store> title={t('stock.common.store')} plural={t('stock.crud.stores')} path="stock/stores" notify={notify} onChange={reloadRefs}
                fields={[{ key: 'code', label: t('stock.common.code') }, { key: 'name', label: t('stock.common.name') }, { key: 'kind', label: t('stock.common.kind'), type: 'select', options: STORE_KINDS.map((k) => [k, t(`stock.store_kind.${k}`)]) },
                    { key: 'department', label: t('stock.req.department') }, { key: 'active', label: t('stock.common.active'), type: 'bool' }]}
                cols={[[t('stock.common.code'), (r) => r.code], [t('stock.common.name'), (r) => r.name], [t('stock.common.kind'), (r) => (r.kind ? t(`stock.store_kind.${r.kind}`) : '—')], [t('stock.req.department'), (r) => r.department ?? '—']]} />
        </div>
    );
}

function Suppliers({ notify, reloadRefs }: Refs) {
    const t = useT();
    return (
        <Crud<Supplier> title={t('stock.common.supplier')} plural={t('stock.crud.suppliers')} path="suppliers" notify={notify} onChange={reloadRefs}
            fields={[{ key: 'code', label: t('stock.common.code') }, { key: 'name', label: t('stock.common.name') }, { key: 'tax_id', label: t('stock.sup.tax_id') }, { key: 'contact_name', label: t('stock.sup.contact') },
                { key: 'email', label: t('stock.sup.email') }, { key: 'phone', label: t('stock.sup.phone') }, { key: 'address', label: t('stock.sup.address') }, { key: 'payment_method', label: t('stock.sup.payment_method') },
                { key: 'momo_number', label: t('stock.sup.momo') }, { key: 'payment_terms_days', label: t('stock.sup.terms_days'), type: 'number' }, { key: 'active', label: t('stock.common.active'), type: 'bool' }]}
            cols={[['Code', (r) => r.code], ['Name', (r) => r.name], [t('stock.sup.contact'), (r) => r.contact_name ?? '—'], [t('stock.sup.phone'), (r) => r.phone ?? '—'], [t('stock.sup.terms'), (r) => t('stock.sup.days_n', { count: r.payment_terms_days ?? 0 })]]} />
    );
}

/* ---------- Movements ---------- */
function Movements({ items, stores }: Refs) {
    const t = useT();
    const [itemId, setItemId] = useState('');
    const [kind, setKind] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const qs = new URLSearchParams({ page: String(page) });
    if (itemId) qs.set('stock_item_id', itemId);
    if (kind) qs.set('kind', kind);
    if (from) qs.set('from', from);
    if (to) qs.set('to', to);
    const list = useApi<Page<Movement>>(`stock/movements?${qs}`);
    const iName = (id: number) => items.find((i) => i.id === id)?.name ?? `#${id}`;
    const sName = (id: number) => stores.find((s) => s.id === id)?.code ?? `#${id}`;
    return (
        <div className="card p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <select className="select w-full sm:w-auto sm:max-w-xs" value={itemId} onChange={(e) => { setItemId(e.target.value); setPage(1); }} aria-label={t('stock.common.all_items')}>
                    <option value="">{t('stock.common.all_items')}</option>{items.map((i) => <option key={i.id} value={i.id}>{i.code} — {i.name}</option>)}
                </select>
                <select className="select w-full sm:w-auto sm:max-w-xs" value={kind} onChange={(e) => { setKind(e.target.value); setPage(1); }} aria-label={t('stock.mov.all_kinds')}>
                    <option value="">{t('stock.mov.all_kinds')}</option>{['receipt', 'issue', 'wastage', 'transfer_in', 'transfer_out', 'adjustment', 'sale'].map((k) => <option key={k} value={k}>{t(`stock.status.${k}`)}</option>)}
                </select>
                <input type="date" aria-label={t('stock.common.from')} className="input w-full sm:w-auto" value={from} onChange={(e) => setFrom(e.target.value)} />
                <input type="date" aria-label={t('stock.common.to')} className="input w-full sm:w-auto" value={to} onChange={(e) => setTo(e.target.value)} />
                <Loading on={list.loading} />
            </div>
            {list.error && <p className="text-sm text-red-700">{list.error.message}</p>}
            <Table head={[t('stock.common.date'), t('stock.common.kind'), t('stock.common.item'), t('stock.common.store'), t('stock.common.quantity'), t('stock.common.value'), t('stock.common.reference')]} empty={!list.loading && !list.data?.data.length}>
                {(list.data?.data ?? []).map((m) => (
                    <tr key={m.id}>
                        <td className="px-2 py-1">{formatDate(m.date)}</td><td className="px-2 py-1"><Badge s={m.kind} /></td><td className="px-2 py-1">{iName(m.stock_item_id)}</td>
                        <td className="px-2 py-1">{sName(m.store_id)}</td><td className={cn('px-2 py-1', Number(m.quantity) < 0 && 'text-red-700')}>{Number(m.quantity)}</td>
                        <td className="px-2 py-1">{m.value != null ? formatCurrency(Number(m.value)) : '—'}</td><td className="px-2 py-1">{m.reference ?? m.reason ?? ''}</td>
                    </tr>
                ))}
            </Table>
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center gap-2 text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('stock.common.prev')}</button>
                    <span>{list.data.current_page} / {list.data.last_page}</span>
                    <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('stock.common.next')}</button>
                </div>
            )}
        </div>
    );
}

/* ---------- Transfer / Issue ---------- */
function TransferIssue({ items, stores, notify }: Refs) {
    const tr = useT();
    const [t, setT] = useState({ from: '', to: '', reference: '' });
    const [tLines, setTLines] = useState<Line[]>([{ stock_item_id: '', quantity: '' }]);
    const [iss, setIss] = useState({ kind: 'issue', store: '', reference: '', reason: '' });
    const [iLines, setILines] = useState<Line[]>([{ stock_item_id: '', quantity: '' }]);
    const storeSel = (v: string, on: (v: string) => void) => (
        <select className="select" value={v} onChange={(e) => on(e.target.value)} aria-label={tr('stock.common.select')}><option value="">{tr('stock.common.select')}</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}</select>
    );
    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <div className="card space-y-3 p-4">
                <h3 className="font-semibold">{tr('stock.tr.title')}</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                    <Field label={tr('stock.common.from')}>{storeSel(t.from, (v) => setT({ ...t, from: v }))}</Field>
                    <Field label={tr('stock.common.to')}>{storeSel(t.to, (v) => setT({ ...t, to: v }))}</Field>
                    <Field label={tr('stock.common.reference')}><input className="input" value={t.reference} onChange={(e) => setT({ ...t, reference: e.target.value })} /></Field>
                </div>
                <LinesEditor lines={tLines} setLines={setTLines} items={items} />
                <button className="btn-primary" onClick={() => run(notify, () => post('stock/transfers', { from_store_id: Number(t.from), to_store_id: Number(t.to), reference: t.reference || null, lines: toLines(tLines) }), tr('stock.tr.recorded'), () => setTLines([{ stock_item_id: '', quantity: '' }]))}>{tr('stock.tr.transfer')}</button>
            </div>
            <div className="card space-y-3 p-4">
                <h3 className="font-semibold">{tr('stock.iss.title')}</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                    <Field label={tr('stock.common.kind')}><select className="select" value={iss.kind} onChange={(e) => setIss({ ...iss, kind: e.target.value })}><option value="issue">{tr('stock.status.issue')}</option><option value="wastage">{tr('stock.status.wastage')}</option></select></Field>
                    <Field label={tr('stock.common.store')}>{storeSel(iss.store, (v) => setIss({ ...iss, store: v }))}</Field>
                    <Field label={tr('stock.common.reference')}><input className="input" value={iss.reference} onChange={(e) => setIss({ ...iss, reference: e.target.value })} /></Field>
                    <Field label={tr('stock.common.reason')}>{iss.kind === 'wastage'
                        ? <select className="select" value={iss.reason} onChange={(e) => setIss({ ...iss, reason: e.target.value })} aria-label={tr('stock.iss.select_reason')}><option value="">{tr('stock.iss.select_reason')}</option>{WASTAGE_REASONS.map((w) => <option key={w} value={w}>{tr(`stock.waste.${w}`)}</option>)}</select>
                        : <input className="input" value={iss.reason} onChange={(e) => setIss({ ...iss, reason: e.target.value })} />}</Field>
                </div>
                <LinesEditor lines={iLines} setLines={setILines} items={items} />
                <button className="btn-primary" onClick={() => run(notify, () => post('stock/issues', { kind: iss.kind, store_id: Number(iss.store), reference: iss.reference || null, reason: iss.reason || null, lines: toLines(iLines) }), iss.kind === 'wastage' ? tr('stock.iss.wastage_recorded') : tr('stock.iss.issue_recorded'), () => setILines([{ stock_item_id: '', quantity: '' }]))}>{tr('stock.iss.record')}</button>
            </div>
        </div>
    );
}

/* ---------- Counts ---------- */
function Counts({ items, stores, notify }: Refs) {
    const t = useT();
    const [storeId, setStoreId] = useState('');
    const [count, setCount] = useState<Count | null>(null);
    const [counted, setCounted] = useState<Record<number, string>>({});
    const [variances, setVariances] = useState<{ stock_item_id: number; delta: number; value: number | null }[] | null>(null);
    const iName = (id: number) => { const i = items.find((x) => x.id === id); return i ? `${i.code} — ${i.name}` : `#${id}`; };
    const open = useApi<Page<Count>>('stock/counts?status=open');
    const sCode = (id: number) => stores.find((s) => s.id === id)?.code ?? `#${id}`;
    async function resume(id: number) {
        try {
            const c = await api<Count>(`stock/counts/${id}`);
            setCount(c); setCounted({}); setVariances(null); setStoreId(String(c.store_id));
        } catch (e) { notify(false, errMsg(e)); }
    }
    async function start() {
        try {
            const c = await post('stock/counts', { store_id: Number(storeId) }) as Count;
            setCount(c); setCounted({}); setVariances(null); open.reload();
            notify(true, t('stock.cnt.started', { id: c.id, count: c.lines.length }));
        } catch (e) { notify(false, errMsg(e)); }
    }
    async function save() {
        if (!count) return false;
        const lines = Object.entries(counted).filter(([, v]) => v !== '').map(([id, v]) => ({ stock_item_id: Number(id), counted: Number(v) }));
        if (!lines.length) { notify(false, t('stock.cnt.enter_one')); return false; }
        try {
            const c = await post(`stock/counts/${count.id}`, { lines }, 'PUT') as Count;
            setCount(c); notify(true, t('stock.cnt.saved'));
            return true;
        } catch (e) { notify(false, errMsg(e)); return false; }
    }
    async function postIt() {
        if (!count || !(await save())) return;
        try {
            const r = await post(`stock/counts/${count.id}/post`) as { count: Count; variances: { stock_item_id: number; delta: number; value: number | null }[] };
            setCount({ ...count, status: 'posted' }); setVariances(r.variances); open.reload();
            notify(true, t('stock.cnt.posted', { count: r.variances.length }));
        } catch (e) { notify(false, errMsg(e)); }
    }
    return (
        <div className="card space-y-4 p-4">
            <div className="flex flex-wrap items-end gap-2">
                <Field label={t('stock.common.store')}><select className="select" value={storeId} onChange={(e) => setStoreId(e.target.value)}><option value="">{t('stock.common.select')}</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}</select></Field>
                <button className="btn-primary" disabled={!storeId} onClick={start}>{t('stock.cnt.start')}</button>
            </div>
            {!!open.data?.data.length && (
                <div className="space-y-1 text-sm">
                    <b>{t('stock.cnt.open')}</b>
                    <div className="flex flex-wrap gap-2">
                        {open.data.data.map((c) => (
                            <button key={c.id} className={cn('btn-secondary text-xs', count?.id === c.id && 'outline-2 outline-slate-700')} onClick={() => resume(c.id)}>
                                #{c.id} · {sCode(c.store_id)} · {formatDate(c.date)}{c.lines_count != null && ` · ${t('stock.cnt.lines_n', { count: c.lines_count })}`}
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {count && (
                <>
                    <div className="flex items-center gap-2"><h3 className="font-semibold">{t('stock.cnt.header', { id: count.id, date: formatDate(count.date) })}</h3><Badge s={count.status} /></div>
                    <Table head={[t('stock.common.item'), t('stock.cnt.expected'), t('stock.cnt.counted'), t('stock.cnt.variance')]} empty={!count.lines.length}>
                        {count.lines.map((l) => {
                            const v = counted[l.stock_item_id] ?? (l.counted != null ? String(l.counted) : '');
                            const diff = v !== '' ? Number(v) - Number(l.expected) : null;
                            return (
                                <tr key={l.id}>
                                    <td className="px-2 py-1">{iName(l.stock_item_id)}</td><td className="px-2 py-1">{Number(l.expected)}</td>
                                    <td className="px-2 py-1"><input className="input w-28" type="number" step="any" min={0} disabled={count.status !== 'open'} value={v} onChange={(e) => setCounted({ ...counted, [l.stock_item_id]: e.target.value })} /></td>
                                    <td className={cn('px-2 py-1', diff && diff < 0 ? 'text-red-700' : diff ? 'text-emerald-700' : '')}>{diff != null ? diff : '—'}</td>
                                </tr>
                            );
                        })}
                    </Table>
                    {count.status === 'open' && <div className="flex gap-2"><button className="btn-secondary" onClick={save}>{t('stock.cnt.save')}</button><button className="btn-primary" onClick={postIt}>{t('stock.cnt.post')}</button></div>}
                    {variances && (
                        <div className="rounded-lg bg-slate-50 p-3 text-sm">
                            <b>{t('stock.cnt.adjustments')}</b>{variances.length === 0 && ` ${t('stock.cnt.none')}`}
                            <ul>{variances.map((v) => <li key={v.stock_item_id}>{iName(v.stock_item_id)}: {v.delta > 0 ? '+' : ''}{v.delta}{v.value != null && ` (${formatCurrency(Number(v.value))})`}</li>)}</ul>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

/* ---------- Purchase orders ---------- */
function Orders({ items, stores, suppliers, notify }: Refs) {
    const t = useT();
    const [status, setStatus] = useState('');
    const [supplierId, setSupplierId] = useState('');
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ supplier_id: '', store_id: '', expected_date: '', notes: '' });
    const [lines, setLines] = useState<Line[]>([{ stock_item_id: '', quantity: '', unit_price: '' }]);
    const [receiving, setReceiving] = useState<PO | null>(null);
    const [recv, setRecv] = useState<Record<number, string>>({});
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    const [poError, setPoError] = useState<{ id: number; text: string } | null>(null);
    const qs = new URLSearchParams();
    if (status) qs.set('status', status);
    if (supplierId) qs.set('supplier_id', supplierId);
    const list = useApi<Page<PO>>(`purchasing/orders?${qs}`);
    const iName = (id: number) => items.find((i) => i.id === id)?.name ?? `#${id}`;
    const total = toLines(lines, true).reduce((s, l) => s + l.quantity * (l.unit_price ?? 0), 0);

    async function fillPrices() {
        const filled = await Promise.all(lines.map(async (l) => {
            if (!l.stock_item_id) return l;
            const r = await api<{ price: { unit_price: number } | null }>(`purchasing/prices/lookup?supplier_id=${form.supplier_id}&stock_item_id=${l.stock_item_id}&quantity=${l.quantity || 0}`).catch(() => ({ price: null }));
            return r.price ? { ...l, unit_price: String(r.price.unit_price) } : l;
        }));
        setLines(filled);
    }
    async function create() {
        let warn = '';
        const ok = await run(notify, async () => {
            const po = await post('purchasing/orders', { supplier_id: Number(form.supplier_id), store_id: Number(form.store_id), expected_date: form.expected_date || null, notes: form.notes || null, lines: toLines(lines, true) }) as { price_warnings?: { line: number; entered: number; list_price: number; difference_pct: number }[] };
            warn = (po.price_warnings ?? []).map((w) => t('stock.po.warn_line', { line: w.line + 1, entered: formatCurrency(w.entered), list: formatCurrency(w.list_price), pct: `${w.difference_pct > 0 ? '+' : ''}${w.difference_pct}` })).join('; ');
        }, t('stock.po.created'), list.reload);
        if (ok && warn) notify(false, t('stock.po.created_warn', { warn }));
        if (ok) { setCreating(false); setLines([{ stock_item_id: '', quantity: '', unit_price: '' }]); }
    }
    async function act(po: PO, action: 'submit' | 'approve' | 'cancel') {
        if (action === 'cancel' && !confirm(t('stock.po.confirm_cancel', { number: po.number }))) return;
        setBusy(po.id); setPoError(null);
        try {
            await post(`purchasing/orders/${po.id}/${action}`);
            notify(true, t(`stock.po.done_${action}`, { number: po.number }));
            list.reload();
        } catch (e) {
            const code = e instanceof ApiError ? e.problem.type : '';
            const text = errMsg(e);
            setPoError({ id: po.id, text: code.includes('sod') ? t('stock.po.sod', { text }) : text });
            notify(false, text);
        } finally { setBusy(null); }
    }
    function openReceive(po: PO) {
        setReceiving(po); setNote('');
        setRecv(Object.fromEntries(po.lines.map((l) => [l.id, String(Math.max(0, Number(l.quantity) - Number(l.received_quantity)))])));
    }
    async function receive() {
        if (!receiving) return;
        const ls = Object.entries(recv).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ purchase_order_line_id: Number(id), quantity: Number(v) }));
        const ok = await run(notify, () => post(`purchasing/orders/${receiving.id}/receive`, { delivery_note: note || null, lines: ls }), t('stock.po.received', { number: receiving.number }), list.reload);
        if (ok) setReceiving(null);
    }

    return (
        <div className="space-y-4">
            <div className="card p-4">
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <select className="select w-full sm:w-auto sm:max-w-xs" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('stock4.aria.status')}>
                        <option value="">{t('stock.common.all_statuses')}</option>{['draft', 'submitted', 'approved', 'partially_received', 'received', 'cancelled'].map((s) => <option key={s} value={s}>{t(`stock.status.${s}`)}</option>)}
                    </select>
                    <select className="select w-full sm:w-auto sm:max-w-xs" value={supplierId} onChange={(e) => setSupplierId(e.target.value)} aria-label={t('stock.common.all_suppliers')}>
                        <option value="">{t('stock.common.all_suppliers')}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <Loading on={list.loading} />
                    <button className="btn-primary ml-auto" onClick={() => setCreating(!creating)}><Plus className="mr-1 inline h-4 w-4" />{t('stock.po.new')}</button>
                </div>
                {creating && (
                    <div className="mb-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
                            <Field label={t('stock.common.supplier')}><select className="select" value={form.supplier_id} onChange={(e) => setForm({ ...form, supplier_id: e.target.value })}><option value="">{t('stock.common.select')}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                            <Field label={t('stock.req.deliver_to')}><select className="select" value={form.store_id} onChange={(e) => setForm({ ...form, store_id: e.target.value })}><option value="">{t('stock.common.select')}</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}</select></Field>
                            <Field label={t('stock.po.expected_date')}><input type="date" className="input" value={form.expected_date} onChange={(e) => setForm({ ...form, expected_date: e.target.value })} /></Field>
                            <Field label={t('stock.common.notes')}><input className="input" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                        </div>
                        <LinesEditor lines={lines} setLines={setLines} items={items} price />
                        <button className="btn-secondary" disabled={!form.supplier_id} onClick={fillPrices}>{t('stock.po.fill_prices')}</button>
                        <div className="flex items-center gap-2"><button className="btn-primary" onClick={create}>{t('stock.po.create_draft')}</button><button className="btn-secondary" onClick={() => setCreating(false)}>{t('stock.common.cancel')}</button><span className="ml-auto text-sm">{t('stock.po.net')} <b>{formatCurrency(total)}</b></span></div>
                    </div>
                )}
                {list.error && <p className="text-sm text-red-700">{list.error.message}</p>}
                <Table head={[t('stock.common.number'), t('stock.common.supplier'), t('stock.common.date'), t('stock.common.lines'), t('stock.common.total'), t('stock.common.status'), '']} empty={!list.loading && !list.data?.data.length}>
                    {(list.data?.data ?? []).map((po) => (
                        <Fragment key={po.id}>
                            <tr>
                                <td className="px-2 py-1 font-medium">{po.number}</td><td className="px-2 py-1">{po.supplier?.name ?? `#${po.supplier_id}`}</td>
                                <td className="px-2 py-1">{formatDate(po.order_date)}</td>
                                <td className="px-2 py-1 text-xs">{po.lines.map((l) => `${iName(l.stock_item_id)} ${Number(l.received_quantity)}/${Number(l.quantity)}`).join(', ')}</td>
                                <td className="px-2 py-1">{formatCurrency(Number(po.total))}</td><td className="px-2 py-1"><Badge s={po.status} /></td>
                                <td className="whitespace-nowrap px-2 py-1 text-right">
                                    {busy === po.id && <Loader2 className="mr-1 inline h-4 w-4 animate-spin" />}
                                    {po.status === 'draft' && <button className="btn-secondary btn-sm" onClick={() => act(po, 'submit')}>{t('stock.common.submit')}</button>}{' '}
                                    {po.status === 'submitted' && <button className="btn-primary btn-sm" onClick={() => act(po, 'approve')}>{t('stock.common.approve')}</button>}{' '}
                                    {['approved', 'partially_received'].includes(po.status) && <button className="btn-primary btn-sm" onClick={() => openReceive(po)}>{t('stock.po.receive')}</button>}{' '}
                                    {['draft', 'submitted', 'approved'].includes(po.status) && <button className="btn-ghost btn-sm text-red-700" onClick={() => act(po, 'cancel')}>{t('stock.common.cancel')}</button>}
                                </td>
                            </tr>
                            {poError?.id === po.id && (
                                <tr><td colSpan={7} className="bg-red-50 px-2 py-2 text-sm text-red-800"><AlertTriangle className="mr-1 inline h-4 w-4" />{poError.text}</td></tr>
                            )}
                        </Fragment>
                    ))}
                </Table>
            </div>
            {receiving && (
                <div className="card space-y-3 p-4">
                    <h3 className="font-semibold">{t('stock.po.receive_title', { number: receiving.number })}</h3>
                    <Field label={t('stock.po.delivery_note')}><input className="input max-w-xs" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
                    <Table head={[t('stock.common.item'), t('stock.po.ordered'), t('stock.po.already_received'), t('stock.po.receive_now')]}>
                        {receiving.lines.map((l) => (
                            <tr key={l.id}>
                                <td className="px-2 py-1">{iName(l.stock_item_id)}</td><td className="px-2 py-1">{Number(l.quantity)}</td><td className="px-2 py-1">{Number(l.received_quantity)}</td>
                                <td className="px-2 py-1"><input className="input w-28" type="number" step="any" min={0} value={recv[l.id] ?? ''} onChange={(e) => setRecv({ ...recv, [l.id]: e.target.value })} /></td>
                            </tr>
                        ))}
                    </Table>
                    <div className="flex gap-2"><button className="btn-primary" onClick={receive}>{t('stock.po.post_receipt')}</button><button className="btn-secondary" onClick={() => setReceiving(null)}>{t('stock4.common.close')}</button></div>
                </div>
            )}
        </div>
    );
}

/* ---------- Receipts ---------- */
function Receipts({ items, stores, suppliers }: Refs) {
    const t = useT();
    const [page, setPage] = useState(1);
    const list = useApi<Page<GRN>>(`purchasing/receipts?page=${page}`);
    const iName = (id: number) => items.find((i) => i.id === id)?.name ?? `#${id}`;
    return (
        <div className="card p-4">
            <Loading on={list.loading} />
            {list.error && <p className="text-sm text-red-700">{list.error.message}</p>}
            <Table head={[t('stock.common.number'), t('stock.common.date'), t('stock.common.supplier'), t('stock.common.store'), t('stock.po.delivery_note'), t('stock.common.lines'), t('stock.grn.net'), t('stock.grn.invoiced')]} empty={!list.loading && !list.data?.data.length}>
                {(list.data?.data ?? []).map((g) => (
                    <tr key={g.id}>
                        <td className="px-2 py-1 font-medium">{g.number}</td><td className="px-2 py-1">{formatDate(g.date)}</td>
                        <td className="px-2 py-1">{suppliers.find((s) => s.id === g.supplier_id)?.name ?? `#${g.supplier_id}`}</td>
                        <td className="px-2 py-1">{stores.find((s) => s.id === g.store_id)?.code ?? `#${g.store_id}`}</td><td className="px-2 py-1">{g.delivery_note ?? '—'}</td>
                        <td className="px-2 py-1 text-xs">{g.lines.map((l) => `${iName(l.stock_item_id)} × ${Number(l.quantity)}`).join(', ')}</td>
                        <td className="px-2 py-1">{formatCurrency(Number(g.net_total))}</td>
                        <td className="px-2 py-1">{g.invoiced ? <Badge s="paid" /> : <span className="text-xs text-amber-700">{t('stock.grn.not_yet')}</span>} <OpsPrintButton path={`goods-receipt/${g.id}`} label={t('documents4.grn')} /></td>
                    </tr>
                ))}
            </Table>
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center gap-2 text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('stock.common.prev')}</button><span>{list.data.current_page} / {list.data.last_page}</span>
                    <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('stock.common.next')}</button>
                </div>
            )}
        </div>
    );
}

/* ---------- Invoices ---------- */
function Invoices({ suppliers, notify }: Refs) {
    const t = useT();
    const [status, setStatus] = useState('');
    const [supplierId, setSupplierId] = useState('');
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ supplier_id: '', reference: '', date: today(), due_date: '', net_total: '', tax_total: '' });
    const [grnIds, setGrnIds] = useState<number[]>([]);
    const [expenses, setExpenses] = useState<{ account: string; amount: string; description: string }[]>([]);
    const [matchError, setMatchError] = useState<string | null>(null);
    const [paying, setPaying] = useState<Invoice | null>(null);
    const [pay, setPay] = useState({ amount: '', account: '', reference: '' });
    const payAccounts = useApi<PayAccount[]>('payables/payment-accounts');
    const defaultAccount = payAccounts.data?.[0]?.code ?? '';
    const qs = new URLSearchParams();
    if (status) qs.set('status', status);
    if (supplierId) qs.set('supplier_id', supplierId);
    const list = useApi<Page<Invoice>>(`payables/invoices?${qs}`);
    const grns = useApi<Page<GRN>>(creating ? 'purchasing/receipts' : null, [creating]);
    const openGrns = (grns.data?.data ?? []).filter((g) => !g.invoiced && (!form.supplier_id || g.supplier_id === Number(form.supplier_id)));
    const expected = openGrns.filter((g) => grnIds.includes(g.id)).reduce((s, g) => s + Number(g.net_total), 0) + expenses.reduce((s, e) => s + Number(e.amount || 0), 0);
    const sName = (id: number) => suppliers.find((s) => s.id === id)?.name ?? `#${id}`;

    async function create() {
        setMatchError(null);
        try {
            await post('payables/invoices', {
                supplier_id: Number(form.supplier_id), reference: form.reference, date: form.date, due_date: form.due_date || null,
                net_total: Number(form.net_total || 0), tax_total: Number(form.tax_total || 0), goods_receipt_ids: grnIds,
                expense_lines: expenses.filter((e) => e.account && e.amount).map((e) => ({ account: e.account, amount: Number(e.amount), description: e.description || null })),
            });
            notify(true, t('stock.inv.recorded'));
            setCreating(false); setGrnIds([]); setExpenses([]); list.reload();
        } catch (e) {
            const text = errMsg(e);
            if (e instanceof ApiError && (e.problem.type ?? '').includes('match-failed')) setMatchError(text);
            else if (text.toLowerCase().includes('does not match')) setMatchError(text);
            notify(false, text);
        }
    }
    async function doPay() {
        if (!paying) return;
        const ok = await run(notify, () => post(`payables/invoices/${paying.id}/pay`, { amount: Number(pay.amount), account: pay.account, reference: pay.reference || null }), t('stock.inv.paid', { ref: paying.reference }), list.reload);
        if (ok) setPaying(null);
    }

    return (
        <div className="space-y-4">
            <div className="card p-4">
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <select className="select w-full sm:w-auto sm:max-w-xs" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('stock4.aria.status')}>
                        <option value="">{t('stock.common.all_statuses')}</option>{['pending', 'approved', 'partially_paid', 'paid'].map((s) => <option key={s} value={s}>{t(`stock.status.${s}`)}</option>)}
                    </select>
                    <select className="select w-full sm:w-auto sm:max-w-xs" value={supplierId} onChange={(e) => setSupplierId(e.target.value)} aria-label={t('stock.common.all_suppliers')}>
                        <option value="">{t('stock.common.all_suppliers')}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <Loading on={list.loading} />
                    <button className="btn-primary ml-auto" onClick={() => setCreating(!creating)}><Plus className="mr-1 inline h-4 w-4" />{t('stock.inv.record')}</button>
                </div>
                {creating && (
                    <div className="mb-4 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-6 [&>*]:min-w-0">
                            <Field label={t('stock.common.supplier')}><select className="select" value={form.supplier_id} onChange={(e) => { setForm({ ...form, supplier_id: e.target.value }); setGrnIds([]); }}><option value="">{t('stock.common.select')}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                            <Field label={t('stock.inv.supplier_ref')}><input className="input" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} /></Field>
                            <Field label={t('stock.common.date')}><input type="date" className="input" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} /></Field>
                            <Field label={t('stock.pay.due_date')}><input type="date" className="input" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} /></Field>
                            <Field label={t('stock.inv.net_total')}><input type="number" className="input" value={form.net_total} onChange={(e) => setForm({ ...form, net_total: e.target.value })} /></Field>
                            <Field label={t('stock.inv.tax_total')}><input type="number" className="input" value={form.tax_total} onChange={(e) => setForm({ ...form, tax_total: e.target.value })} /></Field>
                        </div>
                        <div>
                            <p className="mb-1 text-xs font-medium text-slate-600">{t('stock.inv.grns_to_match')} <Loading on={grns.loading} /></p>
                            {openGrns.length === 0 && <p className="text-xs text-slate-500">{t('stock.inv.no_grns')}</p>}
                            <div className="flex flex-wrap gap-3">
                                {openGrns.map((g) => (
                                    <label key={g.id} className="flex items-center gap-1 text-sm">
                                        <input type="checkbox" className="h-5 w-5 shrink-0" checked={grnIds.includes(g.id)} onChange={(e) => setGrnIds(e.target.checked ? [...grnIds, g.id] : grnIds.filter((x) => x !== g.id))} />
                                        {g.number} ({formatCurrency(Number(g.net_total))})
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div className="space-y-2">
                            <p className="text-xs font-medium text-slate-600">{t('stock.inv.expense_lines')}</p>
                            {expenses.map((x, i) => (
                                <div key={i} className="grid grid-cols-2 items-center gap-2 sm:grid-cols-12 [&>*]:min-w-0">
                                    <input className="input sm:col-span-3" placeholder={t('stock.pay.account')} aria-label={t('stock.pay.account')} value={x.account} onChange={(e) => setExpenses(expenses.map((y, j) => (j === i ? { ...y, account: e.target.value } : y)))} />
                                    <input className="input sm:col-span-3" type="number" placeholder={t('stock.common.amount')} aria-label={t('stock.common.amount')} value={x.amount} onChange={(e) => setExpenses(expenses.map((y, j) => (j === i ? { ...y, amount: e.target.value } : y)))} />
                                    <input className="input col-span-2 sm:col-span-5" placeholder={t('stock.common.description')} aria-label={t('stock.common.description')} value={x.description} onChange={(e) => setExpenses(expenses.map((y, j) => (j === i ? { ...y, description: e.target.value } : y)))} />
                                    <button className="btn-ghost btn-icon justify-self-end text-red-700 sm:col-span-1" aria-label={t('stock.common.remove_line')} onClick={() => setExpenses(expenses.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></button>
                                </div>
                            ))}
                            <button className="btn-secondary btn-sm" onClick={() => setExpenses([...expenses, { account: '', amount: '', description: '' }])}><Plus className="mr-1 inline h-3 w-3" />{t('stock.inv.add_expense')}</button>
                        </div>
                        <div className="text-sm">
                            {t('stock.inv.match_expected')} <b>{formatCurrency(expected)}</b>, {t('stock.inv.match_net')} <b>{formatCurrency(Number(form.net_total || 0))}</b>
                            {form.net_total !== '' && Number(form.net_total) !== expected && <span className="ml-2 text-amber-700">({t('stock.inv.difference', { amount: formatCurrency(Number(form.net_total) - expected) })})</span>}
                        </div>
                        {matchError && (
                            <Alert variant="danger" title={t('stock.inv.match_failed')}>
                                <p>{matchError}</p>
                            </Alert>
                        )}
                        <div className="flex gap-2"><button className="btn-primary" onClick={create}>{t('stock.inv.record')}</button><button className="btn-secondary" onClick={() => setCreating(false)}>{t('stock.common.cancel')}</button></div>
                    </div>
                )}
                {list.error && <p className="text-sm text-red-700">{list.error.message}</p>}
                <Table head={[t('stock.common.reference'), t('stock.common.supplier'), t('stock.common.date'), t('stock.pay.due'), t('stock.common.total'), t('stock.pay.paid_col'), t('stock.cnt.variance'), t('stock.common.status'), '']} empty={!list.loading && !list.data?.data.length}>
                    {(list.data?.data ?? []).map((inv) => (
                        <tr key={inv.id}>
                            <td className="px-2 py-1 font-medium">{inv.reference}</td><td className="px-2 py-1">{sName(inv.supplier_id)}</td>
                            <td className="px-2 py-1">{formatDate(inv.date)}</td><td className="px-2 py-1">{inv.due_date ? formatDate(inv.due_date) : '—'}</td>
                            <td className="px-2 py-1">{formatCurrency(Number(inv.total))}</td><td className="px-2 py-1">{formatCurrency(Number(inv.paid ?? 0))}</td>
                            <td className="px-2 py-1">{inv.match_variance ? formatCurrency(Number(inv.match_variance)) : '—'}</td>
                            <td className="px-2 py-1"><Badge s={inv.status} /></td>
                            <td className="whitespace-nowrap px-2 py-1 text-right">
                                {inv.status === 'pending' && <button className="btn-primary btn-sm" onClick={() => run(notify, () => post(`payables/invoices/${inv.id}/approve`), t('stock.inv.approved', { ref: inv.reference }), list.reload)}>{t('stock.common.approve')}</button>}
                                {['approved', 'partially_paid'].includes(inv.status) && <button className="btn-primary btn-sm" onClick={() => { setPaying(inv); setPay({ amount: String(Number(inv.total) - Number(inv.paid ?? 0)), account: defaultAccount, reference: '' }); }}>{t('stock.inv.pay')}</button>}
                            </td>
                        </tr>
                    ))}
                </Table>
            </div>
            {paying && (
                <div className="card space-y-3 p-4">
                    <h3 className="font-semibold">{t('stock.inv.pay_title', { ref: paying.reference, amount: formatCurrency(Number(paying.total) - Number(paying.paid ?? 0)) })}</h3>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                        <Field label={t('stock.common.amount')}><input type="number" className="input" value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} /></Field>
                        <Field label={t('stock.inv.pay_from_account')}><select className="select" value={pay.account} onChange={(e) => setPay({ ...pay, account: e.target.value })}><option value="">{payAccounts.loading ? t('stock.common.loading') : t('stock.common.select')}</option>{(payAccounts.data ?? []).map((a) => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}</select></Field>
                        <Field label={t('stock.common.reference')}><input className="input" value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} /></Field>
                    </div>
                    <div className="flex gap-2"><button className="btn-primary" onClick={doPay}>{t('stock.inv.record_payment')}</button><button className="btn-secondary" onClick={() => setPaying(null)}>{t('stock4.common.close')}</button></div>
                </div>
            )}
        </div>
    );
}

export default function StockIndex() {
    const t = useT();
    const [tab, setTab] = useState<Tab>('balances');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const items = useApi<Page<Item>>('stock/items?per_page=500&sort=name');
    const stores = useApi<Page<Store>>('stock/stores?per_page=200&sort=code');
    const suppliers = useApi<Page<Supplier>>('suppliers?per_page=500&sort=name');
    const refs: Refs = {
        items: items.data?.data ?? [], stores: stores.data?.data ?? [], suppliers: suppliers.data?.data ?? [],
        reloadRefs: () => { items.reload(); stores.reload(); suppliers.reload(); },
        notify: (ok, text) => setMsg({ ok, text }),
    };
    const views: Record<Tab, ReactNode> = {
        balances: <Balances {...refs} />, items: <ItemsStores {...refs} />, movements: <Movements {...refs} />, transfer: <TransferIssue {...refs} />,
        counts: <Counts {...refs} />, suppliers: <Suppliers {...refs} />, orders: <Orders {...refs} />, receipts: <Receipts {...refs} />, invoices: <Invoices {...refs} />,
        requisitions: <Requisitions {...refs} />, prices: <PriceLists {...refs} />, returns: <Returns {...refs} />, wastage: <Wastage />, payrun: <PaymentRun {...refs} />, ageing: <Ageing />,
    };
    return (
        <AppLayout title={t('stock.page.title')} subtitle={t('stock.page.subtitle')}>
            <Head title={t('stock.page.title')} />
            <StockTabs current="index" />
            <div className="tabs mb-4">
                {TABS.map(([k, l]) => (
                    <button key={k} onClick={() => { setTab(k); setMsg(null); }} className={cn('tab', tab === k && 'active')} aria-current={tab === k ? 'page' : undefined}>{t(l)}</button>
                ))}
            </div>
            <Banner msg={msg} onClose={() => setMsg(null)} />
            {(items.error || stores.error || suppliers.error) && <Banner msg={{ ok: false, text: (items.error ?? stores.error ?? suppliers.error)!.message }} onClose={() => {}} />}
            {views[tab]}
        </AppLayout>
    );
}
