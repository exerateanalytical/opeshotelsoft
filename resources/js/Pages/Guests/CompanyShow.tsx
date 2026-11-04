import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Loader2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Contact { id: number; name: string; role: string | null; email: string | null; phone: string | null; primary: boolean }
interface Overview {
    company: { id: number; name: string; kind: string; tax_id: string | null; email: string | null; phone: string | null; city_ledger: boolean; credit_limit: number; payment_terms_days: number };
    contacts: Contact[];
    rate_plans: { id: number; code: string; name: string; meal_plan: string; active: boolean; hotel_name: string }[];
    bookings: { id: number; number: string; status: string; arrival: string; departure: string; total_amount: number; hotel_name: string; guest_name: string }[];
    statement_url: string | null;
}

const empty = { name: '', role: '', email: '', phone: '', primary: false };

export default function CompanyShow({ id }: { id: string }) {
    const t = useT();
    const o = useApi<Overview>(`companies/${id}/overview`);
    const [form, setForm] = useState<typeof empty | null>(null);
    const [confirmDel, setConfirmDel] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function call(path: string, method: string, body?: object) {
        setBusy(true);
        setError(null);
        try {
            await api(path, { method, body: body ? JSON.stringify(body) : undefined });
            o.reload();
            return true;
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
            return false;
        } finally {
            setBusy(false);
        }
    }

    const d = o.data;
    return (
        <AppLayout
            title={d?.company.name ?? t('guests.company')}
            subtitle={d ? [d.company.kind.replace('_', ' '), d.company.tax_id, d.company.city_ledger ? t('guests.company.ledger_terms', { count: d.company.payment_terms_days }) : null].filter(Boolean).join(' · ') : ''}
            actions={
                <div className="flex gap-2">
                    <Link href="/guests" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('guests.back')}</Link>
                    {d?.statement_url && <a className="btn-secondary" href={`/api/v1/${d.statement_url}`} target="_blank" rel="noreferrer"><FileText className="w-4 h-4" /> {t('guests.company.statement')}</a>}
                </div>
            }
        >
            <Head title={d?.company.name ?? t('guests.company')} />
            {(error || o.error) && <Alert variant="danger" className="mb-4">{error ?? o.error?.message}</Alert>}
            {o.loading && !d && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            {d && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div className="card p-4">
                        <div className="flex justify-between items-center mb-2">
                            <h3 className="font-semibold text-slate-800">{t('guests.company.contacts')}</h3>
                            {!form && <button className="btn-secondary" onClick={() => setForm(empty)}><Plus className="w-4 h-4" /> {t('common.add')}</button>}
                        </div>
                        {form && (
                            <form className="space-y-2 mb-3" onSubmit={(e) => { e.preventDefault(); call(`companies/${id}/contacts`, 'POST', { ...form, role: form.role || null, email: form.email || null, phone: form.phone || null }).then((ok) => ok && setForm(null)); }}>
                                <input className="input w-full" aria-label={t('guests.companies.name')} placeholder={t('guests.companies.name')} required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                                <input className="input w-full" aria-label={t('guests.company.role_ph')} placeholder={t('guests.company.role_ph')} value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} />
                                <input className="input w-full" type="email" aria-label={t('guests.field.email')} placeholder={t('guests.field.email')} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                                <input className="input w-full" aria-label={t('guests.field.phone')} placeholder={t('guests.field.phone')} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                                <label className="min-h-11 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.primary} onChange={(e) => setForm({ ...form, primary: e.target.checked })} /> {t('guests.company.primary_contact')}</label>
                                <div className="flex gap-2"><button className="btn-primary" disabled={busy}>{t('common.save')}</button><button type="button" className="btn-secondary" onClick={() => setForm(null)}>{t('common.cancel')}</button></div>
                            </form>
                        )}
                        {d.contacts.length === 0 && <p className="text-sm text-slate-500">{t('guests.company.no_contacts')}</p>}
                        {d.contacts.map((c) => (
                            <div key={c.id} className="border-t border-slate-100 py-2 text-sm flex justify-between gap-2">
                                <div>
                                    <div className="font-medium text-slate-800">{c.name} {c.primary && <span className="badge-green">{t('guests.company.primary')}</span>}</div>
                                    <div className="text-slate-500">{[c.role, c.email, c.phone].filter(Boolean).join(' · ')}</div>
                                </div>
                                {confirmDel === c.id ? (
                                    <span className="flex gap-1">
                                        <button className="btn-secondary text-red-600" disabled={busy} onClick={() => call(`companies/${id}/contacts/${c.id}`, 'DELETE').then(() => setConfirmDel(null))}>{t('guests.remove')}</button>
                                        <button className="btn-secondary" onClick={() => setConfirmDel(null)}>{t('guests.keep')}</button>
                                    </span>
                                ) : <button title={t('guests.company.remove_contact')} className="btn-ghost btn-icon btn-sm" onClick={() => setConfirmDel(c.id)} aria-label={t('guests.company.remove_contact')}><Trash2 className="w-4 h-4" /></button>}
                            </div>
                        ))}
                    </div>
                    <div className="lg:col-span-2 space-y-4">
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.company.rate_plans')}</h3>
                            {d.rate_plans.length === 0 && <p className="text-sm text-slate-500">{t('guests.company.no_rate_plans')}</p>}
                            <div className="overflow-x-auto">
                                <table className="table-auto"><tbody>
                                    {d.rate_plans.map((p) => (
                                        <tr key={p.id}>
                                            <td className="py-1 pr-2 font-medium">{p.code}</td><td className="pr-2">{p.name}</td><td className="pr-2">{p.meal_plan}</td><td className="pr-2">{p.hotel_name}</td>
                                            <td>{p.active ? <span className="badge-green">{t('guests.status.active')}</span> : <span className="badge-slate">{t('guests.status.inactive')}</span>}</td>
                                        </tr>
                                    ))}
                                </tbody></table>
                            </div>
                        </div>
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.company.bookings')}</h3>
                            <div className="overflow-x-auto">
                                <table className="table-auto"><tbody>
                                    {d.bookings.map((b) => (
                                        <tr key={b.id}>
                                            <td className="py-1 pr-2"><Link href={`/reservations/${b.id}`} className="text-navy-600 underline-offset-2 hover:underline">{b.number}</Link></td>
                                            <td className="pr-2">{b.guest_name}</td><td className="pr-2">{b.hotel_name}</td>
                                            <td className="pr-2 whitespace-nowrap">{formatDate(b.arrival)} → {formatDate(b.departure)}</td>
                                            <td className="pr-2"><span className="badge-slate">{b.status.replace('_', ' ')}</span></td>
                                            <td className="text-right">{formatCurrency(b.total_amount)}</td>
                                        </tr>
                                    ))}
                                    {d.bookings.length === 0 && <tr><td className="py-3 text-slate-500">{t('guests.company.no_bookings')}</td></tr>}
                                </tbody></table>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
