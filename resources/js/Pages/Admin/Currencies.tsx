import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { Loader2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errorText } from '@/Pages/Settings/shared';

interface Currency { code: string; name: string; symbol: string | null; decimals: number; is_functional: boolean; active: boolean; latest_rate: number | null }
interface Rate { id: number; from: string; to: string; date: string; rate: number }
interface Page<T> { data: T[] }

export default function Currencies() {
    const t = useT();
    const perms = usePage<{ auth: { user: { permissions: string[] } | null }; [k: string]: unknown }>().props.auth.user?.permissions ?? [];
    const canManage = perms.includes('currencies.manage');
    const currencies = useApi<Currency[]>('admin/currencies');
    const rates = useApi<Page<Rate>>('admin/exchange-rates');
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [armed, setArmed] = useState<string | null>(null);
    const [cur, setCur] = useState({ code: '', name: '', symbol: '', decimals: '2' });
    const [rate, setRate] = useState({ currency: '', date: new Date().toISOString().slice(0, 10), rate: '' });

    async function run(fn: () => Promise<unknown>, after?: () => void) {
        setBusy(true);
        setMessage(null);
        try {
            await fn();
            setMessage({ ok: true, text: t('admin.currency.saved') });
            after?.();
            currencies.reload();
            rates.reload();
        } catch (err) {
            setMessage({ ok: false, text: errorText(err) });
        } finally {
            setBusy(false);
            setArmed(null);
        }
    }

    const foreign = (currencies.data ?? []).filter((c) => !c.is_functional);

    return (
        <AppLayout title={t('admin.currency.title')} subtitle={t('admin.currency.subtitle')}>
            <Head title={t('admin.currency.title')} />
            {message && <Banner {...message} />}
            {currencies.error && <Banner ok={false} text={currencies.error.message} />}

            <div className="grid lg:grid-cols-2 gap-6">
                <div className="space-y-4">
                    <div className="card overflow-x-auto">
                        <table className="table-auto w-full">
                            <thead>
                                <tr>
                                    <th>{t('admin.currency.code')}</th><th>{t('admin.currency.name')}</th><th>{t('admin.currency.decimals')}</th>
                                    <th>{t('admin.currency.latest_rate')}</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {currencies.loading && !currencies.data && <tr><td colSpan={5}><Loader2 className="w-5 h-5 mx-auto my-8 animate-spin text-slate-500" /></td></tr>}
                                {(currencies.data ?? []).map((c) => (
                                    <tr key={c.code}>
                                        <td className="font-mono text-sm font-semibold">{c.code}</td>
                                        <td className="text-sm">
                                            {c.name} {c.symbol && <span className="text-slate-500">({c.symbol})</span>}
                                            <div className="flex gap-1 mt-1">
                                                {c.is_functional && <span className="badge-blue">{t('admin.currency.functional')}</span>}
                                                {canManage && !c.is_functional ? (
                                                    <button type="button" className={c.active ? 'badge-green' : 'badge-slate'} disabled={busy}
                                                        onClick={() => run(() => api(`admin/currencies/${c.code}`, { method: 'PATCH', body: JSON.stringify({ active: !c.active }) }))}>
                                                        {c.active ? t('admin.currency.active') : t('admin.currency.inactive')}
                                                    </button>
                                                ) : !c.is_functional && <span className={c.active ? 'badge-green' : 'badge-slate'}>{c.active ? t('admin.currency.active') : t('admin.currency.inactive')}</span>}
                                            </div>
                                        </td>
                                        <td className="text-sm">{c.decimals}</td>
                                        <td className="text-sm font-mono">{c.latest_rate ?? '—'}</td>
                                        <td className="text-right">
                                            {canManage && !c.is_functional && (armed === c.code ? (
                                                <button type="button" className="btn-danger btn-sm" disabled={busy} onClick={() => run(() => api(`admin/currencies/${c.code}`, { method: 'DELETE' }))}>
                                                    {t('admin.currency.confirm_delete', { code: c.code })}
                                                </button>
                                            ) : (
                                                <button type="button" title={t('common.delete')} aria-label={t('common.delete')} className="text-slate-500 hover:text-red-600" onClick={() => setArmed(c.code)}>
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            ))}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {canManage && (
                        <form className="card p-4 space-y-3" onSubmit={(e) => {
                            e.preventDefault();
                            run(() => api('admin/currencies', { method: 'POST', body: JSON.stringify({ ...cur, code: cur.code.toUpperCase(), decimals: Number(cur.decimals), symbol: cur.symbol || null }) }),
                                () => setCur({ code: '', name: '', symbol: '', decimals: '2' }));
                        }}>
                            <h3 className="text-sm font-semibold text-slate-900">{t('admin.currency.new')}</h3>
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div><label className="label" htmlFor="c-code">{t('admin.currency.code')}</label><input id="c-code" className="input w-full uppercase" required minLength={3} maxLength={3} value={cur.code} onChange={(e) => setCur({ ...cur, code: e.target.value })} /></div>
                                <div className="col-span-2 sm:col-span-1"><label className="label" htmlFor="c-name">{t('admin.currency.name')}</label><input id="c-name" className="input w-full" required value={cur.name} onChange={(e) => setCur({ ...cur, name: e.target.value })} /></div>
                                <div><label className="label" htmlFor="c-sym">{t('admin.currency.symbol')}</label><input id="c-sym" className="input w-full" maxLength={8} value={cur.symbol} onChange={(e) => setCur({ ...cur, symbol: e.target.value })} /></div>
                                <div><label className="label" htmlFor="c-dec">{t('admin.currency.decimals')}</label><input id="c-dec" type="number" min={0} max={4} className="input w-full" value={cur.decimals} onChange={(e) => setCur({ ...cur, decimals: e.target.value })} /></div>
                            </div>
                            <button className="btn-primary" disabled={busy}><Plus className="w-4 h-4" /> {t('common.add')}</button>
                        </form>
                    )}
                </div>

                <div className="space-y-4">
                    {canManage && (
                        <form className="card p-4 space-y-3" onSubmit={(e) => {
                            e.preventDefault();
                            run(() => api('admin/exchange-rates', { method: 'POST', body: JSON.stringify({ ...rate, rate: Number(rate.rate) }) }), () => setRate({ ...rate, rate: '' }));
                        }}>
                            <h3 className="text-sm font-semibold text-slate-900">{t('admin.currency.new_rate')}</h3>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label className="label" htmlFor="r-cur">{t('admin.currency.currency')}</label>
                                    <select id="r-cur" className="input w-full" required value={rate.currency} onChange={(e) => setRate({ ...rate, currency: e.target.value })}>
                                        <option value="">—</option>
                                        {foreign.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}
                                    </select>
                                </div>
                                <div><label className="label" htmlFor="r-date">{t('admin.currency.date')}</label><input id="r-date" type="date" className="input w-full" required value={rate.date} onChange={(e) => setRate({ ...rate, date: e.target.value })} /></div>
                                <div><label className="label" htmlFor="r-rate">{t('admin.currency.rate')}</label><input id="r-rate" type="number" step="any" min="0" className="input w-full" required value={rate.rate} onChange={(e) => setRate({ ...rate, rate: e.target.value })} /></div>
                            </div>
                            <button className="btn-primary" disabled={busy}><Plus className="w-4 h-4" /> {t('common.add')}</button>
                        </form>
                    )}

                    <div className="card overflow-x-auto">
                        <h3 className="text-sm font-semibold text-slate-900 px-5 pt-4">{t('admin.currency.rates')}</h3>
                        <table className="table-auto w-full">
                            <thead><tr><th>{t('admin.currency.currency')}</th><th>{t('admin.currency.date')}</th><th>{t('admin.currency.latest_rate')}</th><th></th></tr></thead>
                            <tbody>
                                {rates.data?.data.length === 0 && <tr><td colSpan={4} className="text-center py-8 text-slate-500 text-sm">{t('admin.currency.no_rates')}</td></tr>}
                                {rates.data?.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className="font-mono text-sm">{r.from}</td>
                                        <td className="text-sm">{String(r.date).slice(0, 10)}</td>
                                        <td className="font-mono text-sm">{r.rate}</td>
                                        <td className="text-right">
                                            {canManage && (
                                                <button type="button" title={t('common.delete')} aria-label={t('common.delete')} className="text-slate-500 hover:text-red-600" disabled={busy}
                                                    onClick={() => run(() => api(`admin/exchange-rates/${r.id}`, { method: 'DELETE' }))}>
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
