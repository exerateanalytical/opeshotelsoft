import { FormEvent, useEffect, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { formatDate, formatDateTime } from '@/lib/utils';
import { errMsg, Field } from './shared';
import { M } from './coreShared';
import { downloadFile } from './ab3Shared';
import { C, CardTable, fetchBlobUrl, SectionTitle, yesNo } from './gapbShared';

interface SupInvoice { id: number; supplier_id: number; reference: string; date: string; due_date: string | null; total: number; status: string }
interface Supplier { id: number; name: string }
interface Step { id: number; step_no: number; role: string; label: string | null; status: string; decider: string | null; decided_at: string | null; note: string | null }
interface History { workflow: { id: number; status: string; chain_name?: string; created_at: string } | null; steps: Step[] }

/** Supplier invoices: submit for approval and approval history (accounting3/payables/invoices/{i}/submit, .../approvals). */
export function InvoiceApprovalsPanel({ suppliers, version, onChange }: { suppliers: Supplier[]; version: number; onChange: () => void }) {
    const t = useT();
    const can = useCan();
    const [status, setStatus] = useState('pending');
    const invoices = useApi<{ data: SupInvoice[] }>(`payables/invoices${status ? `?status=${status}` : ''}`, [status, version]);
    const [hist, setHist] = useState<SupInvoice | null>(null);
    const history = useApi<History>(hist ? `accounting3/payables/invoices/${hist.id}/approvals` : null, [hist?.id, version]);
    const [busy, setBusy] = useState<number | null>(null);
    const name = (id: number) => suppliers.find((s) => s.id === id)?.name ?? `#${id}`;
    const rows = invoices.data?.data ?? [];

    const submit = async (i: SupInvoice) => {
        setBusy(i.id);
        try {
            await api(`accounting3/payables/invoices/${i.id}/submit`, { method: 'POST' });
            toast.success(t('gapb.inv.submitted'));
            invoices.reload(); onChange();
            setHist(i);
        } catch (e) { toast.error(errMsg(e)); } finally { setBusy(null); }
    };

    return (
        <div className="card mb-4 p-4">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <SectionTitle hint={t('gapb.inv.hint')}>{t('gapb.inv.title')}</SectionTitle>
                <Field label={t('acc3.status')}>
                    <select className="select" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="pending">{t('gapb.inv.st.pending')}</option>
                        <option value="approved">{t('gapb.inv.st.approved')}</option>
                        <option value="rejected">{t('gapb.inv.st.rejected')}</option>
                        <option value="">{t('gapb.all')}</option>
                    </select>
                </Field>
            </div>
            {invoices.error && <p className="text-sm text-red-700" role="alert">{invoices.error.message}</p>}
            {invoices.data && rows.length === 0 && <EmptyState title={t('gapb.inv.empty')} />}
            {rows.length > 0 && (
                <CardTable caption={t('gapb.inv.title')} head={[t('acc3.pay.supplier'), t('acc3.pay.reference'), t('acc3.date'), t('gapb.due'), t('acc3.amount'), t('acc3.status'), '']}>
                    {rows.map((i) => (
                        <tr key={i.id}>
                            <C label={t('acc3.pay.supplier')}>{name(i.supplier_id)}</C>
                            <C label={t('acc3.pay.reference')} mono>{i.reference}</C>
                            <C label={t('acc3.date')}>{formatDate(i.date)}</C>
                            <C label={t('gapb.due')}>{i.due_date ? formatDate(i.due_date) : '-'}</C>
                            <C label={t('acc3.amount')} r b>{M(i.total)}</C>
                            <C label={t('acc3.status')}>{t(`gapb.inv.st.${i.status}`)}</C>
                            <C label="" r>
                                <span className="btn-row justify-end">
                                    <button type="button" className="btn-secondary btn-sm" aria-label={`${t('gapb.inv.history')} ${i.reference}`} onClick={() => setHist(i)}>{t('gapb.inv.history')}</button>
                                    {i.status === 'pending' && can('payables.manage') && <button type="button" className="btn-primary btn-sm" disabled={busy === i.id} aria-label={`${t('gapb.inv.submit')} ${i.reference}`} onClick={() => submit(i)}>{t('gapb.inv.submit')}</button>}
                                </span>
                            </C>
                        </tr>
                    ))}
                </CardTable>
            )}
            <Modal open={!!hist} onClose={() => setHist(null)} size="lg" title={t('gapb.inv.history_title', { ref: hist?.reference ?? '' })} footer={<button type="button" className="btn-secondary" onClick={() => setHist(null)}>{t('gapb.close')}</button>}>
                {history.error && <p className="text-sm text-red-700" role="alert">{history.error.message}</p>}
                {history.data && !history.data.workflow && <EmptyState title={t('gapb.inv.no_workflow')} description={t('gapb.inv.no_workflow_hint')} />}
                {history.data?.workflow && (
                    <div className="grid gap-3">
                        <p className="text-sm">{t('gapb.inv.workflow_status', { status: t(`gapb.wf.${history.data.workflow.status}`) })}</p>
                        <ol className="grid gap-2">
                            {history.data.steps.map((s) => (
                                <li key={s.id} className="border border-slate-200 p-3 text-sm">
                                    <p className="font-semibold">{s.step_no}. {s.label ?? s.role}</p>
                                    <p>{t(`gapb.wf.${s.status}`)}{s.decider ? ` - ${s.decider}` : ''}{s.decided_at ? ` - ${formatDateTime(s.decided_at)}` : ''}</p>
                                    {s.note && <p className="text-slate-600">{s.note}</p>}
                                </li>
                            ))}
                        </ol>
                    </div>
                )}
            </Modal>
        </div>
    );
}

interface CaptureDetail { id: number; filename: string; status: string; engine: string | null; extracted: Record<string, unknown> | null; mime?: string | null }

/** Capture preview: the uploaded document (image inline, PDF via download) plus the extracted fields. */
export function CapturePreview({ id, onClose }: { id: number | null; onClose: () => void }) {
    const t = useT();
    const detail = useApi<CaptureDetail>(id ? `accounting3/payables/captures/${id}` : null, [id]);
    const [file, setFile] = useState<{ url: string; type: string } | null>(null);
    const [fileErr, setFileErr] = useState<string | null>(null);

    useEffect(() => {
        let url: string | null = null;
        setFile(null); setFileErr(null);
        if (!id) return;
        fetchBlobUrl(`accounting3/payables/captures/${id}/file`).then((f) => { url = f.url; setFile(f); }).catch((e) => setFileErr(errMsg(e)));
        return () => { if (url) URL.revokeObjectURL(url); };
    }, [id]);

    const d = detail.data;
    const fields = Object.entries(d?.extracted ?? {}).filter(([, v]) => v !== null && v !== '' && typeof v !== 'object');

    return (
        <Modal open={id !== null} onClose={onClose} size="xl" title={d ? t('gapb.cap.title', { file: d.filename }) : t('gapb.cap.title', { file: '' })}
            footer={<><button type="button" className="btn-secondary" onClick={onClose}>{t('gapb.close')}</button>{d && <button type="button" className="btn-primary" onClick={() => downloadFile(`accounting3/payables/captures/${d.id}/file`, d.filename).catch((e) => toast.error(errMsg(e)))}>{t('gapb.download')}</button>}</>}>
            <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                <div>
                    {fileErr && <p className="text-sm text-red-700" role="alert">{fileErr}</p>}
                    {file && file.type.startsWith('image/') && <img src={file.url} alt={d?.filename ?? ''} className="max-h-[60vh] w-full border border-slate-200 object-contain" />}
                    {file && file.type === 'application/pdf' && <iframe src={file.url} title={d?.filename ?? ''} className="h-[60vh] w-full border border-slate-200" />}
                    {file && !file.type.startsWith('image/') && file.type !== 'application/pdf' && <p className="border border-slate-200 p-3 text-sm text-slate-600">{t('gapb.cap.no_inline')}</p>}
                </div>
                <div>
                    <h3 className="mb-2 font-semibold">{t('gapb.cap.extracted')}</h3>
                    {d && <p className="mb-2 text-sm text-slate-600">{t(`acc3.pay.cap.${d.status}`)}{d.engine ? ` - ${d.engine}` : ''}</p>}
                    {fields.length === 0 ? <p className="text-sm text-slate-500">{t('gapb.cap.nothing')}</p> : (
                        <dl className="grid grid-cols-2 gap-2 text-sm">
                            {fields.map(([k, v]) => <div key={k} className="contents"><dt className="text-slate-500">{k}</dt><dd className="font-mono">{String(v)}</dd></div>)}
                        </dl>
                    )}
                </div>
            </div>
        </Modal>
    );
}

interface Chain { id: number; name: string; min_amount: number; max_amount: number | null; steps_list: { role: string; label: string | null }[]; active: boolean | number }

/** Edit an approval chain (PUT accounting3/payables/approval-chains/{id}). */
export function ChainEditModal({ chain, onClose, onSaved }: { chain: Chain | null; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    type F = { name: string; min_amount: string; max_amount: string; active: boolean; steps: { role: string; label: string }[] };
    const [f, setF] = useState<F | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setErr(null);
        setF(chain ? { name: chain.name, min_amount: String(chain.min_amount), max_amount: chain.max_amount == null ? '' : String(chain.max_amount), active: yesNo(chain.active), steps: chain.steps_list.map((s) => ({ role: s.role, label: s.label ?? '' })) } : null);
    }, [chain]);

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!chain || !f) return;
        setBusy(true); setErr(null);
        try {
            await api(`accounting3/payables/approval-chains/${chain.id}`, { method: 'PUT', body: JSON.stringify({ name: f.name, min_amount: Number(f.min_amount) || 0, max_amount: f.max_amount === '' ? null : Number(f.max_amount), active: f.active, steps: f.steps.filter((s) => s.role.trim()).map((s) => ({ role: s.role.trim(), label: s.label || null })) }) });
            toast.success(t('gapb.saved'));
            onSaved();
        } catch (x) { setErr(errMsg(x)); } finally { setBusy(false); }
    };
    const setStep = (i: number, p: Partial<{ role: string; label: string }>) => f && setF({ ...f, steps: f.steps.map((s, j) => (j === i ? { ...s, ...p } : s)) });

    return (
        <Modal open={!!chain} onClose={onClose} size="lg" title={t('gapb.chain.edit_title')} formId="chain-form"
            footer={<><button type="button" className="btn-secondary" onClick={onClose}>{t('gapb.cancel')}</button><button type="submit" form="chain-form" className="btn-primary" disabled={busy}>{t('common.save')}</button></>}>
            {f && (
                <form id="chain-form" onSubmit={save} className="grid gap-3">
                    {err && <p className="text-sm text-red-700" role="alert">{err}</p>}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                        <Field label={t('acc3.name')}><input required maxLength={120} className="input w-full" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
                        <Field label={t('acc3.pay.min_amount')}><input type="number" min={0} required className="input w-full" value={f.min_amount} onChange={(e) => setF({ ...f, min_amount: e.target.value })} /></Field>
                        <Field label={t('gapb.chain.max_amount')}><input type="number" min={0} className="input w-full" value={f.max_amount} onChange={(e) => setF({ ...f, max_amount: e.target.value })} /></Field>
                    </div>
                    <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} />{t('gapb.active')}</label>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-semibold">{t('acc3.pay.steps')}</legend>
                        {f.steps.map((s, i) => (
                            <div key={i} className="grid grid-cols-1 items-end gap-2 sm:grid-cols-[1fr_1fr_auto] [&>*]:min-w-0">
                                <Field label={t('gapb.chain.role', { n: i + 1 })}><input required className="input w-full font-mono" value={s.role} onChange={(e) => setStep(i, { role: e.target.value })} /></Field>
                                <Field label={t('gapb.label')}><input maxLength={120} className="input w-full" value={s.label} onChange={(e) => setStep(i, { label: e.target.value })} /></Field>
                                <button type="button" className="btn-secondary" disabled={f.steps.length <= 1} aria-label={t('gapb.chain.remove_step', { n: i + 1 })} onClick={() => setF({ ...f, steps: f.steps.filter((_, j) => j !== i) })}>{t('gapb.remove')}</button>
                            </div>
                        ))}
                        {f.steps.length < 6 && <button type="button" className="btn-secondary justify-self-start" onClick={() => setF({ ...f, steps: [...f.steps, { role: '', label: '' }] })}>{t('gapb.chain.add_step')}</button>}
                    </fieldset>
                </form>
            )}
        </Modal>
    );
}
