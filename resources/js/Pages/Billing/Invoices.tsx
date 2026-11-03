import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Loader2, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Invoice {
    id: number;
    number: string;
    company_id: number | null;
    bill_to_name: string;
    issue_date: string;
    due_date: string | null;
    total: number;
    tax_total: number;
    paid: number;
    status: string;
}
interface Page<T> { data: T[]; total?: number }
interface Company { id: number; name: string }

const STATUSES = ['issued', 'partially_paid', 'paid', 'cancelled'];

const statusClass = (s: string) =>
    s === 'paid' ? 'bg-emerald-50 text-emerald-700' : s === 'partially_paid' ? 'bg-amber-50 text-amber-700' : s === 'cancelled' ? 'bg-slate-100 text-slate-500' : 'bg-sky-50 text-sky-700';

export default function Invoices() {
    const t = useT();
    const stLabel = (s: string) => (t(`billing.inv_status.${s}`) === `billing.inv_status.${s}` ? s.replace('_', ' ') : t(`billing.inv_status.${s}`));
    const [status, setStatus] = useState('');
    const [company, setCompany] = useState('');
    const [q, setQ] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');

    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (company) params.set('company_id', company);
    if (q.trim()) params.set('q', q.trim());
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    const invoices = useApi<Page<Invoice>>(`invoices?${params.toString()}`);
    const companies = useApi<Page<Company>>('companies?per_page=200');

    // The API may not yet honour every filter, so apply them on the client too.
    const rows = useMemo(() => {
        const term = q.trim().toLowerCase();
        return (invoices.data?.data ?? []).filter((i) =>
            (!status || i.status === status) &&
            (!company || String(i.company_id) === company) &&
            (!term || i.number.toLowerCase().includes(term) || (i.bill_to_name ?? '').toLowerCase().includes(term)) &&
            (!from || i.issue_date.slice(0, 10) >= from) &&
            (!to || i.issue_date.slice(0, 10) <= to));
    }, [invoices.data, status, company, q, from, to]);

    const totals = rows.reduce((a, i) => ({ total: a.total + i.total, due: a.due + (i.total - i.paid) }), { total: 0, due: 0 });

    return (
        <AppLayout title={t('billing.invoices.title')} subtitle={t('billing.invoices.subtitle')}>
            <Head title={t('billing.invoices.title')} />
            <FilterBar
                activeCount={[status, company, from, to].filter(Boolean).length}
                onClear={() => { setStatus(''); setCompany(''); setFrom(''); setTo(''); }}
                search={
                    <div className="relative min-w-0 flex-1 sm:w-72 sm:flex-none">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                        <input type="search" className="input pl-9" aria-label={t('billing.invoices.search')} placeholder={t('billing.invoices.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    </div>
                }
            >
                <Field label={t('billing.invoices.status')}>
                    <select className="select sm:w-48" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">{t('billing.folios.all_statuses')}</option>
                        {STATUSES.map((s) => <option key={s} value={s}>{stLabel(s)}</option>)}
                    </select>
                </Field>
                <Field label={t('billing.folios.all_companies')}>
                    <select className="select sm:w-52" value={company} onChange={(e) => setCompany(e.target.value)}>
                        <option value="">{t('billing.folios.all_companies')}</option>
                        {companies.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </Field>
                <Field label={t('billing.invoices.from')}><input className="input sm:w-40" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                <Field label={t('billing.invoices.to')}><input className="input sm:w-40" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            </FilterBar>

            {invoices.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{invoices.error.message}</div>}

            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th>{t('billing.invoices.number')}</th><th>{t('billing.invoices.bill_to')}</th><th>{t('billing.invoices.issued')}</th><th>{t('billing.invoices.due')}</th><th className="text-right">{t('billing.invoices.total')}</th><th className="text-right">{t('billing.invoices.outstanding')}</th><th>{t('billing.invoices.status')}</th></tr></thead>
                    <tbody>
                        {invoices.loading && !invoices.data && <tr><td colSpan={7} className="py-10"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-500" /></td></tr>}
                        {invoices.data && rows.length === 0 && <tr><td colSpan={7} className="text-center py-10 text-slate-500 text-sm">{t('billing.invoices.no_match')}</td></tr>}
                        {rows.map((i) => (
                            <tr key={i.id}>
                                <td><Link href={`/billing/invoices/${i.id}`} className="font-mono text-sm text-navy-600 underline underline-offset-2 inline-flex min-h-11 items-center">{i.number}</Link></td>
                                <td className="text-sm">{i.bill_to_name}{i.company_id && <span className="ml-2 text-xs text-slate-500">{t('billing.invoices.city_ledger')}</span>}</td>
                                <td className="text-sm">{formatDate(i.issue_date)}</td>
                                <td className="text-sm">{i.due_date ? formatDate(i.due_date) : '—'}</td>
                                <td className="text-right text-sm">{formatCurrency(i.total)}</td>
                                <td className={cn('text-right text-sm', i.total - i.paid > 0 && 'text-red-600')}>{formatCurrency(i.total - i.paid)}</td>
                                <td><span className={cn('text-xs px-2 py-0.5 rounded', statusClass(i.status))}>{stLabel(i.status)}</span></td>
                            </tr>
                        ))}
                    </tbody>
                    {rows.length > 0 && (
                        <tfoot><tr className="font-semibold"><td colSpan={4} className="text-sm">{t('billing.invoices.count', { count: rows.length })}</td><td className="text-right text-sm">{formatCurrency(totals.total)}</td><td className="text-right text-sm">{formatCurrency(totals.due)}</td><td /></tr></tfoot>
                    )}
                </table>
            </div>
        </AppLayout>
    );
}
