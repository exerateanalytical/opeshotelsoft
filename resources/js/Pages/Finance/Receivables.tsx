import { useState } from 'react';
import { X } from 'lucide-react';
import { api, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Badge, Banner, errMsg, Field, Invoice, Loading, Page } from './shared';
import { useT } from '@/lib/i18n';
import PrintPdfButton from '@/Components/PrintPdfButton';

interface Statement {
    company: { id: number; name: string; tax_id: string | null; credit_limit: number | null; payment_terms_days: number | null };
    invoices: Pick<Invoice, 'id' | 'number' | 'issue_date' | 'due_date' | 'total' | 'paid' | 'status'>[];
    balance: number;
}
interface PaymentMethod { id: number; code: string; name: string; kind: string; active: boolean }
interface Company { id: number; name: string; tax_id: string | null }
const rowsOf = <T,>(d: T[] | { data: T[] } | null | undefined): T[] => (Array.isArray(d) ? d : d?.data ?? []);

export default function Receivables() {
    const t = useT();
    const [status, setStatus] = useState('');
    const [company, setCompany] = useState<Company | null>(null);
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [statementFor, setStatementFor] = useState<number | null>(null);
    const [paying, setPaying] = useState<Invoice | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    const p = new URLSearchParams({ page: String(page) });
    if (status) p.set('status', status);
    if (company) p.set('company_id', String(company.id));
    const list = useApi<Page<Invoice>>(`invoices?${p}`);
    const rows = (list.data?.data ?? []).filter((i) => !q || i.number.toLowerCase().includes(q.toLowerCase()) || i.bill_to_name.toLowerCase().includes(q.toLowerCase()));

    return (
        <div>
            <Banner error={list.error?.message} success={ok} />
            <div className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:flex sm:items-end [&>*]:min-w-0">
                <input className="input sm:col-span-2 w-full sm:w-56" placeholder={t('fin.recv.search')} aria-label={t('fin.recv.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                <select className="input" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label={t('fin.all_statuses')}>
                    <option value="">{t('fin.all_statuses')}</option>
                    {['issued', 'partially_paid', 'paid', 'cancelled'].map((s) => <option key={s} value={s}>{t(`fin.status.${s}`)}</option>)}
                </select>
                <CompanyPicker value={company} onChange={(c) => { setCompany(c); setPage(1); }} />
            </div>
            {statementFor && <StatementPanel id={statementFor} onClose={() => setStatementFor(null)} />}
            {paying && (
                <PayForm inv={paying} onClose={() => setPaying(null)}
                    onDone={(m) => { setPaying(null); setOk(m); list.reload(); }} />
            )}
            <div className="card overflow-x-auto">
                <Loading on={list.loading} />
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.col.number')}</th><th className="p-2">{t('fin.recv.bill_to')}</th><th className="p-2">{t('fin.recv.issued')}</th><th className="p-2">{t('fin.recv.due')}</th><th className="p-2 text-right">{t('fin.col.total')}</th><th className="p-2 text-right">{t('fin.col.balance')}</th><th className="p-2">{t('fin.col.status')}</th><th /></tr></thead>
                    <tbody>
                        {rows.map((i) => (
                            <tr key={i.id} className="border-t border-slate-100">
                                <td className="p-2 font-mono">{i.number}{i.kind === 'credit_note' && <span className="ml-1 text-xs text-amber-700">{t('fin.recv.cn')}</span>}</td>
                                <td className="p-2">{i.bill_to_name}</td>
                                <td className="p-2 whitespace-nowrap">{formatDate(i.issue_date)}</td>
                                <td className="p-2 whitespace-nowrap">{i.due_date ? formatDate(i.due_date) : '—'}</td>
                                <td className="p-2 text-right whitespace-nowrap">{formatCurrency(i.total)}</td>
                                <td className="p-2 text-right whitespace-nowrap">{formatCurrency(i.total - i.paid)}</td>
                                <td className="p-2"><Badge s={i.status} /></td>
                                <td className="p-2 whitespace-nowrap text-right">
                                    {i.company_id && ['issued', 'partially_paid'].includes(i.status) && [1, 2, 3].map((n) => <PrintPdfButton key={n} path={`reminder/${i.id}?level=${n}`} label={`R${n}`} className="btn-secondary mr-1 inline-flex items-center gap-1 btn-sm" />)}
                                    {i.company_id && <button className="btn-secondary mr-1 btn-sm" onClick={() => setStatementFor(i.company_id)}>{t('fin.recv.statement')}</button>}
                                    {i.company_id && ['issued', 'partially_paid'].includes(i.status) && <button className="btn-primary btn-sm" onClick={() => setPaying(i)}>{t('fin.recv.payment')}</button>}
                                </td>
                            </tr>
                        ))}
                        {list.data && rows.length === 0 && <tr><td colSpan={8} className="p-6 text-center text-slate-500">{t('fin.recv.empty')}</td></tr>}
                    </tbody>
                </table>
            </div>
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center justify-end gap-2 text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                    <span>{list.data.current_page} / {list.data.last_page}</span>
                    <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                </div>
            )}
        </div>
    );
}

function CompanyPicker({ value, onChange }: { value: Company | null; onChange: (c: Company | null) => void }) {
    const t = useT();
    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const found = useApi<Company[] | Page<Company>>(open && term.trim().length >= 2 ? `companies?q=${encodeURIComponent(term.trim())}` : null);
    if (value) {
        return (
            <span className="input sm:col-span-2 flex items-center justify-between gap-2 sm:w-56">
                <span className="truncate">{value.name}</span>
                <button type="button" aria-label={t('fin.recv.clear_company')} className="text-slate-500" onClick={() => onChange(null)}><X className="h-4 w-4" /></button>
            </span>
        );
    }
    const options = rowsOf(found.data);
    return (
        <div className="relative sm:col-span-2 sm:w-56">
            <input className="input w-full" placeholder={t('fin.recv.company_ph')} aria-label={t('fin.recv.company_ph')} value={term}
                onChange={(e) => { setTerm(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)} onBlur={() => setTimeout(() => setOpen(false), 150)} />
            {open && term.trim().length >= 2 && (
                <ul className="card absolute z-10 mt-1 max-h-60 w-full overflow-y-auto text-sm">
                    {found.loading && <li className="p-2 text-slate-500">{t('fin.searching')}</li>}
                    {!found.loading && options.length === 0 && <li className="p-2 text-slate-500">{t('fin.recv.no_company')}</li>}
                    {options.map((c) => (
                        <li key={c.id}>
                            <button type="button" className="w-full p-2 text-left hover:bg-slate-50" onMouseDown={(e) => e.preventDefault()}
                                onClick={() => { onChange(c); setTerm(''); setOpen(false); }}>
                                {c.name}{c.tax_id && <span className="ml-1 text-xs text-slate-500">{c.tax_id}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function StatementPanel({ id, onClose }: { id: number; onClose: () => void }) {
    const t = useT();
    const s = useApi<Statement>(`receivables/companies/${id}/statement`);
    const d = s.data;
    return (
        <div className="card mb-4 p-4">
            <div className="mb-2 flex items-start justify-between">
                <div>
                    <h3 className="font-semibold">{d?.company.name ?? t('fin.recv.statement')}</h3>
                    {d && <p className="text-xs text-slate-500">{t('fin.recv.company_meta', { niu: d.company.tax_id ?? '—', days: d.company.payment_terms_days ?? '—', limit: d.company.credit_limit != null ? formatCurrency(d.company.credit_limit) : '—' })}</p>}
                </div>
                <PrintPdfButton path={`company-statement/${id}`} label={t('documents3.print_statement')} />
                <button onClick={onClose} className="text-slate-500"><X className="h-5 w-5" /></button>
            </div>
            <Banner error={s.error?.message} />
            <Loading on={s.loading} />
            {d && (
                <>
                    <p className="mb-2 text-sm">{t('fin.recv.city_ledger_balance')} <span className="font-semibold">{formatCurrency(d.balance)}</span></p>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.recv.invoice')}</th><th className="p-2">{t('fin.recv.issued')}</th><th className="p-2">{t('fin.recv.due')}</th><th className="p-2 text-right">{t('fin.col.total')}</th><th className="p-2 text-right">{t('fin.recv.paid')}</th><th className="p-2">{t('fin.col.status')}</th></tr></thead>
                            <tbody>
                                {d.invoices.map((i) => (
                                    <tr key={i.id} className="border-t border-slate-100">
                                        <td className="p-2 font-mono">{i.number}</td><td className="p-2">{formatDate(i.issue_date)}</td><td className="p-2">{i.due_date ? formatDate(i.due_date) : '—'}</td>
                                        <td className="p-2 text-right">{formatCurrency(i.total)}</td><td className="p-2 text-right">{formatCurrency(i.paid)}</td><td className="p-2"><Badge s={i.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </div>
    );
}

function PayForm({ inv, onClose, onDone }: { inv: Invoice; onClose: () => void; onDone: (m: string) => void }) {
    const t = useT();
    const due = inv.total - inv.paid;
    const [amount, setAmount] = useState(String(due));
    const pm = useApi<PaymentMethod[]>('setup/payment-methods');
    const methods = rowsOf(pm.data).filter((m) => m.active && m.kind !== 'city_ledger');
    const [picked, setMethod] = useState('');
    const method = picked || methods.find((m) => m.kind === 'bank_transfer')?.code || methods[0]?.code || '';
    const [reference, setReference] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        const n = parseInt(amount, 10);
        if (!n || n < 1 || n > due) { setError(t('fin.recv.amount_range', { max: formatCurrency(due) })); return; }
        setBusy(true); setError(null);
        try {
            await api(`receivables/invoices/${inv.id}/payments`, { method: 'POST', body: JSON.stringify({ amount: n, method, reference: reference || null }) });
            onDone(t('fin.recv.payment_recorded', { amount: formatCurrency(n), number: inv.number }));
        } catch (err) { setError(errMsg(err)); } finally { setBusy(false); }
    }

    return (
        <form onSubmit={submit} className="card mb-4 p-4">
            <div className="mb-2 flex items-center justify-between">
                <h3 className="font-semibold">{t('fin.recv.record_payment', { number: inv.number, name: inv.bill_to_name })}</h3>
                <button type="button" onClick={onClose} className="text-slate-500"><X className="h-5 w-5" /></button>
            </div>
            <Banner error={error ?? pm.error?.message} />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 sm:items-end [&>*]:min-w-0">
                <Field label={t('fin.recv.amount_due', { amount: formatCurrency(due) })}><input type="number" min={1} max={due} className="input w-full" required value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
                <Field label={t('fin.recv.method')}><select className="input w-full" value={method} onChange={(e) => setMethod(e.target.value)}>{methods.map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}</select></Field>
                <Field label={t('fin.col.reference')}><input className="input w-full" value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
                <button className="btn-primary" disabled={busy || !method}>{t('fin.recv.record')}</button>
            </div>
        </form>
    );
}
