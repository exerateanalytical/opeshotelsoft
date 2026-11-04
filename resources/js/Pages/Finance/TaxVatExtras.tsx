import { FormEvent, useEffect, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { formatDate } from '@/lib/utils';
import { errMsg, Field } from './shared';
import { M } from './coreShared';
import { C, CardTable, SectionTitle, yesNo } from './gapbShared';

interface Line { code: string; label: string; amount: number }
interface Preview { country: string; form: string; rate: number; period_from: string; period_to: string; filing_deadline: string; figures: Record<string, number>; lines: Line[] }
interface ReturnDetail { id: number; period_from: string; period_to: string; version: number; status: string; filing_reference: string | null; form_code?: string; figures: Record<string, number>; lines: Line[] }
interface Verify { id: number; status: string; matches: boolean; filed: Record<string, number>; ledger_now: Record<string, number> }

function LinesTable({ lines, caption }: { lines: Line[]; caption: string }) {
    const t = useT();
    return (
        <CardTable caption={caption} head={[t('gapb.vat.box'), t('gapb.vat.label'), t('acc3.amount')]}>
            {lines.map((l) => <tr key={l.code}><C label={t('gapb.vat.box')} mono>{l.code}</C><C label={t('gapb.vat.label')}>{l.label}</C><C label={t('acc3.amount')} r b>{M(l.amount)}</C></tr>)}
        </CardTable>
    );
}

/** VAT return preview (GET accounting3/tax/vat-returns/preview) for a period, without saving anything. */
export function VatPreviewButton({ from, to }: { from: string; to: string }) {
    const t = useT();
    const [data, setData] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const go = async () => {
        setBusy(true);
        try { setData(await api<Preview>(`accounting3/tax/vat-returns/preview?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)); } catch (e) { toast.error(errMsg(e)); } finally { setBusy(false); }
    };
    return (
        <>
            <button type="button" className="btn-secondary" disabled={busy || !from || !to} onClick={go}>{t('gapb.vat.preview')}</button>
            <Modal open={!!data} onClose={() => setData(null)} size="lg" title={t('gapb.vat.preview_title')} footer={<button type="button" className="btn-secondary" onClick={() => setData(null)}>{t('gapb.close')}</button>}>
                {data && (
                    <div className="grid gap-3">
                        <p className="text-sm text-slate-600">{data.form} - {formatDate(data.period_from)} / {formatDate(data.period_to)} - {t('gapb.vat.rate', { rate: data.rate })}</p>
                        <p className="text-sm">{t('gapb.vat.deadline', { date: formatDate(data.filing_deadline) })}</p>
                        <LinesTable lines={data.lines} caption={t('gapb.vat.preview_title')} />
                        <p className="text-sm text-slate-500">{t('gapb.vat.preview_note')}</p>
                    </div>
                )}
            </Modal>
        </>
    );
}

/** Filed or draft VAT return detail with a ledger verification (GET .../{id} and .../{id}/verify). */
export function VatReturnDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
    const t = useT();
    const detail = useApi<ReturnDetail>(id ? `accounting3/tax/vat-returns/${id}` : null, [id]);
    const [ver, setVer] = useState<Verify | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => setVer(null), [id]);
    const verify = async () => {
        if (!id) return;
        setBusy(true);
        try { setVer(await api<Verify>(`accounting3/tax/vat-returns/${id}/verify`)); } catch (e) { toast.error(errMsg(e)); } finally { setBusy(false); }
    };
    const d = detail.data;
    const keys = ver ? Array.from(new Set([...Object.keys(ver.filed ?? {}), ...Object.keys(ver.ledger_now ?? {})])) : [];

    return (
        <Modal open={id !== null} onClose={onClose} size="lg" variant="sheet" title={t('gapb.vat.detail_title')}
            footer={<><button type="button" className="btn-secondary" onClick={onClose}>{t('gapb.close')}</button><button type="button" className="btn-primary" disabled={busy || !d} onClick={verify}>{t('gapb.vat.verify')}</button></>}>
            {detail.error && <p className="text-sm text-red-700" role="alert">{detail.error.message}</p>}
            {d && (
                <div className="grid gap-3">
                    <dl className="grid grid-cols-2 gap-2 text-sm">
                        <dt className="text-slate-500">{t('acc3.pay.period')}</dt><dd>{formatDate(d.period_from)} / {formatDate(d.period_to)}</dd>
                        <dt className="text-slate-500">{t('gapb.version')}</dt><dd>{d.version}</dd>
                        <dt className="text-slate-500">{t('acc3.status')}</dt><dd>{t(`acc3.tax.st.${d.status}`)}</dd>
                        <dt className="text-slate-500">{t('acc3.tax.reference')}</dt><dd>{d.filing_reference ?? '-'}</dd>
                    </dl>
                    {d.lines?.length ? <LinesTable lines={d.lines} caption={t('gapb.vat.detail_title')} /> : null}
                    {ver && (
                        <div className={`border p-3 ${ver.matches ? 'border-emerald-700' : 'border-red-700'}`} role="status">
                            <p className={`mb-2 text-sm font-semibold ${ver.matches ? 'text-emerald-700' : 'text-red-700'}`}>{ver.matches ? t('gapb.vat.matches') : t('gapb.vat.differs')}</p>
                            <CardTable caption={t('gapb.vat.verify')} head={[t('gapb.vat.figure'), t('gapb.vat.filed'), t('gapb.vat.ledger'), t('gapb.vat.diff')]}>
                                {keys.map((k) => {
                                    const a = Number(ver.filed?.[k] ?? 0), b = Number(ver.ledger_now?.[k] ?? 0);
                                    return <tr key={k}><C label={t('gapb.vat.figure')} mono>{k}</C><C label={t('gapb.vat.filed')} r>{M(a)}</C><C label={t('gapb.vat.ledger')} r>{M(b)}</C><C label={t('gapb.vat.diff')} r b>{a === b ? '-' : M(b - a)}</C></tr>;
                                })}
                            </CardTable>
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}

interface Rate { id: number; code: string; name: string; rate: number; base: string; threshold: number; active: boolean }

/** Withholding tax rate table (GET/POST accounting3/tax/withholding-rates, upsert by code). */
export function WhtRatesPanel() {
    const t = useT();
    const can = useCan();
    const rates = useApi<Rate[]>('accounting3/tax/withholding-rates');
    const [f, setF] = useState<{ code: string; name: string; rate: string; base: string; threshold: string; active: boolean; isNew: boolean } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const manage = can('accounting.tax_file');

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!f) return;
        setErr(null);
        try {
            await api('accounting3/tax/withholding-rates', { method: 'POST', body: JSON.stringify({ code: f.code, name: f.name, rate: Number(f.rate), base: f.base, threshold: Number(f.threshold) || 0, active: f.active }) });
            toast.success(t('gapb.saved')); setF(null); rates.reload();
        } catch (x) { setErr(errMsg(x)); }
    };

    return (
        <div className="card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <SectionTitle hint={t('gapb.wht.hint')}>{t('gapb.wht.title')}</SectionTitle>
                {manage && <button type="button" className="btn-primary" onClick={() => { setErr(null); setF({ code: '', name: '', rate: '', base: 'net', threshold: '0', active: true, isNew: true }); }}>{t('gapb.wht.new')}</button>}
            </div>
            {rates.error && <p className="text-sm text-red-700" role="alert">{rates.error.message}</p>}
            {rates.data?.length === 0 && <EmptyState title={t('gapb.wht.empty')} />}
            {!!rates.data?.length && (
                <CardTable caption={t('gapb.wht.title')} head={[t('gapb.code'), t('acc3.name'), t('gapb.rate'), t('gapb.wht.base'), t('gapb.wht.threshold'), t('gapb.active'), '']}>
                    {rates.data.map((r) => (
                        <tr key={r.code}>
                            <C label={t('gapb.code')} mono>{r.code}</C><C label={t('acc3.name')}>{r.name}</C><C label={t('gapb.rate')} r>{Number(r.rate)} %</C>
                            <C label={t('gapb.wht.base')}>{r.base === 'gross' ? t('gapb.wht.gross') : t('gapb.wht.net')}</C><C label={t('gapb.wht.threshold')} r>{M(r.threshold)}</C>
                            <C label={t('gapb.active')}>{yesNo(r.active) ? t('acc3.yes') : t('acc3.no')}</C>
                            <C label="" r>{manage && <button type="button" className="btn-secondary btn-sm" aria-label={`${t('gapb.edit')} ${r.code}`} onClick={() => { setErr(null); setF({ code: r.code, name: r.name, rate: String(r.rate), base: r.base, threshold: String(r.threshold ?? 0), active: yesNo(r.active), isNew: false }); }}>{t('gapb.edit')}</button>}</C>
                        </tr>
                    ))}
                </CardTable>
            )}
            <Modal open={!!f} onClose={() => setF(null)} title={f?.isNew ? t('gapb.wht.new') : t('gapb.wht.edit_title')} formId="wht-form"
                footer={<><button type="button" className="btn-secondary" onClick={() => setF(null)}>{t('gapb.cancel')}</button><button type="submit" form="wht-form" className="btn-primary">{t('common.save')}</button></>}>
                {f && (
                    <form id="wht-form" onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                        {err && <p className="text-sm text-red-700 sm:col-span-2" role="alert">{err}</p>}
                        <Field label={t('gapb.code')}><input required maxLength={30} readOnly={!f.isNew} className="input w-full font-mono" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} /></Field>
                        <Field label={t('acc3.name')}><input required maxLength={150} className="input w-full" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
                        <Field label={t('gapb.rate')}><input required type="number" step="0.01" min={0} max={50} className="input w-full" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Field>
                        <Field label={t('gapb.wht.base')}><select className="select w-full" value={f.base} onChange={(e) => setF({ ...f, base: e.target.value })}><option value="net">{t('gapb.wht.net')}</option><option value="gross">{t('gapb.wht.gross')}</option></select></Field>
                        <Field label={t('gapb.wht.threshold')}><input type="number" min={0} className="input w-full" value={f.threshold} onChange={(e) => setF({ ...f, threshold: e.target.value })} /></Field>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} />{t('gapb.active')}</label>
                    </form>
                )}
            </Modal>
        </div>
    );
}

interface StampRule { code: string; name: string; applies_to: string; kind: string; rate: number; fixed_amount: number; min_base: number; min_duty: number; active: boolean }
const APPLIES = ['cash_receipt', 'supplier_payment', 'contract', 'other'];

/** Stamp-duty rules (rules from GET accounting3/tax/stamp-duty, POST .../stamp-duty/rules upserts by code). */
export function StampRulesPanel({ rules, onSaved }: { rules: StampRule[] | undefined; onSaved: () => void }) {
    const t = useT();
    const can = useCan();
    type F = { code: string; name: string; applies_to: string; kind: string; rate: string; fixed_amount: string; min_base: string; min_duty: string; active: boolean; isNew: boolean };
    const [f, setF] = useState<F | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const manage = can('accounting.tax_file');

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!f) return;
        setErr(null);
        try {
            await api('accounting3/tax/stamp-duty/rules', { method: 'POST', body: JSON.stringify({ code: f.code, name: f.name, applies_to: f.applies_to, kind: f.kind, rate: Number(f.rate) || 0, fixed_amount: Number(f.fixed_amount) || 0, min_base: Number(f.min_base) || 0, min_duty: Number(f.min_duty) || 0, active: f.active }) });
            toast.success(t('gapb.saved')); setF(null); onSaved();
        } catch (x) { setErr(errMsg(x)); }
    };
    const edit = (r?: StampRule) => {
        setErr(null);
        setF(r ? { code: r.code, name: r.name, applies_to: r.applies_to, kind: r.kind, rate: String(r.rate ?? 0), fixed_amount: String(r.fixed_amount ?? 0), min_base: String(r.min_base ?? 0), min_duty: String(r.min_duty ?? 0), active: yesNo(r.active), isNew: false }
            : { code: '', name: '', applies_to: 'cash_receipt', kind: 'percent', rate: '0', fixed_amount: '0', min_base: '0', min_duty: '0', active: true, isNew: true });
    };

    return (
        <div className="card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <SectionTitle hint={t('gapb.stamp.hint')}>{t('gapb.stamp.title')}</SectionTitle>
                {manage && <button type="button" className="btn-primary" onClick={() => edit()}>{t('gapb.stamp.new')}</button>}
            </div>
            {rules?.length === 0 && <EmptyState title={t('gapb.stamp.empty')} />}
            {!!rules?.length && (
                <CardTable caption={t('gapb.stamp.title')} head={[t('gapb.code'), t('acc3.name'), t('gapb.stamp.applies'), t('gapb.stamp.duty'), t('gapb.stamp.min_base'), t('gapb.active'), '']}>
                    {rules.map((r) => (
                        <tr key={r.code}>
                            <C label={t('gapb.code')} mono>{r.code}</C><C label={t('acc3.name')}>{r.name}</C><C label={t('gapb.stamp.applies')}>{t(`gapb.stamp.at.${r.applies_to}`)}</C>
                            <C label={t('gapb.stamp.duty')} r>{r.kind === 'fixed' ? M(r.fixed_amount) : `${Number(r.rate)} %`}</C><C label={t('gapb.stamp.min_base')} r>{M(r.min_base)}</C>
                            <C label={t('gapb.active')}>{yesNo(r.active) ? t('acc3.yes') : t('acc3.no')}</C>
                            <C label="" r>{manage && <button type="button" className="btn-secondary btn-sm" aria-label={`${t('gapb.edit')} ${r.code}`} onClick={() => edit(r)}>{t('gapb.edit')}</button>}</C>
                        </tr>
                    ))}
                </CardTable>
            )}
            <Modal open={!!f} onClose={() => setF(null)} title={f?.isNew ? t('gapb.stamp.new') : t('gapb.stamp.edit_title')} formId="stamp-form"
                footer={<><button type="button" className="btn-secondary" onClick={() => setF(null)}>{t('gapb.cancel')}</button><button type="submit" form="stamp-form" className="btn-primary">{t('common.save')}</button></>}>
                {f && (
                    <form id="stamp-form" onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                        {err && <p className="text-sm text-red-700 sm:col-span-2" role="alert">{err}</p>}
                        <Field label={t('gapb.code')}><input required maxLength={30} readOnly={!f.isNew} className="input w-full font-mono" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} /></Field>
                        <Field label={t('acc3.name')}><input required maxLength={150} className="input w-full" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
                        <Field label={t('gapb.stamp.applies')}><select className="select w-full" value={f.applies_to} onChange={(e) => setF({ ...f, applies_to: e.target.value })}>{APPLIES.map((a) => <option key={a} value={a}>{t(`gapb.stamp.at.${a}`)}</option>)}</select></Field>
                        <Field label={t('gapb.stamp.kind')}><select className="select w-full" value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })}><option value="percent">{t('gapb.stamp.percent')}</option><option value="fixed">{t('gapb.stamp.fixed')}</option></select></Field>
                        {f.kind === 'percent'
                            ? <Field label={t('gapb.rate')}><input type="number" step="0.01" min={0} max={100} className="input w-full" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Field>
                            : <Field label={t('gapb.stamp.fixed_amount')}><input type="number" min={0} className="input w-full" value={f.fixed_amount} onChange={(e) => setF({ ...f, fixed_amount: e.target.value })} /></Field>}
                        <Field label={t('gapb.stamp.min_base')}><input type="number" min={0} className="input w-full" value={f.min_base} onChange={(e) => setF({ ...f, min_base: e.target.value })} /></Field>
                        <Field label={t('gapb.stamp.min_duty')}><input type="number" min={0} className="input w-full" value={f.min_duty} onChange={(e) => setF({ ...f, min_duty: e.target.value })} /></Field>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} />{t('gapb.active')}</label>
                    </form>
                )}
            </Modal>
        </div>
    );
}

interface Supplier { id: number; name: string }
interface Prof { supplier_id: number; withholding_code: string | null; exempt: boolean; niu: string | null }

/** Supplier tax profile: withholding code, exemption and NIU (GET/PUT accounting3/tax/suppliers/{s}/profile). */
export function SupplierTaxPanel() {
    const t = useT();
    const can = useCan();
    const suppliers = useApi<{ data: Supplier[] } | Supplier[]>('suppliers?per_page=200');
    const rates = useApi<Rate[]>('accounting3/tax/withholding-rates');
    const list = Array.isArray(suppliers.data) ? suppliers.data : suppliers.data?.data ?? [];
    const [sid, setSid] = useState('');
    const prof = useApi<Prof>(sid ? `accounting3/tax/suppliers/${sid}/profile` : null, [sid]);
    const [f, setF] = useState({ withholding_code: '', exempt: false, niu: '' });
    const [err, setErr] = useState<string | null>(null);
    const manage = can('accounting.tax_file', 'payables.manage');

    useEffect(() => {
        if (prof.data) setF({ withholding_code: prof.data.withholding_code ?? '', exempt: yesNo(prof.data.exempt), niu: prof.data.niu ?? '' });
    }, [prof.data]);

    const save = async (e: FormEvent) => {
        e.preventDefault();
        setErr(null);
        try {
            await api(`accounting3/tax/suppliers/${sid}/profile`, { method: 'PUT', body: JSON.stringify({ withholding_code: f.withholding_code || null, exempt: f.exempt, niu: f.niu || null }) });
            toast.success(t('gapb.saved')); prof.reload();
        } catch (x) { setErr(errMsg(x)); }
    };

    return (
        <div className="card p-4">
            <SectionTitle hint={t('gapb.sup.hint')}>{t('gapb.sup.title')}</SectionTitle>
            <Field label={t('acc3.pay.supplier')}>
                <select className="select w-full" value={sid} onChange={(e) => setSid(e.target.value)}>
                    <option value="">{t('gapb.sup.choose')}</option>
                    {list.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
            </Field>
            {sid && prof.error && <p className="mt-2 text-sm text-red-700" role="alert">{prof.error.message}</p>}
            {sid && prof.data && (
                <form onSubmit={save} className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                    {err && <p className="text-sm text-red-700 sm:col-span-2" role="alert">{err}</p>}
                    <Field label={t('gapb.sup.code')}>
                        <select className="select w-full" disabled={!manage} value={f.withholding_code} onChange={(e) => setF({ ...f, withholding_code: e.target.value })}>
                            <option value="">{t('gapb.sup.none')}</option>
                            {rates.data?.map((r) => <option key={r.code} value={r.code}>{r.code} - {r.name} ({Number(r.rate)} %)</option>)}
                        </select>
                    </Field>
                    <Field label={t('gapb.sup.niu')}><input maxLength={40} readOnly={!manage} className="input w-full font-mono" value={f.niu} onChange={(e) => setF({ ...f, niu: e.target.value })} /></Field>
                    <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" disabled={!manage} checked={f.exempt} onChange={(e) => setF({ ...f, exempt: e.target.checked })} />{t('gapb.sup.exempt')}</label>
                    {manage && <button type="submit" className="btn-primary sm:col-span-2 sm:justify-self-start">{t('common.save')}</button>}
                </form>
            )}
        </div>
    );
}
