import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { num, Ref, usePerms } from './ControlShared';
import { useFormErrors, useList } from './StockForms';
import StockTabs from './StockTabs';

interface Rec { id: number; employee_name: string; issuable_name: string; kind: string; quantity: number; returned_quantity: number; issued_on: string; due_back_on: string | null; status: string; overdue: boolean }
interface Issuable { id: number; code: string; name: string; kind: string }
interface Emp { id: number; first_name: string; last_name: string; number?: string }

function IssueForm({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    const issuables = useList<Issuable>(open ? 'stock-ops/issuables' : null);
    const [q, setQ] = useState('');
    const [term, setTerm] = useState('');
    useEffect(() => { const id = setTimeout(() => setTerm(q.trim()), 300); return () => clearTimeout(id); }, [q]);
    const emps = useList<Emp>(open ? `hr/employees?status=active&q=${encodeURIComponent(term)}` : null);
    const [emp, setEmp] = useState('');
    const [item, setItem] = useState('');
    const [qty, setQty] = useState('1');
    const [serial, setSerial] = useState('');
    const [size, setSize] = useState('');
    const [due, setDue] = useState('');
    const [busy, setBusy] = useState(false);
    const f = useFormErrors();
    const chosen = issuables.rows.find((i) => String(i.id) === item);

    async function save() {
        const e: Record<string, string> = {};
        if (!emp) e.employee_id = t('stock4.err.required');
        if (!item) e.issuable_id = t('stock4.err.required');
        if (!(Number(qty) > 0)) e.quantity = t('stock4.err.positive');
        f.setErrors(e);
        if (Object.keys(e).length) return;
        setBusy(true);
        try {
            await api('stock-ops/issues', { method: 'POST', body: JSON.stringify({ employee_id: Number(emp), issuable_id: Number(item), quantity: Number(qty), serial: serial || null, size: size || null, due_back_on: due || null }) });
            toast.success(t('stock4.issue.issued'));
            setEmp(''); setItem(''); setQty('1'); setSerial(''); setSize(''); setDue(''); setQ('');
            onSaved();
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }
    return (
        <Modal open={open} onClose={onClose} title={t('stock4.issue.new')}
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t('stock4.issue.do_issue')}</Button></>}>
            <div className="grid gap-3 sm:grid-cols-2">
                {f.general && <p role="alert" className="field-error sm:col-span-2">{f.general}</p>}
                <Field label={t('stock4.issue.find_employee')} className="sm:col-span-2"><input className="input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('stock4.issue.find_ph')} data-autofocus /></Field>
                <Field label={t('stock3.issue.col.employee')} required error={f.under('employee_id')} className="sm:col-span-2">
                    <select className="select" value={emp} onChange={(e) => setEmp(e.target.value)}><option value="">{t('stock4.common.choose')}</option>{emps.rows.map((m) => <option key={m.id} value={m.id}>{m.last_name} {m.first_name}{m.number ? ` (${m.number})` : ''}</option>)}</select>
                </Field>
                <Field label={t('stock3.issue.col.item')} required error={f.under('issuable_id')} className="sm:col-span-2">
                    <select className="select" value={item} onChange={(e) => setItem(e.target.value)}><option value="">{t('stock4.common.choose')}</option>{issuables.rows.map((m) => <option key={m.id} value={m.id}>{m.code} - {m.name}</option>)}</select>
                </Field>
                <Field label={t('stock4.common.qty')} required error={f.under('quantity')}><input className="input" type="number" min="0" step="any" value={qty} onChange={(e) => setQty(e.target.value)} /></Field>
                <Field label={t('stock4.issue.due')} error={f.under('due_back_on')}><input className="input" type="date" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
                <Field label={t('stock4.issue.serial')} error={f.under('serial')}><input className="input" maxLength={60} value={serial} onChange={(e) => setSerial(e.target.value)} /></Field>
                {(!chosen || chosen.kind === 'uniform') && <Field label={t('stock4.issue.size')} error={f.under('size')}><input className="input" maxLength={20} value={size} onChange={(e) => setSize(e.target.value)} /></Field>}
            </div>
        </Modal>
    );
}

function IssuableForm({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    const items = useList<Ref>(open ? 'stock/items?per_page=500&sort=name' : null);
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [kind, setKind] = useState('uniform');
    const [stockItem, setStockItem] = useState('');
    const [value, setValue] = useState('');
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
            await api('stock-ops/issuables', { method: 'POST', body: JSON.stringify({ code: code.trim(), name: name.trim(), kind, stock_item_id: stockItem ? Number(stockItem) : null, unit_value: value === '' ? null : Math.round(Number(value)) }) });
            toast.success(t('stock4.issue.item_saved'));
            setCode(''); setName(''); setStockItem(''); setValue('');
            onSaved();
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }
    return (
        <Modal open={open} onClose={onClose} title={t('stock4.issue.new_item')}
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t('stock4.common.save')}</Button></>}>
            <div className="grid gap-3 sm:grid-cols-2">
                {f.general && <p role="alert" className="field-error sm:col-span-2">{f.general}</p>}
                <Field label={t('stock4.common.code')} required error={f.under('code')}><input className="input" maxLength={20} value={code} onChange={(e) => setCode(e.target.value)} data-autofocus /></Field>
                <Field label={t('stock4.issue.kind')} required error={f.under('kind')}>
                    <select className="select" value={kind} onChange={(e) => setKind(e.target.value)}><option value="uniform">{t('stock4.issue.kind_uniform')}</option><option value="asset">{t('stock4.issue.kind_asset')}</option></select>
                </Field>
                <Field label={t('stock4.common.name')} required error={f.under('name')} className="sm:col-span-2"><input className="input" maxLength={200} value={name} onChange={(e) => setName(e.target.value)} /></Field>
                <Field label={t('stock4.issue.stock_item')} hint={t('stock4.issue.stock_item_hint')} error={f.under('stock_item_id')}>
                    <select className="select" value={stockItem} onChange={(e) => setStockItem(e.target.value)}><option value="">{t('stock4.common.none')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}</select>
                </Field>
                <Field label={t('stock4.issue.unit_value')} error={f.under('unit_value')}><input className="input" type="number" min="0" step="1" inputMode="numeric" value={value} onChange={(e) => setValue(e.target.value)} /></Field>
            </div>
        </Modal>
    );
}

function ReturnForm({ rec, onClose, onSaved }: { rec: Rec | null; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    const [qty, setQty] = useState('');
    const [condition, setCondition] = useState('good');
    const [busy, setBusy] = useState(false);
    const [seen, setSeen] = useState<number | null>(null);
    const f = useFormErrors();
    const left = rec ? rec.quantity - rec.returned_quantity : 0;
    if (rec && seen !== rec.id) { setSeen(rec.id); setQty(String(left)); setCondition('good'); f.clear(); }
    if (!rec && seen !== null) setSeen(null);
    async function save() {
        if (!rec) return;
        if (!(Number(qty) > 0) || Number(qty) > left) { f.setErrors({ quantity: t('stock4.issue.err_qty', { max: left }) }); return; }
        setBusy(true);
        try {
            await api(`stock-ops/issues/${rec.id}/return`, { method: 'POST', body: JSON.stringify({ quantity: Number(qty), condition }) });
            toast.success(t('stock4.issue.returned'));
            onSaved();
            onClose();
        } catch (x) { f.fail(x); } finally { setBusy(false); }
    }
    return (
        <Modal open={!!rec} onClose={onClose} title={t('stock4.issue.return_title', { item: rec?.issuable_name ?? '' })}
            footer={<><Button variant="secondary" onClick={onClose}>{t('stock4.common.cancel')}</Button><Button loading={busy} onClick={save}>{t('stock3.issue.return')}</Button></>}>
            <div className="grid gap-3">
                {f.general && <p role="alert" className="field-error">{f.general}</p>}
                <p className="text-sm text-slate-600">{rec?.employee_name} - {t('stock4.issue.outstanding', { qty: num(left) })}</p>
                <Field label={t('stock4.issue.return_qty')} required error={f.under('quantity')}><input className="input" type="number" min="0" step="any" value={qty} onChange={(e) => setQty(e.target.value)} data-autofocus /></Field>
                <Field label={t('stock4.issue.condition')} error={f.under('condition')}>
                    <select className="select" value={condition} onChange={(e) => setCondition(e.target.value)}>{['good', 'worn', 'damaged', 'lost'].map((c) => <option key={c} value={c}>{t(`stock4.issue.cond.${c}`)}</option>)}</select>
                </Field>
            </div>
        </Modal>
    );
}

export default function Issues() {
    const t = useT();
    const canEdit = usePerms().includes('stockops.issues');
    const [overdue, setOverdue] = useState(false);
    const rows = useApi<Rec[]>(`stock-ops/issues${overdue ? '?overdue=1' : ''}`);
    const [issueOpen, setIssueOpen] = useState(false);
    const [itemOpen, setItemOpen] = useState(false);
    const [ret, setRet] = useState<Rec | null>(null);
    const issuables = useList<Issuable>('stock-ops/issuables');

    return (
        <AppLayout title={t('stock3.issue.title')} subtitle={t('stock3.issue.subtitle')}
            actions={canEdit ? <div className="btn-row">
                <Button icon={Plus} onClick={() => setIssueOpen(true)}>{t('stock4.issue.new')}</Button>
                <Button variant="secondary" onClick={() => setItemOpen(true)}>{t('stock4.issue.new_item')}</Button>
            </div> : undefined}>
            <Head title={t('stock3.issue.title')} />
            <QueryStatus query={rows} variant="table" />
            <StockTabs current="issues" />
            <label className="mb-3 flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5 shrink-0" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} />{t('stock3.issue.overdue_only')}</label>
            {canEdit && issuables.rows.length === 0 && !issuables.loading && <p className="mb-3 text-sm text-slate-600">{t('stock4.issue.no_items')}</p>}
            <div className="card overflow-x-auto p-3 text-sm">
                <table className="w-full table-auto">
                    <thead><tr className="text-left text-xs uppercase text-slate-500">{['employee', 'item', 'qty', 'issued', 'due', 'status', ''].map((h) => <th key={h} className="px-2 py-1">{h && t(`stock3.issue.col.${h}`)}</th>)}</tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {(rows.data ?? []).map((r) => (
                            <tr key={r.id}>
                                <td className="px-2 py-1">{r.employee_name}</td><td className="px-2 py-1">{r.issuable_name}</td><td className="px-2 py-1">{num(r.returned_quantity)} / {num(r.quantity)}</td>
                                <td className="px-2 py-1">{r.issued_on.slice(0, 10)}</td><td className={`px-2 py-1 ${r.overdue ? 'text-red-700' : ''}`}>{r.due_back_on?.slice(0, 10) ?? '-'}</td>
                                <td className="px-2 py-1">{t(`stock3.issue.status.${r.status}`)}</td>
                                <td className="px-2 py-1">{canEdit && r.status === 'issued' && <Button size="sm" variant="secondary" onClick={() => setRet(r)}>{t('stock3.issue.return')}</Button>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {rows.data && rows.data.length === 0 && <p className="mt-2 text-slate-500">{t('stock3.issue.empty')}</p>}
            </div>
            <IssueForm open={issueOpen} onClose={() => setIssueOpen(false)} onSaved={rows.reload} />
            <IssuableForm open={itemOpen} onClose={() => setItemOpen(false)} onSaved={issuables.reload} />
            <ReturnForm rec={ret} onClose={() => setRet(null)} onSaved={rows.reload} />
        </AppLayout>
    );
}
