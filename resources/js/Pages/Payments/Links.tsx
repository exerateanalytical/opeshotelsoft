import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { Copy, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface PayLink {
    id: number; code: string; target_type: string; target_id: number; label: string | null; amount: number; amount_paid: number;
    balance: number; currency: string; status: string; expires_at: string | null; url: string; qr_svg?: string;
}

const BADGE: Record<string, string> = { open: 'badge-yellow', partially_paid: 'badge-yellow', paid: 'badge-green', cancelled: 'badge-slate' };
const TARGETS = ['folio', 'pos_check', 'invoice', 'reservation'];

export default function PaymentLinks() {
    const t = useT();
    const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    const list = useApi<{ data: PayLink[] }>('payments/links');
    const [open, setOpen] = useState(params.has('target_type'));
    const [form, setForm] = useState({ target_type: params.get('target_type') ?? 'folio', target_id: params.get('target_id') ?? '', amount: '', payer_phone: '', allow_partial: true });
    const [created, setCreated] = useState<PayLink | null>(null);
    const [error, setError] = useState('');

    const create = async () => {
        setError('');
        try {
            const l = await api<PayLink>('payments/links', { method: 'POST', body: JSON.stringify({
                target_type: form.target_type, target_id: Number(form.target_id), amount: form.amount ? Number(form.amount) : null,
                payer_phone: form.payer_phone || null, allow_partial: form.allow_partial,
            }) });
            setCreated(l);
            list.reload();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
        }
    };
    const cancel = async (id: number) => {
        await api(`payments/links/${id}/cancel`, { method: 'POST' });
        list.reload();
    };

    return (
        <AppLayout title={t('payments2.links.title')} subtitle={t('payments2.links.subtitle')}
            actions={<button className="btn-primary" onClick={() => setOpen(!open)}><Plus className="w-4 h-4" /> {t('payments2.links.new')}</button>}>
            <Head title={t('payments2.links.title')} />
            <QueryStatus query={list} variant="table" />
            {open && (
                <div className="card p-4 mb-4 grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label className="label"><span className="label">{t('payments2.links.target')}</span>
                            <select className="input" value={form.target_type} onChange={(e) => setForm({ ...form, target_type: e.target.value })}>
                                {TARGETS.map((x) => <option key={x} value={x}>{t(`payments2.target.${x}`)}</option>)}
                            </select></label>
                        <label className="label"><span className="label">{t('payments2.links.target_id')}</span>
                            <input className="input" inputMode="numeric" value={form.target_id} onChange={(e) => setForm({ ...form, target_id: e.target.value })} /></label>
                        <label className="label"><span className="label">{t('payments2.links.amount')}</span>
                            <input className="input" inputMode="numeric" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></label>
                        <label className="label"><span className="label">{t('payments2.links.phone')}</span>
                            <input className="input" value={form.payer_phone} onChange={(e) => setForm({ ...form, payer_phone: e.target.value })} /></label>
                        <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={form.allow_partial} onChange={(e) => setForm({ ...form, allow_partial: e.target.checked })} /> {t('payments2.links.allow_partial')}</label>
                        <div className="sm:col-span-2 flex items-center gap-3">
                            <button className="btn-primary" onClick={create} disabled={!form.target_id}>{t('payments2.links.create')}</button>
                            {error && <span className="text-red-600">{error}</span>}
                        </div>
                    </div>
                    {created && (
                        <div className="text-center">
                            <div className="w-44 h-44 mx-auto [&_svg]:w-full [&_svg]:h-full" dangerouslySetInnerHTML={{ __html: created.qr_svg ?? '' }} />
                            <div className="font-mono mt-2">{created.code}</div>
                            <div>{formatCurrency(created.amount)}</div>
                            <button className="btn-secondary mt-2" onClick={() => navigator.clipboard?.writeText(created.url)}><Copy className="w-4 h-4" /> {t('payments2.links.copy')}</button>
                        </div>
                    )}
                </div>
            )}
            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th>Code</th><th>{t('payments2.links.target')}</th><th className="text-right">{t('payments2.inbound.amount')}</th>
                        <th className="text-right">{t('payments2.links.paid')}</th><th className="text-right">{t('payments2.links.balance')}</th><th>{t('payments2.links.expires')}</th><th></th><th></th></tr></thead>
                    <tbody>
                        {list.data?.data.length === 0 && <tr><td colSpan={8} className="text-center text-slate-500 py-6">{t('payments2.links.empty')}</td></tr>}
                        {list.data?.data.map((l) => (
                            <tr key={l.id}>
                                <td className="font-mono">{l.code}</td>
                                <td>{t(`payments2.target.${l.target_type}`)} · {l.label}</td>
                                <td className="text-right">{formatCurrency(l.amount)}</td>
                                <td className="text-right">{formatCurrency(l.amount_paid)}</td>
                                <td className="text-right">{formatCurrency(l.balance)}</td>
                                <td>{l.expires_at ? formatDateTime(l.expires_at) : '-'}</td>
                                <td><span className={`badge ${BADGE[l.status] ?? 'badge-slate'}`}>{t(`payments2.status.${l.status}`)}</span></td>
                                <td className="whitespace-nowrap">
                                    <button className="btn-ghost" title={t('payments2.links.copy')} onClick={() => navigator.clipboard?.writeText(l.url)}><Copy className="w-4 h-4" /></button>
                                    {['open', 'partially_paid'].includes(l.status) && <button className="btn-ghost" title={t('payments2.links.cancel')} onClick={() => cancel(l.id)}><X className="w-4 h-4" /></button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
