import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Reason { factor: string; pct: number; detail: string }
interface Suggestion { id: number; date: string; room_type_id: number; rate_plan_id: number; current_rate: number; suggested_rate: number; change_pct: number; reasons: Reason[] }
interface Settings { high_occupancy_pct: number; low_occupancy_pct: number; high_occupancy_uplift: number; low_occupancy_discount: number; pace_uplift: number; weekend_uplift: number; max_change_pct: number; rounding: number }
interface Ev { id: number; name: string; start_date: string; end_date: string; impact_pct: number }
interface Comp { id: number; competitor: string; date: string; rate: number }

const today = () => new Date().toISOString().slice(0, 10);

export default function RevenueSuggestions() {
    const t = useT();
    const types = useApi<{ data: { id: number; name: string }[] }>('room-types?per_page=100');
    const sugs = useApi<{ data: Suggestion[] }>('revenue/suggestions?status=pending');
    const settings = useApi<Settings>('revenue/settings');
    const events = useApi<{ data: Ev[] }>('revenue/events');
    const comps = useApi<{ data: Comp[] }>('revenue/competitor-rates');
    const [range, setRange] = useState({ from: today(), to: today() });
    const [form, setForm] = useState<Partial<Settings>>({});
    const [ev, setEv] = useState({ name: '', start_date: '', end_date: '', impact_pct: '10' });
    const [cr, setCr] = useState({ competitor: '', date: '', rate: '' });
    const [msg, setMsg] = useState<string | null>(null);

    const run = async (fn: () => Promise<unknown>, done?: () => void) => {
        setMsg(null);
        try { await fn(); done?.(); } catch (e) { setMsg(e instanceof ApiError ? e.message : String(e)); }
    };
    const typeName = (id: number) => types.data?.data.find((x) => x.id === id)?.name ?? `#${id}`;
    const s = { ...settings.data, ...form } as Settings;
    const num = (k: keyof Settings, label: string) => (
        <label className="label">{label}
            <input className="input w-full" type="number" value={s?.[k] ?? ''} onChange={(e) => setForm({ ...form, [k]: +e.target.value })} />
        </label>
    );

    return (
        <AppLayout title={t('revenue.sug.title')}>
            <Head title={t('revenue.sug.title')} />
            <div className="mx-auto max-w-5xl space-y-4">
                <p className="text-sm text-slate-600">{t('revenue.sug.intro')}</p>
                {msg && <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">{msg}</div>}

                <section className="card space-y-3 p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="label">{t('revenue.common.from')}<input className="input" type="date" min={today()} value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></label>
                        <label className="label">{t('revenue.common.to')}<input className="input" type="date" min={range.from} value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></label>
                        <button type="button" className="btn-primary" onClick={() => run(() => api('revenue/suggestions/generate', { method: 'POST', body: JSON.stringify(range) }), sugs.reload)}>{t('revenue.sug.generate')}</button>
                    </div>
                </section>

                <section className="card p-4">
                    <h2 className="mb-2 font-medium">{t('revenue.sug.pending')}</h2>
                    {!sugs.data?.data.length ? <p className="text-sm text-slate-500">{t('revenue.common.empty')}</p> : (
                        <div className="space-y-2">
                            {sugs.data.data.map((g) => (
                                <div key={g.id} className="flex flex-col gap-2 rounded-md border border-slate-200 p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div className="font-medium">{formatDate(g.date)} · {typeName(g.room_type_id)}</div>
                                        <div>{t('revenue.sug.current')} {formatCurrency(g.current_rate)} → {t('revenue.sug.suggested')} <strong>{formatCurrency(g.suggested_rate)}</strong> ({g.change_pct > 0 ? '+' : ''}{g.change_pct}%)</div>
                                        <div className="text-slate-600">{t('revenue.sug.why')}: {g.reasons.map((r) => `${r.factor} ${r.pct > 0 ? '+' : ''}${r.pct}% (${r.detail})`).join(' · ')}</div>
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" className="btn-primary" onClick={() => run(() => api(`revenue/suggestions/${g.id}/accept`, { method: 'POST', body: '{}' }), sugs.reload)}>{t('revenue.common.accept')}</button>
                                        <button type="button" className="btn-secondary" onClick={() => run(() => api(`revenue/suggestions/${g.id}/reject`, { method: 'POST', body: '{}' }), sugs.reload)}>{t('revenue.common.reject')}</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.sug.settings')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {num('high_occupancy_pct', t('revenue.sug.high_occ'))}
                        {num('low_occupancy_pct', t('revenue.sug.low_occ'))}
                        {num('high_occupancy_uplift', t('revenue.sug.high_uplift'))}
                        {num('low_occupancy_discount', t('revenue.sug.low_discount'))}
                        {num('pace_uplift', t('revenue.sug.pace_uplift'))}
                        {num('weekend_uplift', t('revenue.sug.weekend_uplift'))}
                        {num('max_change_pct', t('revenue.sug.max_change'))}
                        {num('rounding', t('revenue.sug.rounding'))}
                    </div>
                    <button type="button" className="btn-primary" onClick={() => run(() => api('revenue/settings', { method: 'PUT', body: JSON.stringify(form) }), () => { setForm({}); settings.reload(); setMsg(null); })}>{t('revenue.common.save')}</button>
                </section>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.sug.events')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <input className="input" aria-label={t('revenue.common.name')} placeholder={t('revenue.common.name')} value={ev.name} onChange={(e) => setEv({ ...ev, name: e.target.value })} />
                        <input className="input" type="date" value={ev.start_date} onChange={(e) => setEv({ ...ev, start_date: e.target.value })} />
                        <input className="input" type="date" min={ev.start_date} value={ev.end_date} onChange={(e) => setEv({ ...ev, end_date: e.target.value })} />
                        <input className="input" type="number" title={t('revenue.sug.event_impact')} value={ev.impact_pct} onChange={(e) => setEv({ ...ev, impact_pct: e.target.value })} />
                        <button type="button" className="btn-secondary" disabled={!ev.name || !ev.start_date || !ev.end_date} onClick={() => run(() => api('revenue/events', { method: 'POST', body: JSON.stringify({ ...ev, impact_pct: +ev.impact_pct }) }), () => { setEv({ name: '', start_date: '', end_date: '', impact_pct: '10' }); events.reload(); })}>{t('revenue.common.add')}</button>
                    </div>
                    {events.data?.data.map((x) => (
                        <div key={x.id} className="flex items-center justify-between text-sm">
                            <span>{x.name} · {formatDate(x.start_date)} → {formatDate(x.end_date)} · {x.impact_pct}%</span>
                            <button type="button" className="btn-secondary" onClick={() => run(() => api(`revenue/events/${x.id}`, { method: 'DELETE' }), events.reload)}>{t('revenue.common.delete')}</button>
                        </div>
                    ))}
                </section>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.sug.competitors')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <input className="input" aria-label={t('revenue.sug.competitor')} placeholder={t('revenue.sug.competitor')} value={cr.competitor} onChange={(e) => setCr({ ...cr, competitor: e.target.value })} />
                        <input className="input" type="date" value={cr.date} onChange={(e) => setCr({ ...cr, date: e.target.value })} />
                        <input className="input" type="number" aria-label={t('revenue.common.rate')} placeholder={t('revenue.common.rate')} value={cr.rate} onChange={(e) => setCr({ ...cr, rate: e.target.value })} />
                        <button type="button" className="btn-secondary" disabled={!cr.competitor || !cr.date || !cr.rate} onClick={() => run(() => api('revenue/competitor-rates', { method: 'POST', body: JSON.stringify({ ...cr, rate: +cr.rate }) }), () => { setCr({ competitor: '', date: '', rate: '' }); comps.reload(); })}>{t('revenue.common.add')}</button>
                    </div>
                    {comps.data?.data.map((x) => (
                        <div key={x.id} className="flex items-center justify-between text-sm">
                            <span>{x.competitor} · {formatDate(x.date)} · {formatCurrency(x.rate)}</span>
                            <button type="button" className="btn-secondary" onClick={() => run(() => api(`revenue/competitor-rates/${x.id}`, { method: 'DELETE' }), comps.reload)}>{t('revenue.common.delete')}</button>
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
