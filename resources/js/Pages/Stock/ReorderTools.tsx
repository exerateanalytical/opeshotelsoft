import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Button from '@/Components/ui/Button';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import Tabs from '@/Components/ui/Tabs';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, num, Ref } from './ControlShared';
import { useFormErrors, useList } from './StockForms';

interface Par { id: number; store_id: number; stock_item_id: number; par_level: number; min_level: number | null; supplier_id: number | null; lead_time_days: number | null }
interface LeadTime { id: number; code: string; name: string; lead_time_days: number }
interface Control { tracks_lots: boolean; shelf_life_days: number | null; expiry_alert_days: number }
interface Unit { unit: string; factor: number; role: string }

function ParLevels({ canManage }: { canManage: boolean }) {
    const t = useT();
    const [storeId, setStoreId] = useState('');
    const stores = useList<Ref>('stock/stores?per_page=200&sort=code');
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const suppliers = useList<Ref>('suppliers?per_page=500&sort=name');
    const pars = useList<Par>(`stock-control/par-levels${storeId ? `?store_id=${storeId}` : ''}`);
    const [edit, setEdit] = useState<{ id: number | null; store_id: string; stock_item_id: string; par_level: string; min_level: string; supplier_id: string; lead_time_days: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    const iName = (id: number) => items.rows.find((i) => i.id === id)?.name ?? `#${id}`;
    const sName = (id: number) => stores.rows.find((s) => s.id === id)?.name ?? `#${id}`;

    const blank = () => ({ id: null, store_id: storeId, stock_item_id: '', par_level: '', min_level: '', supplier_id: '', lead_time_days: '' });
    function open(p?: Par) {
        f.clear();
        setEdit(p ? { id: p.id, store_id: String(p.store_id), stock_item_id: String(p.stock_item_id), par_level: String(p.par_level), min_level: p.min_level == null ? '' : String(p.min_level), supplier_id: p.supplier_id ? String(p.supplier_id) : '', lead_time_days: p.lead_time_days == null ? '' : String(p.lead_time_days) } : blank());
    }
    async function save() {
        if (!edit) return;
        setBusy(true);
        f.clear();
        try {
            await api('stock-control/par-levels', { method: 'PUT', body: JSON.stringify({
                store_id: Number(edit.store_id), stock_item_id: Number(edit.stock_item_id), par_level: Number(edit.par_level),
                ...(edit.min_level !== '' ? { min_level: Number(edit.min_level) } : {}),
                supplier_id: edit.supplier_id ? Number(edit.supplier_id) : null, lead_time_days: edit.lead_time_days !== '' ? Number(edit.lead_time_days) : null,
            }) });
            toast.success(t('gapc.par.saved'));
            setEdit(null);
            pars.reload();
        } catch (e) { f.fail(e); } finally { setBusy(false); }
    }
    async function remove(p: Par) {
        if (!window.confirm(t('gapc.par.confirm_delete', { name: iName(p.stock_item_id) }))) return;
        try { await api(`stock-control/par-levels/${p.id}`, { method: 'DELETE' }); toast.success(t('gapc.par.deleted')); pars.reload(); } catch (e) { toast.error(errMsg(e)); }
    }

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-end gap-2">
                <Field label={t('gapc.par.store')} className="w-full sm:w-64">
                    <select className="select" value={storeId} onChange={(e) => setStoreId(e.target.value)}>
                        <option value="">{t('gapc.all_stores')}</option>
                        {stores.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                    </select>
                </Field>
                {canManage && <Button icon={Plus} className="sm:ml-auto" onClick={() => open()}>{t('gapc.par.add')}</Button>}
            </div>
            <DataTable<Par> caption={t('gapc.par.caption')} rows={pars.rows} rowKey={(p) => p.id} loading={pars.loading}
                empty={<EmptyState title={t('gapc.par.empty')} description={t('gapc.par.empty_hint')} />}
                columns={[
                    { key: 'item', header: t('gapc.col.item'), cell: (p) => iName(p.stock_item_id), hideLabel: true },
                    { key: 'store', header: t('gapc.par.store'), cell: (p) => sName(p.store_id) },
                    { key: 'par', header: t('gapc.par.par'), align: 'right', cell: (p) => num(p.par_level) },
                    { key: 'min', header: t('gapc.par.min'), align: 'right', cell: (p) => (p.min_level == null ? '-' : num(p.min_level)) },
                    { key: 'sup', header: t('gapc.par.supplier'), cell: (p) => suppliers.rows.find((s) => s.id === p.supplier_id)?.name ?? '-' },
                    { key: 'lead', header: t('gapc.par.lead'), align: 'right', cell: (p) => (p.lead_time_days == null ? '-' : p.lead_time_days) },
                    ...(canManage ? [{ key: 'act', header: t('gapc.col.actions'), required: true, cell: (p: Par) => (
                        <span className="btn-row">
                            <Button size="sm" variant="secondary" icon={Pencil} onClick={() => open(p)}>{t('gapc.edit')}</Button>
                            <Button size="sm" variant="danger" icon={Trash2} onClick={() => remove(p)}>{t('gapc.delete')}</Button>
                        </span>
                    ) }] : []),
                ]} />
            <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? t('gapc.par.edit') : t('gapc.par.add')} formId="par-form"
                footer={<><Button variant="secondary" onClick={() => setEdit(null)}>{t('gapc.cancel')}</Button><Button type="submit" loading={busy}>{t('gapc.save')}</Button></>}>
                {edit && (
                    <form id="par-form" className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save(); }}>
                        {f.general && <p role="alert" className="field-error sm:col-span-2">{f.general}</p>}
                        <Field label={t('gapc.par.store')} required error={f.under('store_id')}>
                            <select className="select" required value={edit.store_id} onChange={(e) => setEdit({ ...edit, store_id: e.target.value })}>
                                <option value="">{t('gapc.choose')}</option>{stores.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                            </select>
                        </Field>
                        <Field label={t('gapc.col.item')} required error={f.under('stock_item_id')}>
                            <select className="select" required value={edit.stock_item_id} onChange={(e) => setEdit({ ...edit, stock_item_id: e.target.value })}>
                                <option value="">{t('gapc.choose')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                            </select>
                        </Field>
                        <Field label={t('gapc.par.par')} required error={f.under('par_level')}><input className="input" type="number" min="0" step="any" required value={edit.par_level} onChange={(e) => setEdit({ ...edit, par_level: e.target.value })} /></Field>
                        <Field label={t('gapc.par.min')} error={f.under('min_level')}><input className="input" type="number" min="0" step="any" value={edit.min_level} onChange={(e) => setEdit({ ...edit, min_level: e.target.value })} /></Field>
                        <Field label={t('gapc.par.supplier')} error={f.under('supplier_id')}>
                            <select className="select" value={edit.supplier_id} onChange={(e) => setEdit({ ...edit, supplier_id: e.target.value })}>
                                <option value="">-</option>{suppliers.rows.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </Field>
                        <Field label={t('gapc.par.lead')} error={f.under('lead_time_days')}><input className="input" type="number" min="0" max="365" step="1" value={edit.lead_time_days} onChange={(e) => setEdit({ ...edit, lead_time_days: e.target.value })} /></Field>
                    </form>
                )}
            </Modal>
        </div>
    );
}

function LeadTimes({ canManage }: { canManage: boolean }) {
    const t = useT();
    const list = useApi<LeadTime[]>('stock-control/lead-times');
    const [draft, setDraft] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState<number | null>(null);
    async function save(s: LeadTime) {
        setBusy(s.id);
        try {
            await api(`stock-control/lead-times/${s.id}`, { method: 'PUT', body: JSON.stringify({ lead_time_days: Number(draft[s.id]) }) });
            toast.success(t('gapc.lead.saved', { name: s.name }));
            setDraft((d) => { const n = { ...d }; delete n[s.id]; return n; });
            list.reload();
        } catch (e) { toast.error(errMsg(e)); } finally { setBusy(null); }
    }
    return (
        <DataTable<LeadTime> caption={t('gapc.lead.caption')} rows={list.data ?? []} rowKey={(s) => s.id} loading={list.loading}
            empty={<EmptyState title={t('gapc.lead.empty')} />}
            columns={[
                { key: 'code', header: t('gapc.col.code'), cell: (s) => s.code },
                { key: 'name', header: t('gapc.col.name'), cell: (s) => s.name, hideLabel: true },
                { key: 'days', header: t('gapc.lead.days'), align: 'right', cell: (s) => (canManage ? (
                    <input className="input w-24 text-right" type="number" min="0" max="365" step="1" aria-label={t('gapc.lead.days_for', { name: s.name })}
                        value={draft[s.id] ?? String(s.lead_time_days)} onChange={(e) => setDraft({ ...draft, [s.id]: e.target.value })} />
                ) : s.lead_time_days) },
                ...(canManage ? [{ key: 'act', header: t('gapc.col.actions'), required: true, cell: (s: LeadTime) => (
                    <Button size="sm" disabled={draft[s.id] === undefined || draft[s.id] === ''} loading={busy === s.id} onClick={() => save(s)}>{t('gapc.save')}</Button>
                ) }] : []),
            ]} />
    );
}

function ItemControlPanel({ canManage }: { canManage: boolean }) {
    const t = useT();
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const [itemId, setItemId] = useState('');
    const ctl = useApi<Control>(itemId ? `stock-control/item-controls/${itemId}` : null, [itemId]);
    const [form, setForm] = useState<{ tracks_lots: boolean; shelf_life_days: string; expiry_alert_days: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    const cur = form ?? (ctl.data ? { tracks_lots: !!ctl.data.tracks_lots, shelf_life_days: ctl.data.shelf_life_days == null ? '' : String(ctl.data.shelf_life_days), expiry_alert_days: String(ctl.data.expiry_alert_days ?? 0) } : null);
    async function save() {
        if (!cur) return;
        setBusy(true);
        f.clear();
        try {
            await api(`stock-control/item-controls/${itemId}`, { method: 'PUT', body: JSON.stringify({
                tracks_lots: cur.tracks_lots, shelf_life_days: cur.shelf_life_days === '' ? null : Number(cur.shelf_life_days), expiry_alert_days: Number(cur.expiry_alert_days || 0),
            }) });
            toast.success(t('gapc.ctl.saved'));
            setForm(null);
            ctl.reload();
        } catch (e) { f.fail(e); } finally { setBusy(false); }
    }
    return (
        <div className="max-w-xl">
            <Field label={t('gapc.col.item')}>
                <select className="select" value={itemId} onChange={(e) => { setItemId(e.target.value); setForm(null); }}>
                    <option value="">{t('gapc.choose')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                </select>
            </Field>
            {!itemId && <EmptyState title={t('gapc.ctl.pick')} />}
            {itemId && cur && (
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    {f.general && <p role="alert" className="field-error sm:col-span-2">{f.general}</p>}
                    <label className="flex min-h-11 items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" className="h-5 w-5 shrink-0" disabled={!canManage} checked={cur.tracks_lots} onChange={(e) => setForm({ ...cur, tracks_lots: e.target.checked })} />{t('gapc.ctl.tracks_lots')}
                    </label>
                    <Field label={t('gapc.ctl.shelf_life')} hint={t('gapc.ctl.shelf_life_hint')} error={f.under('shelf_life_days')}>
                        <input className="input" type="number" min="1" max="3650" disabled={!canManage} value={cur.shelf_life_days} onChange={(e) => setForm({ ...cur, shelf_life_days: e.target.value })} />
                    </Field>
                    <Field label={t('gapc.ctl.alert_days')} error={f.under('expiry_alert_days')}>
                        <input className="input" type="number" min="0" max="365" disabled={!canManage} value={cur.expiry_alert_days} onChange={(e) => setForm({ ...cur, expiry_alert_days: e.target.value })} />
                    </Field>
                    {canManage && <div className="sm:col-span-2"><Button loading={busy} onClick={save}>{t('gapc.save')}</Button></div>}
                </div>
            )}
        </div>
    );
}

function Units({ canManage }: { canManage: boolean }) {
    const t = useT();
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const [itemId, setItemId] = useState('');
    const units = useApi<Unit[]>(itemId ? `stock-control/units/${itemId}` : null, [itemId]);
    const [def, setDef] = useState({ unit: '', factor: '', role: 'recipe' });
    const [conv, setConv] = useState({ qty: '1', from: '', to: '' });
    const [result, setResult] = useState<string | null>(null);
    const f = useFormErrors();
    const list = units.data ?? [];

    async function define() {
        f.clear();
        try {
            await api(`stock-control/units/${itemId}`, { method: 'POST', body: JSON.stringify({ unit: def.unit, factor: Number(def.factor), role: def.role }) });
            toast.success(t('gapc.uom.saved'));
            setDef({ unit: '', factor: '', role: 'recipe' });
            units.reload();
        } catch (e) { f.fail(e); }
    }
    async function convert() {
        setResult(null);
        f.clear();
        try {
            const r = await api<{ result: number }>(`stock-control/units/${itemId}/convert?qty=${encodeURIComponent(conv.qty)}&from=${encodeURIComponent(conv.from)}&to=${encodeURIComponent(conv.to)}`);
            setResult(`${num(Number(conv.qty), 6)} ${conv.from} = ${num(r.result, 6)} ${conv.to}`);
        } catch (e) { f.fail(e); }
    }
    return (
        <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <div>
                <Field label={t('gapc.col.item')}>
                    <select className="select" value={itemId} onChange={(e) => { setItemId(e.target.value); setResult(null); setConv({ qty: '1', from: '', to: '' }); }}>
                        <option value="">{t('gapc.choose')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                    </select>
                </Field>
                {!itemId && <EmptyState title={t('gapc.uom.pick')} />}
                {itemId && (
                    <div className="mt-3">
                        <DataTable<Unit> caption={t('gapc.uom.caption')} rows={list} rowKey={(u) => u.unit} loading={units.loading}
                            columns={[
                                { key: 'unit', header: t('gapc.uom.unit'), cell: (u) => u.unit, hideLabel: true },
                                { key: 'factor', header: t('gapc.uom.factor'), align: 'right', cell: (u) => num(u.factor, 6) },
                                { key: 'role', header: t('gapc.uom.role'), cell: (u) => t(`gapc.uom.role.${u.role}`) },
                            ]} />
                    </div>
                )}
            </div>
            {itemId && (
                <div className="space-y-4">
                    {f.general && <p role="alert" className="field-error">{f.general}</p>}
                    {canManage && (
                        <form className="card grid grid-cols-3 gap-2 p-3" onSubmit={(e) => { e.preventDefault(); define(); }}>
                            <h3 className="col-span-3 text-base font-semibold">{t('gapc.uom.define')}</h3>
                            <Field label={t('gapc.uom.unit')} error={f.under('unit')}><input className="input" maxLength={10} required value={def.unit} onChange={(e) => setDef({ ...def, unit: e.target.value })} /></Field>
                            <Field label={t('gapc.uom.factor')} hint={t('gapc.uom.factor_hint')} error={f.under('factor')}><input className="input" type="number" min="0" step="any" required value={def.factor} onChange={(e) => setDef({ ...def, factor: e.target.value })} /></Field>
                            <Field label={t('gapc.uom.role')}>
                                <select className="select" value={def.role} onChange={(e) => setDef({ ...def, role: e.target.value })}>
                                    {['recipe', 'purchase', 'other'].map((r) => <option key={r} value={r}>{t(`gapc.uom.role.${r}`)}</option>)}
                                </select>
                            </Field>
                            <div className="col-span-3"><Button type="submit">{t('gapc.save')}</Button></div>
                        </form>
                    )}
                    <form className="card grid grid-cols-3 gap-2 p-3" onSubmit={(e) => { e.preventDefault(); convert(); }}>
                        <h3 className="col-span-3 text-base font-semibold">{t('gapc.uom.convert')}</h3>
                        <Field label={t('gapc.uom.qty')}><input className="input" type="number" step="any" required value={conv.qty} onChange={(e) => setConv({ ...conv, qty: e.target.value })} /></Field>
                        <Field label={t('gapc.uom.from')}>
                            <select className="select" required value={conv.from} onChange={(e) => setConv({ ...conv, from: e.target.value })}><option value="">-</option>{list.map((u) => <option key={u.unit} value={u.unit}>{u.unit}</option>)}</select>
                        </Field>
                        <Field label={t('gapc.uom.to')}>
                            <select className="select" required value={conv.to} onChange={(e) => setConv({ ...conv, to: e.target.value })}><option value="">-</option>{list.map((u) => <option key={u.unit} value={u.unit}>{u.unit}</option>)}</select>
                        </Field>
                        <div className="col-span-3 flex flex-wrap items-center gap-3">
                            <Button type="submit" variant="secondary">{t('gapc.uom.run')}</Button>
                            {result && <p className="text-base font-semibold" role="status">{result}</p>}
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}

/** GAPC: par levels, supplier lead times, item (lot) controls and unit conversion (stock-control endpoints). */
export default function ReorderTools({ canManage }: { canManage: boolean }) {
    const t = useT();
    const [tab, setTab] = useState('par');
    return (
        <section className="card mt-4 p-3" aria-label={t('gapc.tools.title')}>
            <h2 className="mb-2 text-lg font-semibold">{t('gapc.tools.title')}</h2>
            <Tabs label={t('gapc.tools.title')} className="mb-3" value={tab} onChange={setTab}
                tabs={[{ key: 'par', label: t('gapc.tools.par') }, { key: 'lead', label: t('gapc.tools.lead') }, { key: 'control', label: t('gapc.tools.control') }, { key: 'units', label: t('gapc.tools.units') }]} />
            <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
                {tab === 'par' && <ParLevels canManage={canManage} />}
                {tab === 'lead' && <LeadTimes canManage={canManage} />}
                {tab === 'control' && <ItemControlPanel canManage={canManage} />}
                {tab === 'units' && <Units canManage={canManage} />}
            </div>
        </section>
    );
}
