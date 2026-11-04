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
import { errMsg, fcfa, num, Ref, usePerms } from './ControlShared';
import { useFormErrors, useList } from './StockForms';
import StockTabs from './StockTabs';

interface Task { id: number; room_number: string; status: string; date: string; lines: { stock_item_id: number; item_name: string; quantity: number }[] }
interface Par { id: number; room_type_id: number; stock_item_id: number; item_name: string; par_qty: number; price: number }
interface RoomType { id: number; name: string; code?: string }
interface Room { id: number; number: string }

function ParForm({ open, onClose, initial, onSaved }: { open: boolean; onClose: () => void; initial: Par | null; onSaved: () => void }) {
    const t = useT();
    const types = useList<RoomType>(open ? 'room-types?per_page=200' : null);
    const items = useList<Ref>(open ? 'stock/items?per_page=500&sort=name' : null);
    const [type, setType] = useState('');
    const [item, setItem] = useState('');
    const [par, setPar] = useState('');
    const [price, setPrice] = useState('');
    const [busy, setBusy] = useState(false);
    const [seen, setSeen] = useState<Par | null | undefined>(undefined);
    const f = useFormErrors();
    if (open && seen !== initial) {
        setSeen(initial);
        setType(initial ? String(initial.room_type_id) : ''); setItem(initial ? String(initial.stock_item_id) : '');
        setPar(initial ? String(initial.par_qty) : ''); setPrice(initial ? String(initial.price) : '');
        f.clear();
    }
    if (!open && seen !== undefined) setSeen(undefined);

    async function save() {
        const e: Record<string, string> = {};
        if (!type) e.room_type_id = t('stock4.err.required');
        if (!item) e.stock_item_id = t('stock4.err.required');
        if (!(Number(par) > 0)) e.par_qty = t('stock4.err.positive');
        if (price === '' || Number(price) < 0) e.price = t('stock4.err.required');
        f.setErrors(e);
        if (Object.keys(e).length) return;
        setBusy(true);
        try {
            await api('stock-ops/minibar/pars', { method: 'PUT', body: JSON.stringify({ room_type_id: Number(type), stock_item_id: Number(item), par_qty: Number(par), price: Math.round(Number(price)) }) });
            toast.success(t('stock4.mini.par_saved'));
            onSaved();
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }
    return (
        <Modal open={open} onClose={onClose} title={t('stock4.mini.set_par')}
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t('stock4.common.save')}</Button></>}>
            <div className="grid gap-3">
                {f.general && <p role="alert" className="field-error">{f.general}</p>}
                <Field label={t('stock4.mini.room_type')} required error={f.under('room_type_id')}>
                    <select className="select" value={type} onChange={(e) => setType(e.target.value)} data-autofocus><option value="">{t('stock4.common.choose')}</option>{types.rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select>
                </Field>
                <Field label={t('stock4.common.item')} required error={f.under('stock_item_id')}>
                    <select className="select" value={item} onChange={(e) => setItem(e.target.value)}><option value="">{t('stock4.common.choose')}</option>{items.rows.map((r) => <option key={r.id} value={r.id}>{r.code} - {r.name}</option>)}</select>
                </Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label={t('stock4.mini.par')} required error={f.under('par_qty')}><input className="input" type="number" min="0" step="any" value={par} onChange={(e) => setPar(e.target.value)} /></Field>
                    <Field label={t('stock4.mini.price')} required error={f.under('price')}><input className="input" type="number" min="0" step="1" inputMode="numeric" value={price} onChange={(e) => setPrice(e.target.value)} /></Field>
                </div>
            </div>
        </Modal>
    );
}

function StoreForm({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    const [code, setCode] = useState('MINI');
    const [name, setName] = useState('Minibar');
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    async function save() {
        const e: Record<string, string> = {};
        if (!code.trim()) e.code = t('stock4.err.required');
        if (!name.trim()) e.name = t('stock4.err.required');
        f.setErrors(e);
        if (Object.keys(e).length) return;
        setBusy(true);
        try {
            await api('stock/stores', { method: 'POST', body: JSON.stringify({ code: code.trim(), name: name.trim(), kind: 'minibar' }) });
            toast.success(t('stock4.mini.store_created'));
            onSaved();
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }
    return (
        <Modal open={open} onClose={onClose} title={t('stock4.mini.new_store')}
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t('stock4.common.save')}</Button></>}>
            <div className="grid gap-3">
                {f.general && <p role="alert" className="field-error">{f.general}</p>}
                <p className="text-sm text-slate-600">{t('stock4.mini.store_hint')}</p>
                <Field label={t('stock4.common.code')} required error={f.under('code')}><input className="input" maxLength={12} value={code} onChange={(e) => setCode(e.target.value)} data-autofocus /></Field>
                <Field label={t('stock4.common.name')} required error={f.under('name')}><input className="input" maxLength={100} value={name} onChange={(e) => setName(e.target.value)} /></Field>
            </div>
        </Modal>
    );
}

function ConsumeForm({ open, onClose }: { open: boolean; onClose: () => void }) {
    const t = useT();
    const rooms = useList<Room>(open ? 'rooms?per_page=500&sort=number' : null);
    const items = useList<Ref>(open ? 'stock/items?per_page=500&sort=name' : null);
    const [room, setRoom] = useState('');
    const [lines, setLines] = useState([{ stock_item_id: '', quantity: '1' }]);
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    const setLine = (i: number, p: Partial<{ stock_item_id: string; quantity: string }>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...p } : l)));
    async function save() {
        const e: Record<string, string> = {};
        if (!room) e.room_id = t('stock4.err.required');
        if (lines.some((l) => !l.stock_item_id || !(Number(l.quantity) > 0))) e.items = t('stock4.rfq.err_lines');
        f.setErrors(e);
        if (Object.keys(e).length) return;
        setBusy(true);
        try {
            await api('stock-ops/minibar/consumptions', { method: 'POST', body: JSON.stringify({ room_id: Number(room), items: lines.map((l) => ({ stock_item_id: Number(l.stock_item_id), quantity: Number(l.quantity) })) }) });
            toast.success(t('stock4.mini.consumed'));
            setRoom(''); setLines([{ stock_item_id: '', quantity: '1' }]);
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }
    return (
        <Modal open={open} onClose={onClose} title={t('stock4.mini.consume')}
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t('stock4.common.save')}</Button></>}>
            <div className="grid gap-3">
                {f.general && <p role="alert" className="field-error">{f.general}</p>}
                <Field label={t('stock4.mini.room')} required error={f.under('room_id')}>
                    <select className="select" value={room} onChange={(e) => setRoom(e.target.value)} data-autofocus><option value="">{t('stock4.common.choose')}</option>{rooms.rows.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</select>
                </Field>
                <div>
                    <p className="label">{t('stock4.mini.consumed_items')}</p>
                    {lines.map((l, i) => (
                        <div key={i} className="mb-2 grid grid-cols-[1fr_5rem_auto] items-center gap-2">
                            <select className="select" aria-label={t('stock4.common.item')} value={l.stock_item_id} onChange={(e) => setLine(i, { stock_item_id: e.target.value })}><option value="">{t('stock4.common.item')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}</select>
                            <input className="input" type="number" min="0" step="any" aria-label={t('stock4.common.qty')} value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />
                            <Button variant="ghost" iconOnly icon={Trash2} disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, j) => j !== i))}>{t('stock4.common.remove_line')}</Button>
                        </div>
                    ))}
                    {f.under('items') && <p role="alert" className="field-error">{f.under('items')}</p>}
                    <Button variant="secondary" size="sm" icon={Plus} onClick={() => setLines([...lines, { stock_item_id: '', quantity: '1' }])}>{t('stock4.common.add_line')}</Button>
                </div>
            </div>
        </Modal>
    );
}

export default function Minibar() {
    const t = useT();
    const canEdit = usePerms().includes('stockops.minibar');
    const tasks = useApi<Task[]>('stock-ops/minibar/tasks?status=pending');
    const pars = useApi<Par[]>('stock-ops/minibar/pars');
    const types = useList<RoomType>('room-types?per_page=200');
    const minibarStores = useList<Ref>('stock/stores?filter[kind]=minibar&per_page=50');
    const [msg, setMsg] = useState<string | null>(null);
    const [parOpen, setParOpen] = useState(false);
    const [editPar, setEditPar] = useState<Par | null>(null);
    const [storeOpen, setStoreOpen] = useState(false);
    const [consumeOpen, setConsumeOpen] = useState(false);
    const [doing, setDoing] = useState<number | null>(null);
    const typeName = (id: number) => types.rows.find((r) => r.id === id)?.name ?? String(id);
    const noStore = !minibarStores.loading && minibarStores.rows.length === 0;

    async function done(id: number) {
        setMsg(null);
        setDoing(id);
        try { await api(`stock-ops/minibar/tasks/${id}/complete`, { method: 'POST', body: '{}' }); toast.success(t('stock4.mini.task_done')); tasks.reload(); } catch (e) { setMsg(errMsg(e)); } finally { setDoing(null); }
    }

    return (
        <AppLayout title={t('stock3.mini.title')} subtitle={t('stock3.mini.subtitle')}
            actions={canEdit ? <div className="btn-row">
                <Button variant="secondary" icon={Plus} onClick={() => { setEditPar(null); setParOpen(true); }}>{t('stock4.mini.set_par')}</Button>
                <Button variant="secondary" onClick={() => setConsumeOpen(true)}>{t('stock4.mini.consume')}</Button>
            </div> : undefined}>
            <Head title={t('stock3.mini.title')} />
            <QueryStatus query={tasks} variant="list" />
            <StockTabs current="minibar" />
            {msg && <p role="alert" className="mb-3 text-sm text-red-700">{msg}</p>}
            {noStore && (
                <div className="card mb-4 flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                    <p>{t('stock4.mini.no_store')}</p>
                    {canEdit && <Button onClick={() => setStoreOpen(true)}>{t('stock4.mini.new_store')}</Button>}
                </div>
            )}
            <h2 className="mb-2 font-medium">{t('stock3.mini.tasks')}</h2>
            {tasks.data && tasks.data.length === 0 && <p className="mb-4 text-sm text-slate-500">{t('stock3.mini.no_tasks')}</p>}
            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
                {(tasks.data ?? []).map((k) => (
                    <div key={k.id} className="card p-3 text-sm">
                        <p className="font-medium">{t('stock3.mini.room', { room: k.room_number })} <span className="text-xs text-slate-500">{k.date}</span></p>
                        <ul className="my-2">{k.lines.length === 0 ? <li className="text-slate-500">{t('stock3.mini.check_only')}</li> : k.lines.map((l) => <li key={l.stock_item_id}>{num(l.quantity)} × {l.item_name}</li>)}</ul>
                        {canEdit && <Button className="w-full" loading={doing === k.id} onClick={() => done(k.id)}>{t('stock3.mini.done')}</Button>}
                    </div>
                ))}
            </div>
            <h2 className="mb-2 font-medium">{t('stock3.mini.pars')}</h2>
            <div className="card overflow-x-auto p-3 text-sm">
                <table className="w-full table-auto">
                    <thead><tr className="text-left text-xs uppercase text-slate-500">{['type', 'item', 'par', 'price'].map((h) => <th key={h} className="px-2 py-1">{t(`stock3.mini.col.${h}`)}</th>)}<th className="px-2 py-1" /></tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {(pars.data ?? []).map((p) => (
                            <tr key={p.id}><td className="px-2 py-1">{typeName(p.room_type_id)}</td><td className="px-2 py-1">{p.item_name}</td><td className="px-2 py-1">{num(p.par_qty)}</td><td className="px-2 py-1">{fcfa(p.price)}</td>
                                <td className="px-2 py-1 text-right">{canEdit && <Button size="sm" variant="secondary" onClick={() => { setEditPar(p); setParOpen(true); }}>{t('stock4.common.edit')}</Button>}</td></tr>
                        ))}
                    </tbody>
                </table>
                {pars.data && pars.data.length === 0 && <p className="mt-2 text-slate-500">{t('stock4.mini.no_pars')}</p>}
            </div>
            <ParForm open={parOpen} onClose={() => setParOpen(false)} initial={editPar} onSaved={pars.reload} />
            <StoreForm open={storeOpen} onClose={() => setStoreOpen(false)} onSaved={minibarStores.reload} />
            <ConsumeForm open={consumeOpen} onClose={() => setConsumeOpen(false)} />
        </AppLayout>
    );
}
