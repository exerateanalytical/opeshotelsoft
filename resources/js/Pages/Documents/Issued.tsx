import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { activeHotelId, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';
import Alert from '@/Components/ui/Alert';
import LoadingState from '@/Components/ui/LoadingState';
import { useSplitView } from '@/Components/ui/MasterDetail';

interface Doc {
    id: number; type: string; number: string; source_type: string | null; source_id: number | null; issued_at: string;
    amount: number | null; currency: string | null; status: string; status_reason: string | null; code: string; copy_count: number;
}
interface Reprint { id: number; copy_number: number; printed_by: number | null; printed_at: string; ip: string | null }
interface Detail extends Doc { reprints: Reprint[]; verify_path: string }
interface Paged<T> { data: T[]; total: number; current_page: number; last_page: number }

// Same kind lists as the server (MoneyController::KINDS, OpsController::KINDS); the PDF route rebuilds and logs a copy.
const MONEY = ['cancellation', 'payment-receipt', 'deposit-receipt', 'payment-receipt-pos', 'payment-receipt-online', 'payment-receipt-link', 'proforma', 'express-checkout',
    'credit-note', 'refund-voucher', 'guest-statement', 'room-move', 'pos-receipt', 'shift-report', 'void-slip', 'void-slip-pos', 'company-statement', 'reminder',
    'journal-voucher', 'supplier-payment', 'bank-reconciliation', 'asset-register', 'depreciation-schedule', 'budget-variance', 'daily-report'];
const OPS = ['group-contract', 'rooming-list', 'police-register', 'lost-found-handin', 'lost-found-return', 'kot', 'pos-day-report', 'goods-receipt', 'requisition',
    'stock-issue', 'stock-transfer', 'supplier-return', 'stocktake-sheet', 'stocktake-variance', 'stock-valuation', 'rfq', 'offer-letter', 'employment-attestation',
    'salary-certificate', 'raise-letter', 'transfer-letter', 'warning-letter', 'termination-letter', 'final-settlement', 'leave-slip', 'staff-id-card', 'payroll-journal'];
// Only these ops kinds carry a document type the server can regenerate (OpsController::kinds()).
const REGEN = ['offer-letter', 'employment-attestation', 'salary-certificate', 'raise-letter', 'transfer-letter', 'warning-letter', 'termination-letter'];
const BASE: Record<string, string> = { invoice: 'invoices', folio: 'folios', 'registration-card': 'registration-cards', payslip: 'payslips', 'purchase-order': 'purchase-orders' };
const STATUSES = ['valid', 'voided', 'replaced'];

const kindOf = (d: Doc) => d.type.replace(/_/g, '-');
function pdfPath(d: Doc): string | null {
    const k = kindOf(d);
    if (!d.source_id) return null;
    if (MONEY.includes(k)) return `documents/money/${k}/${d.source_id}`;
    if (OPS.includes(k)) return `documents/ops/${k}/${d.source_id}`;
    if (BASE[k]) return `documents/${BASE[k]}/${d.source_id}`;
    return null;
}
function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}
async function openPdf(path: string, init: RequestInit = {}) {
    const hotel = activeHotelId();
    const res = await fetch(`/api/v1/${path}`, {
        credentials: 'same-origin', ...init,
        headers: { Accept: 'application/pdf', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
    });
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new ApiError({ status: res.status, title: res.statusText, ...body });
    }
    const url = URL.createObjectURL(await res.blob());
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** Register of issued (numbered, QR-verifiable) documents: search, reprint, verify log, regenerate HR letters. Needs documents.verify.log. */
export default function Issued() {
    const t = useT();
    const can = useCan();
    const wide = useSplitView();
    const [q, setQ] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [nonce, setNonce] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [logId, setLogId] = useState<number | null>(null);
    const [regen, setRegen] = useState<{ doc: Doc; reason: string } | null>(null);

    const params = new URLSearchParams({ page: String(page), per_page: '25' });
    if (q.trim()) params.set('q', q.trim());
    if (type.trim()) params.set('type', type.trim());
    if (status) params.set('status', status);
    const list = useApi<Paged<Doc>>(`documents/issued?${params.toString()}`, [nonce]);
    const detail = useApi<Detail>(logId ? `documents/issued/${logId}` : null, [logId]);
    const rows = list.data?.data ?? [];

    const reprint = async (d: Doc) => {
        const p = pdfPath(d);
        setError(null); setOk(null);
        if (!p) { setError(t('ms1.issued.no_pdf')); return; }
        try { await openPdf(p); setNonce((n) => n + 1); } catch (e) { setError(e instanceof Error ? e.message : String(e)); }
    };

    return (
        <AppLayout title={t('ms1.issued.title')} subtitle={t('ms1.issued.subtitle')}>
            <Head title={t('ms1.issued.title')} />
            <FilterBar
                activeCount={[type, status].filter(Boolean).length}
                onClear={() => { setType(''); setStatus(''); setPage(1); }}
                search={
                    <div className="relative min-w-0 flex-1 sm:w-72 sm:flex-none">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                        <input type="search" className="input pl-9" aria-label={t('ms1.issued.search')} placeholder={t('ms1.issued.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                }
            >
                <Field label={t('ms1.issued.kind')}>
                    <input className="input sm:w-48" placeholder="invoice, payment_receipt..." value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} />
                </Field>
                <Field label={t('ms1.issued.status')}>
                    <select className="select sm:w-40" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                        <option value="">{t('ms1.issued.all')}</option>
                        {STATUSES.map((s) => <option key={s} value={s}>{t(`ms1.issued.st.${s}`)}</option>)}
                    </select>
                </Field>
            </FilterBar>

            {error && <Alert variant="danger" className="mb-4" onDismiss={() => setError(null)}>{error}</Alert>}
            {ok && <Alert variant="success" className="mb-4" onDismiss={() => setOk(null)}>{ok}</Alert>}

            <DataTable
                caption={t('ms1.issued.title')}
                tableKey="issued-documents"
                loading={list.loading && !list.data}
                error={list.error ? list.error.message : undefined}
                rows={rows}
                rowKey={(d) => d.id}
                rowLabel={(d) => d.number}
                onRowClick={(d) => setLogId(d.id)}
                empty={<EmptyState title={t('ms1.issued.empty')} />}
                rowActions={() => [
                    { key: 'open', label: t('ms1.issued.open'), onSelect: (d) => window.open(`/verify/${d.code}`, '_blank', 'noopener') },
                    { key: 'reprint', label: t('ms1.issued.reprint'), onSelect: (d) => { void reprint(d); } },
                    { key: 'log', label: t('ms1.issued.log'), onSelect: (d) => setLogId(d.id) },
                    ...(can('documents.ops.regenerate') ? [{ key: 'regen', label: t('ms1.issued.regenerate'), onSelect: (d: Doc) => setRegen({ doc: d, reason: '' }) }] : []),
                ]}
                columns={[
                    { key: 'type', header: t('ms1.issued.kind'), sortValue: (d) => d.type, cell: (d) => d.type.replace(/_/g, ' ') },
                    { key: 'number', header: t('ms1.issued.number'), sortValue: (d) => d.number, cell: (d) => <span className="font-mono text-sm">{d.number}</span> },
                    { key: 'date', header: t('ms1.issued.date'), sortValue: (d) => d.issued_at, cell: (d) => formatDate(d.issued_at) },
                    { key: 'amount', header: t('ms1.issued.amount'), align: 'right', sortValue: (d) => d.amount ?? 0, cell: (d) => (d.amount !== null ? formatCurrency(d.amount, d.currency ?? 'XAF') : '') },
                    { key: 'status', header: t('ms1.issued.status'), sortValue: (d) => d.status, cell: (d) => <span className="rounded border border-slate-300 px-2 py-0.5 text-xs">{t(`ms1.issued.st.${d.status}`)}</span> },
                    { key: 'code', header: t('ms1.issued.code'), sortValue: (d) => d.code, cell: (d) => <span className="font-mono text-xs">{d.code}</span> },
                    { key: 'copies', header: t('ms1.issued.copies'), align: 'right', sortValue: (d) => d.copy_count, cell: (d) => d.copy_count },
                ]}
            />
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center justify-end gap-2 text-sm">
                    <button type="button" className="btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('ms1.prev')}</button>
                    <span>{list.data.current_page} / {list.data.last_page}</span>
                    <button type="button" className="btn-secondary btn-sm" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('ms1.next')}</button>
                </div>
            )}

            {logId && (
                <Modal open size="lg" variant={wide ? 'dialog' : 'sheet'} title={t('ms1.issued.log_title', { number: detail.data?.number ?? '' })} onClose={() => setLogId(null)}>
                    {detail.loading && !detail.data && <LoadingState />}
                    {detail.error && <Alert variant="danger">{detail.error.message}</Alert>}
                    {detail.data && (
                        <div className="space-y-3 text-sm">
                            <p>{t('ms1.issued.code')}: <a className="font-mono underline" href={detail.data.verify_path} target="_blank" rel="noreferrer">{detail.data.code}</a></p>
                            {detail.data.status_reason && <p>{t('ms1.issued.reason')}: {detail.data.status_reason}</p>}
                            {detail.data.reprints.length === 0 ? <p className="text-slate-600">{t('ms1.issued.no_reprints')}</p> : (
                                <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200">
                                    {detail.data.reprints.map((r) => <li key={r.id} className="flex justify-between gap-2 p-3"><span>{t('ms1.issued.copy_n', { n: r.copy_number })}</span><span className="text-slate-600">{formatDateTime(r.printed_at)}{r.ip ? ` · ${r.ip}` : ''}</span></li>)}
                                </ul>
                            )}
                        </div>
                    )}
                </Modal>
            )}

            {regen && (
                <Modal open variant={wide ? 'dialog' : 'sheet'} title={t('ms1.issued.regenerate')} onClose={() => setRegen(null)} formId="regen-form"
                    footer={<><button type="button" className="btn-secondary" onClick={() => setRegen(null)}>{t('common.cancel')}</button><button type="submit" form="regen-form" className="btn-primary">{t('ms1.issued.regenerate')}</button></>}>
                    <form id="regen-form" className="space-y-3" onSubmit={async (e) => {
                        e.preventDefault();
                        const k = kindOf(regen.doc);
                        if (!REGEN.includes(k) || !regen.doc.source_id) { setError(t('ms1.issued.no_regen')); setRegen(null); return; }
                        try {
                            await openPdf(`documents/ops/${k}/${regen.doc.source_id}/regenerate`, { method: 'POST', body: JSON.stringify({ reason: regen.reason }) });
                            setOk(t('ms1.issued.regenerated')); setRegen(null); setNonce((n) => n + 1);
                        } catch (err) { setError(err instanceof Error ? err.message : String(err)); setRegen(null); }
                    }}>
                        {!REGEN.includes(kindOf(regen.doc)) && <Alert variant="warning">{t('ms1.issued.no_regen')}</Alert>}
                        <Field label={t('ms1.issued.reason')}>
                            <textarea className="input" required minLength={3} maxLength={255} rows={3} value={regen.reason} onChange={(e) => setRegen({ ...regen, reason: e.target.value })} />
                        </Field>
                    </form>
                </Modal>
            )}
        </AppLayout>
    );
}
