import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { cn, formatCurrency } from '@/lib/utils';
import { Banner, errMsg, Page } from './Crud';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useLocale, useT } from '@/lib/i18n';

interface RatePlan { id: number; code: string; name: string }
interface RateRow { rate_plan_id: number; room_type_id: number; date: string; amount: number; min_stay: number | null; closed: boolean | number; source?: 'base' | 'override' }
interface InvDay { date: string; capacity: number; sold: number; available: number; stop_sell: boolean }
interface InvRow { room_type: { id: number; code: string; name: string }; days: InvDay[] }

const iso = (d: Date) => d.toISOString().slice(0, 10);
const addDays = (s: string, n: number) => { const d = new Date(s + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return iso(d); };

export default function RatesGrid() {
    const t = useT();
    const locale = useLocale();
    const [from, setFrom] = useState(iso(new Date()));
    const to = addDays(from, 13);
    const plans = useApi<Page<RatePlan>>('rate-plans?per_page=100&filter[active]=1');
    const [planId, setPlanId] = useState('');
    useEffect(() => {
        if (!planId && plans.data?.data.length) setPlanId(String(plans.data.data[0].id));
    }, [plans.data]);

    const rates = useApi<RateRow[]>(planId ? `rates?from=${from}&to=${to}&rate_plan_id=${planId}` : null);
    const inv = useApi<InvRow[]>(`inventory?from=${from}&to=${to}`);
    const days = Array.from({ length: 14 }, (_, i) => addDays(from, i));

    const rateMap = new Map<string, RateRow>();
    (rates.data ?? []).forEach((r) => rateMap.set(`${r.room_type_id}|${String(r.date).slice(0, 10)}`, r));

    // Bulk form
    const [types, setTypes] = useState<number[]>([]);
    const [fFrom, setFFrom] = useState(from);
    const [fTo, setFTo] = useState(to);
    const [amount, setAmount] = useState('');
    const [minStay, setMinStay] = useState('');
    const [closed, setClosed] = useState('');
    const [stopSell, setStopSell] = useState('');
    const [weekdays, setWeekdays] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setError(null);
        setSuccess(null);
        if (!types.length) return setError(t('prop.rates.pick_type'));
        const rateBody: Record<string, unknown> = {};
        if (amount !== '') rateBody.amount = Number(amount);
        if (minStay !== '') rateBody.min_stay = Number(minStay);
        if (closed !== '') rateBody.closed = closed === '1';
        if (!Object.keys(rateBody).length && stopSell === '') return setError(t('prop.rates.set_something'));
        if (Object.keys(rateBody).length && !planId) return setError(t('prop.rates.pick_plan'));
        setBusy(true);
        try {
            const done: string[] = [];
            if (Object.keys(rateBody).length) {
                const r = await api<{ updated: number }>('rates', {
                    method: 'PUT',
                    body: JSON.stringify({ rate_plan_id: Number(planId), room_type_ids: types, from: fFrom, to: fTo, ...(weekdays.length ? { weekdays } : {}), ...rateBody }),
                });
                done.push(t('prop.rates.nights_updated', { count: r.updated }));
            }
            if (stopSell !== '') {
                await api('inventory', { method: 'PUT', body: JSON.stringify({ room_type_ids: types, from: fFrom, to: fTo, stop_sell: stopSell === '1' }) });
                done.push(stopSell === '1' ? t('prop.rates.stop_on') : t('prop.rates.stop_off'));
            }
            setSuccess(done.join(', ') + '.');
            rates.reload();
            inv.reload();
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setBusy(false);
        }
    }

    const rows = inv.data ?? [];

    return (
        <div>
            <Banner error={error} success={success} />
            <QueryStatus query={plans} variant="list" rows={1} />
            <QueryStatus query={inv} variant="table" rows={4} />
            <QueryStatus query={rates} variant="list" rows={1} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <select className="select w-full sm:w-auto" aria-label={t('prop.rates.pick_plan')} value={planId} onChange={(e) => setPlanId(e.target.value)}>
                    {!plans.data?.data.length && <option value="">{t('prop.rates.no_plans')}</option>}
                    {plans.data?.data.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                </select>
                <button className="btn-secondary btn-icon" onClick={() => setFrom(addDays(from, -14))} aria-label={t('common.previous')}><ChevronLeft className="h-4 w-4" /></button>
                <input type="date" className="input w-full sm:w-40" aria-label={t('fin.col.date')} value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} />
                <button className="btn-secondary btn-icon" onClick={() => setFrom(addDays(from, 14))} aria-label={t('common.next')}><ChevronRight className="h-4 w-4" /></button>
            </div>
            {plans.data && plans.data.data.length === 0 && <div className="card mb-4"><EmptyFor kind="rooms" action={<a href="/property" className="btn-secondary">{t('prop.rates.no_plans')}</a>} /></div>}

            <div className="card mb-5 overflow-x-auto">
                <table className="table-auto w-full text-xs">
                    <thead>
                        <tr className="text-slate-500">
                            <th className="sticky left-0 bg-white px-2 py-2 text-left">{t('prop.f.room_type')}</th>
                            {days.map((d) => {
                                const dt = new Date(d + 'T00:00:00Z');
                                return (
                                    <th key={d} className={cn('px-2 py-2 text-center font-medium', [0, 6].includes(dt.getUTCDay()) && 'bg-slate-50')}>
                                        {dt.toLocaleDateString(locale, { weekday: 'short', timeZone: 'UTC' })}<br />{d.slice(8)}/{d.slice(5, 7)}
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {!rows.length && !inv.loading && <tr><td colSpan={15} className="px-3 py-6 text-center text-slate-500">{t('prop.rates.no_types')}</td></tr>}
                        {rows.map((row) => {
                            const byDate = new Map(row.days.map((d) => [d.date, d]));
                            return (
                                <tr key={row.room_type.id} className="border-t border-slate-100">
                                    <td className="sticky left-0 bg-white px-2 py-2 font-medium text-slate-700">{row.room_type.code}<div className="font-normal text-slate-500">{row.room_type.name}</div></td>
                                    {days.map((d) => {
                                        const r = rateMap.get(`${row.room_type.id}|${d}`);
                                        const iv = byDate.get(d);
                                        const stop = iv?.stop_sell;
                                        const isClosed = r && !!Number(r.closed);
                                        return (
                                            <td key={d} className={cn('min-w-[84px] px-2 py-2 text-center align-top', stop ? 'bg-red-50' : isClosed ? 'bg-amber-50' : '')}>
                                                <div className="font-semibold text-slate-800">{r ? formatCurrency(r.amount) : '-'}</div>
                                                {r?.source === 'base' && <div className="text-slate-500" title={t('prop.rates.default_hint')}>{t('prop.rates.default')}</div>}
                                                <div className={cn(iv && iv.available === 0 ? 'text-red-600' : 'text-emerald-700')}>{iv ? t('prop.rates.avail', { available: iv.available, capacity: iv.capacity }) : '-'}</div>
                                                {r?.min_stay && r.min_stay > 1 && <div className="text-slate-500">{t('prop.rates.min', { n: r.min_stay })}</div>}
                                                {stop && <div className="font-medium text-red-600">{t('prop.rates.stop_sell')}</div>}
                                                {isClosed && <div className="font-medium text-amber-700">{t('prop.rates.closed')}</div>}
                                            </td>
                                        );
                                    })}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <form onSubmit={submit} className="card p-4">
                <h3 className="mb-3 font-semibold text-slate-800">{t('prop.rates.update_range')}</h3>
                <div className="mb-3 flex flex-wrap gap-3">
                    {rows.map((r) => (
                        <label key={r.room_type.id} className="flex min-h-11 items-center gap-2 text-sm">
                            <input type="checkbox" className="h-5 w-5" checked={types.includes(r.room_type.id)}
                                onChange={(e) => setTypes(e.target.checked ? [...types, r.room_type.id] : types.filter((id) => id !== r.room_type.id))} />
                            {r.room_type.code}
                        </label>
                    ))}
                    {rows.length > 0 && <button type="button" className="btn-ghost btn-sm" onClick={() => setTypes(rows.map((r) => r.room_type.id))}>{t('common.all')}</button>}
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-6 [&>*]:min-w-0">
                    <label><span className="mb-1 block text-xs text-slate-500">{t('prop.rates.from')}</span><input type="date" className="input" value={fFrom} onChange={(e) => setFFrom(e.target.value)} required /></label>
                    <label><span className="mb-1 block text-xs text-slate-500">{t('prop.rates.to')}</span><input type="date" className="input" value={fTo} onChange={(e) => setFTo(e.target.value)} required /></label>
                    <label><span className="mb-1 block text-xs text-slate-500">{t('prop.rates.rate_fcfa')}</span><input type="number" min={0} className="input" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={t('prop.rates.unchanged')} /></label>
                    <label><span className="mb-1 block text-xs text-slate-500">{t('prop.rates.min_stay')}</span><input type="number" min={1} max={30} className="input" value={minStay} onChange={(e) => setMinStay(e.target.value)} placeholder={t('prop.rates.unchanged')} /></label>
                    <label><span className="mb-1 block text-xs text-slate-500">{t('prop.rates.closed_plan')}</span>
                        <select className="select" value={closed} onChange={(e) => setClosed(e.target.value)} aria-label={t('prop.rates.unchanged')}><option value="">{t('prop.rates.unchanged')}</option><option value="1">{t('prop.rates.closed')}</option><option value="0">{t('prop.rates.open')}</option></select></label>
                    <label><span className="mb-1 block text-xs text-slate-500">{t('prop.rates.stop_sell_type')}</span>
                        <select className="select" value={stopSell} onChange={(e) => setStopSell(e.target.value)} aria-label={t('prop.rates.unchanged')}><option value="">{t('prop.rates.unchanged')}</option><option value="1">{t('prop.rates.on')}</option><option value="0">{t('prop.rates.off')}</option></select></label>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                    <span className="text-xs text-slate-500">{t('prop.rates.weekdays')}</span>
                    {['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].map((w, i) => (
                        <label key={w} className="flex min-h-11 items-center gap-2 text-sm">
                            <input type="checkbox" className="h-5 w-5" checked={weekdays.includes(i)} onChange={(e) => setWeekdays(e.target.checked ? [...weekdays, i] : weekdays.filter((x) => x !== i))} /> {t(`prop.rates.wd.${w}`)}
                        </label>
                    ))}
                </div>
                <button className="btn-primary mt-4" disabled={busy}>{busy && <Loader2 className="h-4 w-4 animate-spin" />} {t('prop.rates.apply')}</button>
            </form>
        </div>
    );
}
