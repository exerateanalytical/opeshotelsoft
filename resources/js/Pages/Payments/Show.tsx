import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Loader2, RefreshCw, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { Intent, PROVIDERS, STATUS_BADGE } from './shared';
import { useT } from '@/lib/i18n';


export default function PaymentShow({ id }: { id: string }) {
    const t = useT();
    const intent = useApi<Intent>(`payments/intents/${id}`);
    const i = intent.data;

    // Poll while the payment is pending.
    useEffect(() => {
        if (i?.status !== 'pending') return;
        const timer = setTimeout(() => intent.reload(), 5000);
        return () => clearTimeout(timer);
    }, [i]);

    return (
        <AppLayout title={t('payments.show.title')} subtitle={i ? `${t(PROVIDERS[i.provider])} · ${i.reference}` : undefined}
            actions={<Link href="/payments" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('payments.show.all')}</Link>}>
            <Head title={t('payments.show.title')} />
            {intent.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{intent.error.message}</div>}
            {!i && intent.loading && <Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-400" />}
            {i && (
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                    <div className="card px-5 py-4 self-start">
                        <div className="flex items-center justify-between gap-3 mb-4">
                            <div className="text-2xl font-semibold">{formatCurrency(i.amount, i.currency)}</div>
                            <div className="flex items-center gap-2">
                                <span className={STATUS_BADGE[i.status]}>{t(`payments.status.${i.status}`)}</span>
                                <button className="btn-secondary btn-sm" onClick={() => intent.reload()} aria-label={t('payments.show.refresh')}><RefreshCw className="w-4 h-4" /></button>
                            </div>
                        </div>
                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <Item label={t('payments.method')}>{t(PROVIDERS[i.provider])}{i.driver === 'dry_run' && <span className="badge-slate ml-2">{t('payments.dry_run')}</span>}</Item>
                            <Item label={t('payments.show.channel')}>{i.channel}</Item>
                            <Item label={t('payments.show.folio')}>{i.folio ? `${i.folio.number} - ${i.folio.name}` : '-'}</Item>
                            <Item label={t('payments.show.reservation')}>{i.reservation ? <Link className="text-sky-700" href={`/reservations/${i.reservation.id}`}>{i.reservation.number}</Link> : '-'}</Item>
                            <Item label={t('payments.payer')}>{i.payer_phone ?? i.payer_email ?? '-'}</Item>
                            <Item label={t('payments.show.provider_ref')}><span className="font-mono break-all">{i.provider_reference ?? '-'}</span></Item>
                            <Item label={t('payments.show.created')}>{formatDateTime(i.created_at)}</Item>
                            <Item label={t('payments.show.completed')}>{i.completed_at ? formatDateTime(i.completed_at) : '-'}</Item>
                            {i.failure_reason && <Item label={t('payments.reason')}>{i.failure_reason}</Item>}
                            <Item label={t('payments.show.refunded')}>{formatCurrency(i.refunded_amount, i.currency)}</Item>
                        </dl>
                        {i.status === 'pending' && i.redirect_url && (
                            <a href={i.redirect_url} target="_blank" rel="noreferrer" className="btn-secondary mt-4"><ExternalLink className="w-4 h-4" /> {t('payments.show.checkout')}</a>
                        )}
                        {i.callbacks && i.callbacks.length > 0 && (
                            <div className="mt-5">
                                <div className="label mb-2">{t('payments.show.callbacks')}</div>
                                <ul className="text-sm divide-y divide-slate-100">
                                    {i.callbacks.map((c) => (
                                        <li key={c.id} className="py-2 flex justify-between gap-3">
                                            <span>{formatDateTime(c.created_at)}</span>
                                            <span className={c.signature_valid ? 'badge-blue' : 'badge-red'}>{c.signature_valid ? c.outcome ?? t('payments.show.received') : t('payments.show.bad_signature')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                    <div className="space-y-6">
                        {i.status === 'succeeded' && (i.refundable ?? 0) > 0 && <RefundForm intent={i} onDone={() => intent.reload()} />}
                        <div className="card overflow-x-auto">
                            <table className="table-auto w-full">
                                <thead><tr><th>{t('payments.show.refund')}</th><th>{t('payments.reason')}</th><th className="text-right">{t('payments.amount')}</th></tr></thead>
                                <tbody>
                                    {(i.refunds ?? []).length === 0 && <tr><td colSpan={3} className="text-center py-6 text-slate-400 text-sm">{t('payments.show.no_refunds')}</td></tr>}
                                    {(i.refunds ?? []).map((r) => (
                                        <tr key={r.id}>
                                            <td className="text-sm whitespace-nowrap">{formatDateTime(r.created_at)}</td>
                                            <td className="text-sm">{r.reason}</td>
                                            <td className="text-right text-sm">{formatCurrency(r.amount, i.currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function Item({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="mt-0.5">{children}</dd>
        </div>
    );
}

function RefundForm({ intent, onDone }: { intent: Intent; onDone: () => void }) {
    const t = useT();
    const [amount, setAmount] = useState(String(intent.refundable ?? ''));
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    return (
        <form className="card px-5 py-4 space-y-3" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setMessage(null);
            try {
                await api(`payments/intents/${intent.id}/refunds`, { method: 'POST', body: JSON.stringify({ amount: Number(amount), reason }) });
                setMessage({ ok: true, text: t('payments.show.refund_posted') });
                setReason('');
                onDone();
            } catch (err) {
                setMessage({ ok: false, text: err instanceof ApiError ? err.message : String(err) });
            } finally {
                setBusy(false);
            }
        }}>
            <div className="font-medium text-sm">{t('payments.show.refund_gateway')}</div>
            <label className="label block">{t('payments.show.amount_max', { amount: formatCurrency(intent.refundable ?? 0, intent.currency) })}
                <input className="input mt-1" type="number" min={1} max={intent.refundable} required value={amount} onChange={(e) => setAmount(e.target.value)} />
            </label>
            <label className="label block">{t('payments.reason')}
                <input className="input mt-1" required maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
            </label>
            <button className="btn-primary w-full justify-center" disabled={busy}>{busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Undo2 className="w-4 h-4" />} {t('payments.show.refund_btn')}</button>
            {message && <div className={message.ok ? 'rounded-lg text-sm px-4 py-3 bg-emerald-50 text-emerald-700' : 'rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700'}>{message.text}</div>}
        </form>
    );
}
