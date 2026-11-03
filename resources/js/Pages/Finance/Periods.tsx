import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { Badge, Banner, errMsg, Field, Loading } from './shared';

interface Period { id: number; year: number; month: number; status: string; closed_at: string | null; closed_by: number | null }
const MONTH_KEYS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

export default function Periods() {
    const t = useT();
    const MONTHS = MONTH_KEYS.map((m) => t(`fin.month.${m}`));
    const list = useApi<Period[]>('accounting/periods');
    const now = new Date();
    const [year, setYear] = useState(now.getFullYear());
    const [month, setMonth] = useState(now.getMonth() || 12);
    const [reopen, setReopen] = useState<Period | null>(null);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function run(path: string, body: object, msg: string) {
        setBusy(true); setError(null); setOk(null);
        try {
            await api(`accounting/periods/${path}`, { method: 'POST', body: JSON.stringify(body) });
            setOk(msg);
            setReopen(null); setReason('');
            list.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    return (
        <div>
            <Banner error={error ?? list.error?.message} success={ok} />
            <form className="card mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 p-4 sm:flex sm:items-end [&>*]:min-w-0"
                onSubmit={(e) => { e.preventDefault(); run('close', { year, month }, t('fin.periods.closed_ok', { period: `${MONTHS[month - 1]} ${year}` })); }}>
                <Field label={t('fin.periods.year')}><input type="number" className="input w-full sm:w-28" value={year} onChange={(e) => setYear(Number(e.target.value))} /></Field>
                <Field label={t('fin.periods.month')}>
                    <select className="input w-full" value={month} onChange={(e) => setMonth(Number(e.target.value))}>
                        {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                    </select>
                </Field>
                <button className="btn-primary sm:col-span-2" disabled={busy}>{t('fin.periods.close')}</button>
                <p className="sm:col-span-2 text-xs text-slate-500">{t('fin.periods.close_hint')}</p>
            </form>
            {reopen && (
                <form className="card mb-4 grid grid-cols-1 gap-2 border-amber-200 p-4 sm:grid-cols-[1fr_auto] sm:items-end [&>*]:min-w-0"
                    onSubmit={(e) => { e.preventDefault(); run('reopen', { year: reopen.year, month: reopen.month, reason }, t('fin.periods.reopened_ok', { period: `${MONTHS[reopen.month - 1]} ${reopen.year}` })); }}>
                    <Field label={t('fin.periods.reopen_reason', { period: `${MONTHS[reopen.month - 1]} ${reopen.year}` })}><input className="input w-full" required value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                    <div className="flex gap-2"><button className="btn-primary" disabled={busy}>{t('fin.periods.reopen')}</button><button type="button" className="btn-secondary" onClick={() => setReopen(null)}>{t('common.cancel')}</button></div>
                </form>
            )}
            <div className="card overflow-x-auto">
                <Loading on={list.loading} />
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.col.period')}</th><th className="p-2">{t('fin.col.status')}</th><th className="p-2">{t('fin.periods.closed_at')}</th><th /></tr></thead>
                    <tbody>
                        {list.data?.map((p) => (
                            <tr key={p.id} className="border-t border-slate-100">
                                <td className="p-2">{MONTHS[p.month - 1]} {p.year}</td>
                                <td className="p-2"><Badge s={p.status} /></td>
                                <td className="p-2">{p.closed_at ? formatDateTime(p.closed_at) : '—'}</td>
                                <td className="p-2 text-right">
                                    {p.status === 'closed' && <button className="btn-secondary btn-sm" onClick={() => setReopen(p)}>{t('fin.periods.reopen')}</button>}
                                </td>
                            </tr>
                        ))}
                        {list.data?.length === 0 && <tr><td colSpan={4} className="p-6 text-center text-slate-500">{t('fin.periods.empty')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
