import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Loader2, Plus, Search, Settings } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { Intent, PROVIDERS, STATUS_BADGE } from './shared';
import { useT } from '@/lib/i18n';


interface Page<T> { data: T[] }

export default function PaymentsIndex() {
    const t = useT();
    const [status, setStatus] = useState('');
    const [provider, setProvider] = useState('');
    const [q, setQ] = useState('');
    const [creating, setCreating] = useState(false);
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (provider) params.set('provider', provider);
    if (q.trim()) params.set('q', q.trim());
    const list = useApi<Page<Intent>>(`payments/intents?${params.toString()}`);

    return (
        <AppLayout
            title={t('payments.index.title')}
            subtitle={t('payments.index.subtitle')}
            actions={
                <div className="flex gap-2">
                    <Link href="/payments/gateways" className="btn-secondary"><Settings className="w-4 h-4" /> {t('payments.index.gateways')}</Link>
                    <button className="btn-primary" onClick={() => setCreating(!creating)}><Plus className="w-4 h-4" /> {t('payments.index.new')}</button>
                </div>
            }
        >
            <Head title={t('payments.index.title')} />
            {creating && <CreateIntent onDone={(id) => (id ? router.visit(`/payments/${id}`) : setCreating(false))} />}

            <div className="card px-4 py-3 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <label className="relative">
                    <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                    <input className="input pl-9" aria-label={t('payments.index.search')} placeholder={t('payments.index.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                </label>
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('payments.index.all_statuses')}>
                    <option value="">{t('payments.index.all_statuses')}</option>
                    {Object.keys(STATUS_BADGE).map((s) => <option key={s} value={s}>{t(`payments.status.${s}`)}</option>)}
                </select>
                <select className="input" value={provider} onChange={(e) => setProvider(e.target.value)} aria-label={t('payments.index.all_methods')}>
                    <option value="">{t('payments.index.all_methods')}</option>
                    {Object.entries(PROVIDERS).map(([k, v]) => <option key={k} value={k}>{t(v)}</option>)}
                </select>
            </div>

            {list.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{list.error.message}</div>}

            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th>{t('payments.index.date')}</th><th>{t('payments.method')}</th><th>{t('payments.target')}</th><th>{t('payments.payer')}</th><th>{t('payments.index.status')}</th><th className="text-right">{t('payments.amount')}</th></tr></thead>
                    <tbody>
                        {list.loading && !list.data && <tr><td colSpan={6} className="py-10"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-500" /></td></tr>}
                        {list.data && list.data.data.length === 0 && <tr><td colSpan={6} className="text-center py-10 text-slate-500 text-sm">{t('payments.index.no_match')}</td></tr>}
                        {list.data?.data.map((i) => (
                            <tr key={i.id} className="cursor-pointer" onClick={() => router.visit(`/payments/${i.id}`)}>
                                <td className="text-sm whitespace-nowrap">{formatDateTime(i.created_at)}</td>
                                <td className="text-sm">{t(PROVIDERS[i.provider])}{i.driver === 'dry_run' && <span className="badge-slate ml-2">{t('payments.dry_run')}</span>}</td>
                                <td className="text-sm">{i.folio ? t('payments.index.folio_n', { number: i.folio.number }) : i.reservation ? t('payments.index.booking_n', { number: i.reservation.number }) : '-'}</td>
                                <td className="text-sm">{i.payer_phone ?? i.payer_email ?? '-'}</td>
                                <td><span className={STATUS_BADGE[i.status]}>{t(`payments.status.${i.status}`)}</span></td>
                                <td className="text-right text-sm">{formatCurrency(i.amount, i.currency)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}

function CreateIntent({ onDone }: { onDone: (id?: number) => void }) {
    const t = useT();
    const [form, setForm] = useState({ provider: 'mtn_momo', amount: '', target: 'folio', target_id: '', payer_phone: '', payer_email: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <form className="card px-5 py-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 items-end" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setError(null);
            try {
                const i = await api<Intent>('payments/intents', { method: 'POST', body: JSON.stringify({
                    provider: form.provider, amount: Number(form.amount),
                    [form.target === 'folio' ? 'folio_id' : 'reservation_id']: Number(form.target_id),
                    payer_phone: form.payer_phone || null, payer_email: form.payer_email || null,
                }) });
                onDone(i.id);
            } catch (err) {
                setError(err instanceof ApiError ? err.message : String(err));
            } finally {
                setBusy(false);
            }
        }}>
            <label className="label">{t('payments.method')}
                <select className="input mt-1" value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })}>
                    {Object.entries(PROVIDERS).map(([k, v]) => <option key={k} value={k}>{t(v)}</option>)}
                </select>
            </label>
            <label className="label">{t('payments.amount_fcfa')}
                <input className="input mt-1" type="number" min={1} required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </label>
            <div className="grid grid-cols-2 gap-2">
                <label className="label">{t('payments.target')}
                    <select className="input mt-1" value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })} aria-label={t('payments.index.folio_id')}>
                        <option value="folio">{t('payments.index.folio_id')}</option>
                        <option value="reservation">{t('payments.index.reservation_id')}</option>
                    </select>
                </label>
                <label className="label">{t('payments.index.id')}
                    <input className="input mt-1" type="number" min={1} required value={form.target_id} onChange={(e) => setForm({ ...form, target_id: e.target.value })} />
                </label>
            </div>
            <label className="label">{t('payments.index.payer_phone')}
                <input className="input mt-1" inputMode="tel" aria-label="+2376..." placeholder="+2376..." value={form.payer_phone} onChange={(e) => setForm({ ...form, payer_phone: e.target.value })} />
            </label>
            <label className="label">{t('payments.index.payer_email')}
                <input className="input mt-1" type="email" value={form.payer_email} onChange={(e) => setForm({ ...form, payer_email: e.target.value })} />
            </label>
            <div className="flex gap-2">
                <button className="btn-primary justify-center flex-1" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('payments.index.request')}</button>
                <button type="button" className="btn-secondary" onClick={() => onDone()}>{t('common.cancel')}</button>
            </div>
            {error && <div className="sm:col-span-2 lg:col-span-3 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{error}</div>}
        </form>
    );
}
