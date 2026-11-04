import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import Modal from '@/Components/ui/Modal';
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
    const [viewId, setViewId] = useState<number | null>(null);
    const detail = useApi<PayLink & { intents: { id: number; reference: string; provider: string; amount: number; status: string; payer_phone: string | null; created_at: string; completed_at: string | null }[] }>(viewId ? `payments/links/${viewId}` : null, [viewId]);

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
                            <select className="select" value={form.target_type} onChange={(e) => setForm({ ...form, target_type: e.target.value })}>
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
                                <td className="font-mono"><button type="button" className="hit-44 text-blue-700 underline" onClick={() => setViewId(l.id)}>{l.code}</button></td>
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
            <Modal open={viewId !== null} onClose={() => setViewId(null)} title={t('gapf.link.detail', { code: detail.data?.code ?? '' })}>
                {detail.error && <p className="text-sm text-red-700">{detail.error.message}</p>}
                {detail.data && (
                    <div className="space-y-3 text-sm">
                        <dl className="grid grid-cols-2 gap-2">
                            <div><dt className="text-slate-500">{t('payments2.links.target')}</dt><dd>{t(`payments2.target.${detail.data.target_type}`)} · {detail.data.label}</dd></div>
                            <div><dt className="text-slate-500">{t('payments2.inbound.amount')}</dt><dd>{formatCurrency(detail.data.amount)}</dd></div>
                            <div><dt className="text-slate-500">{t('payments2.links.paid')}</dt><dd>{formatCurrency(detail.data.amount_paid)}</dd></div>
                            <div><dt className="text-slate-500">{t('payments2.links.balance')}</dt><dd>{formatCurrency(detail.data.balance)}</dd></div>
                            <div><dt className="text-slate-500">{t('payments2.links.expires')}</dt><dd>{detail.data.expires_at ? formatDateTime(detail.data.expires_at) : '-'}</dd></div>
                            <div><dt className="text-slate-500">{t('gapf.link.status')}</dt><dd>{t(`payments2.status.${detail.data.status}`)}</dd></div>
                        </dl>
                        <h3 className="font-semibold">{t('gapf.link.attempts')}</h3>
                        {detail.data.intents.length === 0 && <p className="text-slate-500">{t('gapf.link.no_attempts')}</p>}
                        <ul className="divide-y divide-slate-200">
                            {detail.data.intents.map((i) => (
                                <li key={i.id} className="flex flex-wrap justify-between gap-2 py-2">
                                    <span className="font-mono">{i.reference}</span><span>{formatCurrency(i.amount)}</span><span>{i.provider}</span><span>{i.status}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}
