import { Loader2, Wallet } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Shift { id: number; opened_at: string; opening_float: number; station: string | null; expected: Record<string, number> }

/** The signed-in user's cashier shift: payments are taken against it, and it is closed with a count per payment method. */
export default function CashierBar({ onChange, refresh = 0 }: { onChange?: () => void; refresh?: number }) {
    const t = useT();
    const shift = useApi<Shift | null>('cashier-shifts/current', [refresh]);
    const [closing, setClosing] = useState(false);
    const [counted, setCounted] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function call(path: string, body: object) {
        setBusy(true);
        setMessage(null);
        try {
            const res = await api<{ variance?: number }>(path, { method: 'POST', body: JSON.stringify(body) });
            if (res?.variance !== undefined) setMessage(t('cashier.closed_variance', { amount: formatCurrency(res.variance) }));
            setClosing(false);
            shift.reload();
            onChange?.();
        } catch (e) {
            setMessage(e instanceof ApiError ? e.message : String(e));
        } finally {
            setBusy(false);
        }
    }

    const [float, setFloat] = useState('');

    // With no shift open the API answers an empty body.
    const s = shift.data?.id ? shift.data : null;
    return (
        <div className="card px-5 py-3 mb-6">
            <div className="flex items-center gap-3 flex-wrap">
                <Wallet className="w-4 h-4 text-slate-500" />
                {shift.loading && !s ? <Loader2 className="w-4 h-4 animate-spin text-slate-400" /> : s ? (
                    <>
                        <span className="text-sm text-slate-700">
                            {t('cashier.open_since', { id: s.id, time: new Date(s.opened_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) })}
                            {' · '}{t('cashier.cash_expected')} <b>{formatCurrency(s.expected?.CASH ?? 0)}</b>
                        </span>
                        <button className="btn-secondary text-xs ml-auto" onClick={() => setClosing(!closing)}>{t('cashier.close_shift')}</button>
                    </>
                ) : (
                    <>
                        <span className="text-sm text-slate-500">{t('cashier.none_open')}</span>
                        <input type="number" min={0} className="input w-40 ml-auto text-xs" placeholder={t('cashier.opening_float')} value={float}
                            onChange={(e) => setFloat(e.target.value)} />
                        <button className="btn-primary text-xs" disabled={busy}
                            onClick={() => call('cashier-shifts', { opening_float: Number(float) || 0 })}>{t('cashier.open_shift')}</button>
                    </>
                )}
            </div>
            {closing && s && (
                <div className="mt-3 pt-3 border-t border-slate-100">
                    <p className="text-xs text-slate-500 mb-2">{t('cashier.count_hint')}</p>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                        {Object.entries(s.expected ?? {}).map(([code, amount]) => (
                            <label key={code} className="text-xs text-slate-500">
                                {code} <span className="text-slate-400">({t('cashier.expected', { amount: formatCurrency(amount) })})</span>
                                <input type="number" min={0} className="input mt-1" value={counted[code] ?? ''}
                                    onChange={(e) => setCounted({ ...counted, [code]: e.target.value })} />
                            </label>
                        ))}
                    </div>
                    <button className="btn-primary text-xs mt-3" disabled={busy}
                        onClick={() => call(`cashier-shifts/${s.id}/close`, {
                            counted: Object.fromEntries(Object.keys(s.expected ?? {}).map((c) => [c, Number(counted[c]) || 0])),
                        })}>
                        {busy && <Loader2 className="w-3 h-3 animate-spin" />} {t('cashier.close_record')}
                    </button>
                </div>
            )}
            {message && <p className="text-sm mt-2 text-slate-600">{message}</p>}
        </div>
    );
}
