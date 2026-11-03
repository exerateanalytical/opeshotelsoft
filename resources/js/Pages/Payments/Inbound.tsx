import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Candidate { link_id: number; code: string; label: string | null; balance: number; score: number; reasons: string[] }
interface Inbound {
    id: number; source: string; provider: string | null; transaction_id: string | null; amount: number; payer_phone: string | null;
    message: string | null; date: string; status: string; confidence: number; candidates: Candidate[];
}

export default function PaymentInbound() {
    const t = useT();
    const [status, setStatus] = useState('review');
    const list = useApi<{ data: Inbound[] }>(`payments/inbound?status=${status}`);
    const [bank, setBank] = useState('');
    const [msg, setMsg] = useState('');

    const act = async (path: string, body?: object) => {
        setMsg('');
        try {
            await api(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
        } catch (e) {
            setMsg(e instanceof ApiError ? e.message : String(e));
        }
        list.reload();
    };
    const scan = async () => {
        setMsg('');
        try {
            const r = await api<{ scanned: number; posted: number; review: number }>(`payments/bank-accounts/${bank}/scan`, { method: 'POST' });
            setMsg(t('payments2.inbound.scanned', { scanned: r.scanned, posted: r.posted, review: r.review }));
        } catch (e) {
            setMsg(e instanceof ApiError ? e.message : String(e));
        }
        list.reload();
    };

    return (
        <AppLayout title={t('payments2.inbound.title')} subtitle={t('payments2.inbound.subtitle')}>
            <Head title={t('payments2.inbound.title')} />
            <div className="card px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
                <label className="label"><span className="label">{t('payments2.inbound.bank_account')}</span>
                    <input className="input w-40" inputMode="numeric" value={bank} onChange={(e) => setBank(e.target.value)} /></label>
                <button className="btn-secondary" onClick={scan} disabled={!bank}>{t('payments2.inbound.scan')}</button>
                <select className="input w-44 ml-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
                    {['review', 'posted', 'ignored'].map((s) => <option key={s} value={s}>{t(`payments2.status.${s}`)}</option>)}
                </select>
                {msg && <div className="w-full text-slate-700">{msg}</div>}
            </div>
            <div className="space-y-3">
                {list.data?.data.length === 0 && <div className="card p-6 text-center text-slate-500">{t('payments2.inbound.empty')}</div>}
                {list.data?.data.map((i) => (
                    <div key={i.id} className="card p-4">
                        <div className="flex flex-wrap gap-x-6 gap-y-1">
                            <span className="font-semibold">{formatCurrency(i.amount)}</span>
                            <span>{t('payments2.inbound.source')}: {i.source} · {i.provider ? t(`payments2.channel.${i.provider}`) : '-'}</span>
                            <span>{t('payments2.inbound.date')}: {formatDateTime(i.date)}</span>
                            <span>{i.payer_phone ?? ''}</span>
                            <span className="text-slate-600">{t('payments2.inbound.message')}: {i.message ?? '-'}</span>
                            <span>{t('payments2.inbound.confidence')}: {i.confidence}%</span>
                        </div>
                        {i.status === 'review' && (
                            <div className="mt-3">
                                <div className="label">{t('payments2.inbound.candidates')}</div>
                                {i.candidates.map((c) => (
                                    <div key={c.link_id} className="flex flex-wrap items-center gap-3 py-1 border-t border-slate-100">
                                        <span className="font-mono">{c.code}</span><span>{c.label}</span>
                                        <span>{formatCurrency(c.balance)}</span><span>{c.score}%</span>
                                        <span className="text-slate-500">{c.reasons.join(', ')}</span>
                                        <button className="btn-primary ml-auto" onClick={() => act(`payments/inbound/${i.id}/post`, { payment_link_id: c.link_id })}>{t('payments2.inbound.post')}</button>
                                    </div>
                                ))}
                                <div className="flex gap-2 mt-2">
                                    <button className="btn-secondary" onClick={() => act(`payments/inbound/${i.id}/rematch`)}>{t('payments2.inbound.rematch')}</button>
                                    <button className="btn-ghost" onClick={() => act(`payments/inbound/${i.id}/ignore`)}>{t('payments2.inbound.ignore')}</button>
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
