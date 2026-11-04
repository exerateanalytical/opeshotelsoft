import { useState } from 'react';
import Button from '@/Components/ui/Button';
import CameraScan from '@/Components/gapj/CameraScan';
import Field from '@/Components/ui/Field';
import { toast } from '@/Components/ui/Toast';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Ref, usePerms } from './ControlShared';
import { esc, printHtml } from './gapcShared';
import { useFormErrors, useList } from './StockForms';

interface Code { value: string; label: string; external?: string[] }
interface LotRow { id: number; item: string; lot_number: string; expiry_date: string | null }

function RegisterBarcode() {
    const t = useT();
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const [itemId, setItemId] = useState('');
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    async function save() {
        setBusy(true);
        f.clear();
        try {
            await api('stock-ops/scan/barcodes', { method: 'POST', body: JSON.stringify({ stock_item_id: Number(itemId), code: code.trim() }) });
            toast.success(t('gapc.scan.registered'));
            setCode('');
        } catch (e) { f.fail(e); } finally { setBusy(false); }
    }
    return (
        <form className="card grid gap-3 p-3" onSubmit={(e) => { e.preventDefault(); save(); }}>
            <h2 className="text-lg font-semibold">{t('gapc.scan.register_title')}</h2>
            <p className="text-sm text-slate-500">{t('gapc.scan.register_hint')}</p>
            {f.general && <p role="alert" className="field-error">{f.general}</p>}
            <Field label={t('gapc.col.item')} required error={f.under('stock_item_id')}>
                <select className="select" value={itemId} onChange={(e) => setItemId(e.target.value)}>
                    <option value="">{t('gapc.choose')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                </select>
            </Field>
            <Field label={t('gapc.scan.barcode')} required error={f.under('code')}><input className="input" maxLength={64} value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off" /></Field>
            <div><Button type="submit" loading={busy} disabled={!itemId || !code.trim()}>{t('gapc.scan.register')}</Button></div>
        </form>
    );
}

function ScanReceive({ canReceive }: { canReceive: boolean }) {
    const t = useT();
    const [form, setForm] = useState({ po: '', code: '', quantity: '1', delivery_note: '' });
    const [busy, setBusy] = useState(false);
    const [last, setLast] = useState<string | null>(null);
    const f = useFormErrors();
    async function receive() {
        setBusy(true);
        f.clear();
        setLast(null);
        try {
            await api('stock-ops/scan/receive', { method: 'POST', body: JSON.stringify({ po: form.po.trim(), code: form.code.trim(), quantity: Number(form.quantity), delivery_note: form.delivery_note || null }) });
            setLast(t('gapc.scan.received', { qty: form.quantity, code: form.code.trim() }));
            toast.success(t('gapc.scan.received_toast'));
            setForm({ ...form, code: '', quantity: '1' });
        } catch (e) { f.fail(e); } finally { setBusy(false); }
    }
    return (
        <form className="card grid gap-3 p-3" onSubmit={(e) => { e.preventDefault(); receive(); }}>
            <h2 className="text-lg font-semibold">{t('gapc.scan.receive_title')}</h2>
            <p className="text-sm text-slate-500">{canReceive ? t('gapc.scan.receive_hint') : t('gapc.scan.receive_denied')}</p>
            {f.general && <p role="alert" className="field-error">{f.general}</p>}
            <Field label={t('gapc.scan.po')} hint={t('gapc.scan.po_hint')} required error={f.under('po')}><input className="input" disabled={!canReceive} value={form.po} onChange={(e) => setForm({ ...form, po: e.target.value })} autoComplete="off" /></Field>
            {canReceive && <CameraScan onCode={(c) => setForm((p) => ({ ...p, code: c }))} />}
            <div className="grid grid-cols-2 gap-3">
                <Field label={t('gapc.scan.code')} hint={t('gapc.scan.code_hint')} required error={f.under('code')}><input className="input" disabled={!canReceive} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} autoComplete="off" /></Field>
                <Field label={t('gapc.scan.qty')} required error={f.under('quantity')}><input className="input" type="number" min="0" step="any" disabled={!canReceive} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} /></Field>
            </div>
            <Field label={t('gapc.scan.delivery_note')} error={f.under('delivery_note')}><input className="input" maxLength={60} disabled={!canReceive} value={form.delivery_note} onChange={(e) => setForm({ ...form, delivery_note: e.target.value })} /></Field>
            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" loading={busy} disabled={!canReceive || !form.po.trim() || !form.code.trim() || !(Number(form.quantity) > 0)}>{t('gapc.scan.receive')}</Button>
                {last && <p role="status" className="text-sm text-emerald-800">{last}</p>}
            </div>
        </form>
    );
}

function Codes() {
    const t = useT();
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const lots = useList<LotRow>('stock-control/lots');
    const [itemId, setItemId] = useState('');
    const [lotId, setLotId] = useState('');
    const [code, setCode] = useState<Code | null>(null);
    const f = useFormErrors();
    async function load(kind: 'item' | 'lot', id: string) {
        f.clear();
        setCode(null);
        if (!id) return;
        try { setCode(await api<Code>(`stock-ops/scan/codes/${kind}/${id}`)); } catch (e) { f.fail(e); }
    }
    function print() {
        if (!code) return;
        printHtml(code.label, `<h1>${esc(code.label)}</h1><p style="font:32px monospace">${esc(code.value)}</p>${(code.external ?? []).length ? `<p>${esc((code.external ?? []).join(', '))}</p>` : ''}`);
    }
    return (
        <div className="card grid gap-3 p-3">
            <h2 className="text-lg font-semibold">{t('gapc.scan.codes_title')}</h2>
            <p className="text-sm text-slate-500">{t('gapc.scan.codes_hint')}</p>
            {f.general && <p role="alert" className="field-error">{f.general}</p>}
            <div className="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                <Field label={t('gapc.scan.item_code')}>
                    <select className="select" value={itemId} onChange={(e) => { setItemId(e.target.value); setLotId(''); load('item', e.target.value); }}>
                        <option value="">{t('gapc.choose')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                    </select>
                </Field>
                <Field label={t('gapc.scan.lot_code')}>
                    <select className="select" value={lotId} onChange={(e) => { setLotId(e.target.value); setItemId(''); load('lot', e.target.value); }}>
                        <option value="">{t('gapc.choose')}</option>{lots.rows.map((l) => <option key={l.id} value={l.id}>{l.item} - {l.lot_number}{l.expiry_date ? ` (${String(l.expiry_date).slice(0, 10)})` : ''}</option>)}
                    </select>
                </Field>
            </div>
            {code && (
                <div role="status" className="rounded-lg border border-slate-200 p-3">
                    <p className="text-sm text-slate-500">{code.label}</p>
                    <p className="font-mono text-xl font-semibold">{code.value}</p>
                    {(code.external ?? []).length > 0 && <p className="text-sm">{t('gapc.scan.external')}: {(code.external ?? []).join(', ')}</p>}
                    <Button className="mt-2" variant="secondary" size="sm" onClick={print}>{t('gapc.print')}</Button>
                </div>
            )}
        </div>
    );
}

/** GAPC: register an external barcode, receive against a PO by scan code, and show the item / lot codes (stock-ops/scan/*). */
export default function ScanTools() {
    const perms = usePerms();
    return (
        <div className="mt-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <RegisterBarcode />
            <ScanReceive canReceive={perms.length === 0 || perms.includes('purchasing.manage')} />
            <div className="lg:col-span-2"><Codes /></div>
        </div>
    );
}
