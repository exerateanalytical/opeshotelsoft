import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { Banner, errMsg, Field, Loading, monthStart, today } from './shared';
import { useT } from '@/lib/i18n';

interface CashFlow {
    sections: { key: string; label: string; total: number; lines: { label: string; amount: number; subtotal?: boolean }[] }[];
    net_change: number; opening_treasury: number; closing_treasury: number; check: boolean;
}
interface Dsf { gate: string; lines: { ref: string; label: string; statement: string; amount: number; accounts: string[] }[]; unmapped_accounts: string[] }
interface FxPos { account: string; currency: string; fx_balance: number; book_balance: number; rate: number; revalued: number; difference: number }
interface FxPreview { rates: Record<string, number>; positions: FxPos[]; gain: number; loss: number }
interface YearStatus { year: number; open_months: number[]; locked: boolean; result: number; can_close: boolean; opening_balances_next_year: { account_code: string; debit: number; credit: number }[] }

const M = (n: number) => formatCurrency(n);

export default function Closing() {
    const t = useT();
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [from, setFrom] = useState(monthStart());
    const [to, setTo] = useState(today());
    const [year, setYear] = useState(new Date().getFullYear() - 1);
    const [fxDate, setFxDate] = useState(today());
    const [rates, setRates] = useState('');

    const rateQuery = rates.split(',').map((s) => s.trim().split('=')).filter((p) => p.length === 2 && p[1])
        .map(([c, r]) => `&rates[${encodeURIComponent(c.toUpperCase())}]=${encodeURIComponent(r)}`).join('');
    const cf = useApi<CashFlow>(`accounting/reports/cash-flow?from=${from}&to=${to}`, [from, to]);
    const dsf = useApi<Dsf>(`accounting/reports/dsf?year=${year}`, [year]);
    const fx = useApi<FxPreview>(`accounting/fx-revaluations/preview?date=${fxDate}${rateQuery}`, [fxDate, rateQuery]);
    const ye = useApi<YearStatus>(`accounting/year-end?year=${year}`, [year]);

    async function post(path: string, body: object, msg: string) {
        setBusy(true); setError(null); setOk(null);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            setOk(msg); fx.reload(); ye.reload(); cf.reload(); dsf.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    const fxRates = Object.fromEntries(rates.split(',').map((s) => s.trim().split('=')).filter((p) => p.length === 2 && p[1]).map(([c, r]) => [c.toUpperCase(), Number(r)]));

    return (
        <div className="space-y-4">
            <Banner error={error} success={ok} />

            <section className="card p-4">
                <div className="mb-3 grid grid-cols-2 gap-2 sm:flex sm:items-end">
                    <h4 className="col-span-2 font-semibold sm:mr-auto">{t('fin.closing.cash_flow')}</h4>
                    <Field label={t('fin.from')}><input type="date" className="input w-full" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label={t('fin.to')}><input type="date" className="input w-full" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                </div>
                <Loading on={cf.loading} />
                {cf.error && <p className="text-sm text-red-700">{cf.error.message}</p>}
                {cf.data && (
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <tbody>
                                <tr><td className="p-2">{t('fin.closing.opening_treasury')}</td><td className="p-2 text-right">{M(cf.data.opening_treasury)}</td></tr>
                                {cf.data.sections.map((s) => [
                                    <tr key={s.key} className="border-t border-slate-200 font-semibold"><td className="p-2">{s.label}</td><td className="p-2 text-right whitespace-nowrap">{M(s.total)}</td></tr>,
                                    ...s.lines.map((l) => <tr key={s.key + l.label}><td className={`p-2 pl-6 ${l.subtotal ? 'font-medium' : 'text-slate-600'}`}>{l.label}</td><td className="p-2 text-right whitespace-nowrap">{M(l.amount)}</td></tr>),
                                ])}
                                <tr className="border-t border-slate-200 font-semibold"><td className="p-2">{t('fin.closing.closing_treasury')}</td><td className="p-2 text-right">{M(cf.data.closing_treasury)}</td></tr>
                            </tbody>
                        </table>
                        {!cf.data.check && <p className="mt-2 text-sm text-amber-700">{t('fin.closing.no_reconcile')}</p>}
                    </div>
                )}
            </section>

            <section className="card p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <h4 className="mr-auto font-semibold">{t('fin.closing.fiscal_year')}</h4>
                    <Field label={t('fin.periods.year')}><input type="number" className="input w-28" value={year} onChange={(e) => setYear(Number(e.target.value))} /></Field>
                </div>
                {ye.data && (
                    <div className="space-y-2 text-sm">
                        <p>{t('fin.closing.result_year', { year: ye.data.year })} <b>{M(ye.data.result)}</b> · {ye.data.locked ? t('fin.closing.locked') : ye.data.open_months.length ? t('fin.closing.open_months', { months: ye.data.open_months.join(', ') }) : t('fin.closing.all_closed')}</p>
                        {!ye.data.locked && (
                            <button className="btn-primary" disabled={busy || !ye.data.can_close} onClick={() => post('accounting/year-end/close', { year }, t('fin.closing.year_closed', { year }))}>{t('fin.closing.close_year', { year })}</button>
                        )}
                        <p className="text-xs text-slate-500">{t('fin.closing.close_hint')}</p>
                        {ye.data.opening_balances_next_year.length > 0 && (
                            <p className="text-xs text-slate-500">{t('fin.closing.carried', { count: ye.data.opening_balances_next_year.length, year: year + 1 })}</p>
                        )}
                    </div>
                )}
            </section>

            <section className="card p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <h4 className="mr-auto font-semibold">{t('fin.closing.dsf_export', { year })}</h4>
                    <a className="btn-secondary" href={`/api/v1/accounting/reports/dsf?year=${year}&format=csv`}>{t('fin.download_csv')}</a>
                </div>
                {dsf.data && (
                    <>
                        <p className="mb-2 text-xs text-amber-700">{dsf.data.gate}</p>
                        <div className="overflow-x-auto">
                            <table className="table-auto w-full text-sm">
                                <tbody>
                                    {dsf.data.lines.map((l) => (
                                        <tr key={l.ref} className="border-t border-slate-100">
                                            <td className="p-2 font-mono">{l.ref}</td><td className="p-2">{l.label}<span className="block text-xs text-slate-500">{l.accounts.join(' ')}</span></td>
                                            <td className="p-2 text-xs text-slate-500">{l.statement}</td><td className="p-2 text-right whitespace-nowrap">{M(l.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {dsf.data.unmapped_accounts.length > 0 && <p className="mt-2 text-sm text-amber-700">{t('fin.closing.unmapped', { accounts: dsf.data.unmapped_accounts.join(', ') })}</p>}
                    </>
                )}
            </section>

            <section className="card p-4">
                <div className="mb-3 grid grid-cols-1 gap-2 sm:flex sm:items-end">
                    <h4 className="font-semibold sm:mr-auto">{t('fin.closing.fx_title')}</h4>
                    <Field label={t('fin.closing.closing_date')}><input type="date" className="input w-full" value={fxDate} onChange={(e) => setFxDate(e.target.value)} /></Field>
                    <Field label={t('fin.closing.rates')}><input className="input w-full sm:w-64" value={rates} onChange={(e) => setRates(e.target.value)} /></Field>
                    <button className="btn-primary" disabled={busy || !fx.data} onClick={() => post('accounting/fx-revaluations', { date: fxDate, rates: fxRates }, t('fin.closing.fx_posted'))}>{t('fin.closing.post_fx')}</button>
                </div>
                {fx.error && <p className="text-sm text-red-700">{fx.error.message}</p>}
                {fx.data && (
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.col.account')}</th><th className="p-2 text-right">{t('fin.closing.foreign')}</th><th className="p-2 text-right">{t('fin.closing.book')}</th><th className="p-2 text-right">{t('fin.closing.revalued')}</th><th className="p-2 text-right">{t('fin.closing.difference')}</th></tr></thead>
                            <tbody>
                                {fx.data.positions.map((p, i) => (
                                    <tr key={i} className="border-t border-slate-100">
                                        <td className="p-2 font-mono">{p.account}</td><td className="p-2 text-right">{p.fx_balance} {p.currency}</td>
                                        <td className="p-2 text-right">{M(p.book_balance)}</td><td className="p-2 text-right">{M(p.revalued)}</td><td className="p-2 text-right">{M(p.difference)}</td>
                                    </tr>
                                ))}
                                {fx.data.positions.length === 0 && <tr><td colSpan={5} className="p-6 text-center text-slate-400">{t('fin.closing.fx_empty')}</td></tr>}
                            </tbody>
                        </table>
                        <p className="mt-2 text-sm">{t('fin.closing.gain')} {M(fx.data.gain)} · {t('fin.closing.loss')} {M(fx.data.loss)}</p>
                    </div>
                )}
            </section>
        </div>
    );
}
