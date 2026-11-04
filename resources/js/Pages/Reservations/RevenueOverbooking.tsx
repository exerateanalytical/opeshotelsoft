import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';
import { UpsellOffersSection, WalksSection } from '@/Components/gape/WalksAndUpsells';

interface Policy { room_type_id: number; room_type: string; max_rooms: number; max_percent: number; cap: number }
interface Exposure { room_type: string; date: string; rooms: number; booked: number; over: number }
interface Settings { walk_policy: string | null; walk_compensation: number; walk_partner_hotel: string | null; upsell_discount_pct: Record<string, number> | null }

const today = () => new Date().toISOString().slice(0, 10);
const plus = (n: number) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);

export default function RevenueOverbooking() {
    const t = useT();
    const policies = useApi<{ data: Policy[] }>('revenue/overbooking');
    const exposure = useApi<{ data: Exposure[] }>(`revenue/overbooking/exposure?from=${today()}&to=${plus(60)}`);
    const settings = useApi<Settings>('revenue/settings');
    const [edit, setEdit] = useState<Record<number, { max_rooms: string; max_percent: string }>>({});
    const [apply, setApply] = useState({ room_type_id: '', from: today(), to: today(), limit: '1' });
    const [walk, setWalk] = useState({ walk_policy: '', walk_compensation: '0', walk_partner_hotel: '' });
    const [up, setUp] = useState({ booking: '0', pre_arrival: '10', check_in: '15' });
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

    useEffect(() => {
        const s = settings.data;
        if (!s) return;
        setWalk({ walk_policy: s.walk_policy ?? '', walk_compensation: String(s.walk_compensation), walk_partner_hotel: s.walk_partner_hotel ?? '' });
        const d = s.upsell_discount_pct ?? {};
        setUp({ booking: String(d.booking ?? 0), pre_arrival: String(d.pre_arrival ?? 10), check_in: String(d.check_in ?? 15) });
    }, [settings.data]);

    const run = async (fn: () => Promise<unknown>, ok?: string, done?: () => void) => {
        try { await fn(); setMsg({ ok: true, text: ok ?? t('revenue.common.saved') }); done?.(); } catch (e) { setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) }); }
    };

    return (
        <AppLayout title={t('revenue.ob.title')}>
            <Head title={t('revenue.ob.title')} />
            <div className="mx-auto max-w-4xl space-y-4">
                {msg && <Alert variant={msg.ok ? 'success' : 'danger'}>{msg.text}</Alert>}

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.ob.policies')}</h2>
                    <QueryStatus query={policies} variant="form" rows={3} />
                    {policies.data?.data.length === 0 && <EmptyState title={t('states3.empty.policies.title')} description={t('states3.empty.policies.text')} action={<a href="/properties" className="btn-secondary">{t('states3.empty.outlets.action')}</a>} />}
                    {policies.data?.data.map((x) => {
                        const e = edit[x.room_type_id] ?? { max_rooms: String(x.max_rooms), max_percent: String(x.max_percent) };
                        return (
                            <div key={x.room_type_id} className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <div className="text-sm font-medium">{x.room_type}</div>
                                <label className="label">{t('revenue.ob.max_rooms')}<input className="input w-full" type="number" value={e.max_rooms} onChange={(ev) => setEdit({ ...edit, [x.room_type_id]: { ...e, max_rooms: ev.target.value } })} /></label>
                                <label className="label">{t('revenue.ob.max_percent')}<input className="input w-full" type="number" value={e.max_percent} onChange={(ev) => setEdit({ ...edit, [x.room_type_id]: { ...e, max_percent: ev.target.value } })} /></label>
                                <div className="text-sm">{t('revenue.ob.cap')}: {x.cap}</div>
                                <button type="button" className="btn-secondary" onClick={() => run(() => api(`revenue/overbooking/policies/${x.room_type_id}`, { method: 'PUT', body: JSON.stringify({ max_rooms: +e.max_rooms || 0, max_percent: +e.max_percent || 0 }) }), undefined, policies.reload)}>{t('revenue.common.save')}</button>
                            </div>
                        );
                    })}
                    <h3 className="pt-2 text-sm font-medium">{t('revenue.ob.apply')}</h3>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <select className="select" value={apply.room_type_id} onChange={(e) => setApply({ ...apply, room_type_id: e.target.value })} aria-label={t('revenue.common.room_type')}>
                            <option value="">{t('revenue.common.room_type')}</option>
                            {policies.data?.data.map((x) => <option key={x.room_type_id} value={x.room_type_id}>{x.room_type}</option>)}
                        </select>
                        <input className="input" type="date" aria-label={t('revenue.common.from')} value={apply.from} onChange={(e) => setApply({ ...apply, from: e.target.value })} />
                        <input className="input" type="date" aria-label={t('revenue.common.to')} min={apply.from} value={apply.to} onChange={(e) => setApply({ ...apply, to: e.target.value })} />
                        <input className="input" type="number" aria-label={t('revenue.ob.limit')} title={t('revenue.ob.limit')} value={apply.limit} onChange={(e) => setApply({ ...apply, limit: e.target.value })} />
                        <button type="button" className="btn-primary" disabled={!apply.room_type_id} onClick={async () => {
                            try {
                                const r = await api<{ applied: number }>('revenue/overbooking/apply', { method: 'POST', body: JSON.stringify({ ...apply, room_type_id: +apply.room_type_id, limit: +apply.limit }) });
                                setMsg({ ok: true, text: t('revenue.ob.applied', { n: r.applied }) });
                                exposure.reload();
                            } catch (e) { setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) }); }
                        }}>{t('revenue.common.save')}</button>
                    </div>
                </section>

                <section className="card p-4">
                    <h2 className="mb-2 font-medium">{t('revenue.ob.exposure')}</h2>
                    <QueryStatus query={exposure} variant="list" rows={2} />
                    {exposure.data && !exposure.data.data.length ? <p className="text-sm text-slate-500">{t('revenue.common.empty')}</p> : exposure.data?.data.map((x, i) => (
                        <div key={i} className="text-sm">{formatDate(x.date)} · {x.room_type} · {x.booked}/{x.rooms} · {t('revenue.ob.over')} {x.over}</div>
                    ))}
                </section>

                <WalksSection notify={(ok, text) => setMsg({ ok, text })} defaults={{ partner: walk.walk_partner_hotel, compensation: walk.walk_compensation }} />
                <UpsellOffersSection notify={(ok, text) => setMsg({ ok, text })} />

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.ob.walk_policy')}</h2>
                    <textarea className="textarea" rows={3} aria-label={t('revenue.ob.walk_policy')} value={walk.walk_policy} onChange={(e) => setWalk({ ...walk, walk_policy: e.target.value })} />
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label className="label">{t('revenue.ob.walk_comp')}<input className="input w-full" type="number" value={walk.walk_compensation} onChange={(e) => setWalk({ ...walk, walk_compensation: e.target.value })} /></label>
                        <label className="label">{t('revenue.ob.walk_partner')}<input className="input w-full" value={walk.walk_partner_hotel} onChange={(e) => setWalk({ ...walk, walk_partner_hotel: e.target.value })} /></label>
                    </div>
                    <h2 className="pt-2 font-medium">{t('revenue.ob.upsell')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {(['booking', 'pre_arrival', 'check_in'] as const).map((k) => (
                            <label key={k} className="label">{t(`revenue.ob.stage.${k}`)}<input className="input w-full" type="number" value={up[k]} onChange={(e) => setUp({ ...up, [k]: e.target.value })} /></label>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="btn-primary" onClick={() => run(() => api('revenue/settings', { method: 'PUT', body: JSON.stringify({ walk_policy: walk.walk_policy || null, walk_compensation: +walk.walk_compensation || 0, walk_partner_hotel: walk.walk_partner_hotel || null, upsell_discount_pct: { booking: +up.booking, pre_arrival: +up.pre_arrival, check_in: +up.check_in } }) }), undefined, settings.reload)}>{t('revenue.common.save')}</button>
                        <button type="button" className="btn-secondary" onClick={() => run(() => api('revenue/upsell-offers/pre-arrival-run', { method: 'POST', body: '{}' }))}>{t('revenue.ob.prearrival_run')}</button>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
