import { Link } from '@inertiajs/react';
import { Loader2, Pencil, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import SharedField from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';

export interface Company {
    id: number;
    kind: string;
    name: string;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    country: string | null;
    contact_name: string | null;
    commission_percent: number | string | null;
    city_ledger: boolean;
    credit_limit: number | null;
    payment_terms_days: number | null;
    active: boolean;
}

interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }

const KINDS: [string, string][] = [
    ['corporate', 'guests.kind.corporate'], ['travel_agent', 'guests.kind.travel_agent'], ['ota', 'guests.kind.ota'], ['government', 'guests.kind.government'], ['ngo', 'guests.kind.ngo'],
];

const EMPTY = {
    kind: 'corporate', name: '', tax_id: '', email: '', phone: '', address: '', country: '', contact_name: '',
    commission_percent: '0', city_ledger: false, credit_limit: '0', payment_terms_days: '30', active: true,
};
type Form = typeof EMPTY;

function toForm(c: Company): Form {
    return {
        kind: c.kind, name: c.name, tax_id: c.tax_id ?? '', email: c.email ?? '', phone: c.phone ?? '', address: c.address ?? '',
        country: c.country ?? '', contact_name: c.contact_name ?? '', commission_percent: String(c.commission_percent ?? 0),
        city_ledger: !!c.city_ledger, credit_limit: String(c.credit_limit ?? 0), payment_terms_days: String(c.payment_terms_days ?? 30), active: !!c.active,
    };
}

export default function CompaniesTab() {
    const t = useT();
    const [q, setQ] = useState('');
    const [kind, setKind] = useState('');
    const [ledgerOnly, setLedgerOnly] = useState(false);
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState<Company | 'new' | null>(null);
    const [form, setForm] = useState<Form>(EMPTY);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    const params = new URLSearchParams({ page: String(page), per_page: '25', sort: 'name' });
    if (q) params.set('q', q);
    if (kind) params.set('filter[kind]', kind);
    if (ledgerOnly) params.set('filter[city_ledger]', '1');
    const list = useApi<Page<Company>>(`companies?${params}`);

    function open(c: Company | 'new') {
        setEditing(c);
        setForm(c === 'new' ? EMPTY : toForm(c));
        setError(null);
        setOk(null);
    }

    const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));

    async function save(e: React.FormEvent) {
        e.preventDefault();
        setSaving(true);
        setError(null);
        const body = {
            ...form,
            tax_id: form.tax_id || null, email: form.email || null, phone: form.phone || null, address: form.address || null,
            country: form.country ? form.country.toUpperCase() : null, contact_name: form.contact_name || null,
            commission_percent: Number(form.commission_percent || 0), credit_limit: Math.round(Number(form.credit_limit || 0)),
            payment_terms_days: Math.round(Number(form.payment_terms_days || 0)),
        };
        try {
            if (editing === 'new') await api('companies', { method: 'POST', body: JSON.stringify(body) });
            else if (editing) await api(`companies/${editing.id}`, { method: 'PUT', body: JSON.stringify(body) });
            setOk(editing === 'new' ? t('guests.companies.created') : t('guests.companies.updated'));
            setEditing(null);
            list.reload();
        } catch (err) {
            setError(err instanceof ApiError ? err.message : String(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-4">
            {ok && <div className="rounded-lg bg-emerald-50 text-emerald-700 px-4 py-2 text-sm">{ok}</div>}
            {error && <div className="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm">{error}</div>}

            <div className="flex justify-end">
                <button type="button" className="btn-primary btn-lg w-full sm:w-auto" onClick={() => open('new')}><Plus className="w-4 h-4" aria-hidden="true" /> {t('guests.companies.new')}</button>
            </div>
            <FilterBar
                activeCount={(kind ? 1 : 0) + (ledgerOnly ? 1 : 0)}
                onClear={() => { setKind(''); setLedgerOnly(false); setPage(1); }}
                search={
                    <div className="relative min-w-0 flex-1 sm:w-80 sm:flex-none">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                        <input type="search" className="input pl-9" aria-label={t('guests.companies.search')} placeholder={t('guests.companies.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                }
            >
                <Field label={t('guests.companies.kind')}>
                    <select className="select sm:w-48" value={kind} onChange={(e) => { setKind(e.target.value); setPage(1); }}>
                        <option value="">{t('guests.companies.all_kinds')}</option>
                        {KINDS.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}
                    </select>
                </Field>
                <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={ledgerOnly} onChange={(e) => { setLedgerOnly(e.target.checked); setPage(1); }} /> {t('guests.companies.ledger_only')}
                </label>
            </FilterBar>

            <Modal open={!!editing} size="xl" onClose={() => setEditing(null)}
                title={editing === 'new' ? t('guests.companies.new') : editing ? t('guests.companies.edit_title', { name: editing.name }) : ''}
                footer={<>
                    <button type="button" className="btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
                    <button type="submit" form="company-form" className="btn-primary" disabled={saving}>{saving && <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />} {t('common.save')}</button>
                </>}>
                {editing && (
                <form id="company-form" onSubmit={save} className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <Field label={t('guests.companies.name_required')}><input className="input w-full" required value={form.name} onChange={(e) => set('name', e.target.value)} /></Field>
                        <Field label={t('guests.companies.kind')}>
                            <select className="select w-full" value={form.kind} onChange={(e) => set('kind', e.target.value)}>
                                {KINDS.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('guests.companies.tax_id')}><input className="input w-full" value={form.tax_id} onChange={(e) => set('tax_id', e.target.value)} /></Field>
                        <Field label={t('guests.companies.contact_name')}><input className="input w-full" value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} /></Field>
                        <Field label={t('guests.field.email')}><input type="email" className="input w-full" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
                        <Field label={t('guests.field.phone')}><input className="input w-full" value={form.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
                        <Field label={t('guests.field.address')}><input className="input w-full" value={form.address} onChange={(e) => set('address', e.target.value)} /></Field>
                        <Field label={t('guests.field.country_iso')}><input className="input w-full" maxLength={2} value={form.country} onChange={(e) => set('country', e.target.value)} /></Field>
                        <Field label={t('guests.companies.commission')}><input type="number" min={0} max={50} step="0.01" className="input w-full" value={form.commission_percent} onChange={(e) => set('commission_percent', e.target.value)} /></Field>
                        <Field label={t('guests.companies.credit_limit_fcfa')}><input type="number" min={0} className="input w-full" value={form.credit_limit} onChange={(e) => set('credit_limit', e.target.value)} /></Field>
                        <Field label={t('guests.companies.terms_days')}><input type="number" min={0} max={180} className="input w-full" value={form.payment_terms_days} onChange={(e) => set('payment_terms_days', e.target.value)} /></Field>
                        <div className="flex flex-col gap-2 justify-end text-sm text-slate-700">
                            <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={form.city_ledger} onChange={(e) => set('city_ledger', e.target.checked)} /> {t('guests.companies.city_ledger_hint')}</label>
                            <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={form.active} onChange={(e) => set('active', e.target.checked)} /> {t('guests.status.active')}</label>
                        </div>
                    </div>
                </form>
                )}
            </Modal>

            <div className="card overflow-hidden">
                {list.error && <div className="bg-red-50 text-red-700 px-4 py-2 text-sm">{list.error.message}</div>}
                <div className="table-wrap"><table className="table-auto">
                    <thead>
                        <tr>
                            <th className="px-4 py-2">{t('guests.companies.name')}</th><th className="px-4 py-2">{t('guests.companies.kind')}</th><th className="px-4 py-2">{t('guests.companies.contact')}</th>
                            <th className="px-4 py-2">{t('guests.companies.city_ledger')}</th><th className="px-4 py-2 text-right">{t('guests.companies.credit_limit')}</th><th className="px-4 py-2">{t('guests.companies.terms')}</th>
                            <th className="px-4 py-2">{t('guests.status')}</th><th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.loading && !list.data && <tr><td colSpan={8} className="px-4 py-6 text-center text-slate-500">{t('common.loading')}</td></tr>}
                        {list.data?.data.length === 0 && <tr><td colSpan={8} className="px-4 py-6 text-center text-slate-500">{t('guests.companies.empty')}</td></tr>}
                        {list.data?.data.map((c) => (
                            <tr key={c.id}>
                                <td className="px-4 py-2 font-medium text-slate-800"><Link href={`/guests/companies/${c.id}`} className="text-navy-600 underline-offset-2 hover:underline">{c.name}</Link><div className="text-xs text-slate-500">{c.tax_id}</div></td>
                                <td className="px-4 py-2">{(() => { const l = KINDS.find(([v]) => v === c.kind)?.[1]; return l ? t(l) : c.kind; })()}</td>
                                <td className="px-4 py-2">{c.contact_name}<div className="text-xs text-slate-500">{c.email ?? c.phone}</div></td>
                                <td className="px-4 py-2">{c.city_ledger ? <span className="px-2 py-0.5 rounded border border-emerald-300 bg-emerald-50 text-emerald-800 text-xs">{t('guests.yes')}</span> : <span className="text-slate-500">{t('guests.no')}</span>}</td>
                                <td className="px-4 py-2 text-right">{formatCurrency(c.credit_limit ?? 0)}</td>
                                <td className="px-4 py-2">{t('guests.companies.days_short', { count: c.payment_terms_days ?? 0 })}</td>
                                <td className="px-4 py-2">{c.active ? t('guests.status.active') : <span className="text-amber-800">{t('guests.status.inactive')}</span>}</td>
                                <td className="px-4 py-2 text-right"><button type="button" className="btn-secondary btn-sm" onClick={() => open(c)}><Pencil className="w-4 h-4" aria-hidden="true" /> {t('common.edit')}</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table></div>
                {list.data && list.data.last_page > 1 && (
                    <div className="flex justify-between items-center px-4 py-2 text-sm text-slate-500">
                        <span>{t('guests.companies.count', { count: list.data.total })}</span>
                        <div className="flex gap-2">
                            <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                            <span>{page} / {list.data.last_page}</span>
                            <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export function Field({ label, children, className }: { label: string; children: React.ReactElement<Record<string, unknown>>; className?: string }) {
    return <SharedField label={label} className={className}>{children}</SharedField>;
}
