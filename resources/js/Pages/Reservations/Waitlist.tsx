import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head, Link } from '@inertiajs/react';
import { Loader2, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Entry {
    id: number; status: string; arrival: string; departure: string; adults: number; children: number; notes: string | null;
    available: boolean; reservation_id: number | null;
    guest: { id: number; first_name: string; last_name: string; phone: string | null; email: string | null } | null;
    room_type: { id: number; code: string; name: string } | null;
    rate_plan: { id: number; code: string; name: string } | null;
}
interface Guest { id: number; first_name: string; last_name: string; email: string | null }
interface RoomType { id: number; code: string; name: string }
interface RatePlan { id: number; code: string; name: string }
interface Page<T> { data: T[] }

const BADGE: Record<string, string> = { waiting: 'badge-yellow', offered: 'badge-blue', converted: 'badge-green', cancelled: 'badge-slate' };
const iso = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);

export default function Waitlist() {
    const t = useT();
    const [status, setStatus] = useState('');
    const list = useApi<Page<Entry>>(`waitlist${status ? `?status=${status}` : ''}`);
    const types = useApi<Page<RoomType>>('room-types?per_page=100');
    const plans = useApi<Page<RatePlan>>('rate-plans?per_page=100&filter[active]=1');
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState<number | null>(null);

    const [guestQ, setGuestQ] = useState('');
    const [hits, setHits] = useState<Guest[]>([]);
    const [form, setForm] = useState({ guest: null as Guest | null, room_type_id: '', rate_plan_id: '', arrival: iso(1), departure: iso(2), adults: 1, notes: '' });

    useEffect(() => {
        if (guestQ.trim().length < 2) return setHits([]);
        const tm = setTimeout(() => api<Page<Guest>>(`guests?q=${encodeURIComponent(guestQ.trim())}&per_page=8`).then((r) => setHits(r.data)).catch(() => setHits([])), 250);
        return () => clearTimeout(tm);
    }, [guestQ]);

    const fail = (e: unknown) => setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });

    async function add() {
        if (!form.guest || !form.room_type_id) return;
        setMessage(null);
        try {
            await api('waitlist', {
                method: 'POST',
                body: JSON.stringify({
                    guest_id: form.guest.id, room_type_id: +form.room_type_id, rate_plan_id: form.rate_plan_id ? +form.rate_plan_id : null,
                    arrival: form.arrival, departure: form.departure, adults: form.adults, notes: form.notes || null,
                }),
            });
            setForm({ ...form, guest: null, notes: '' });
            setMessage({ ok: true, text: t('res.waitlist.added') });
            list.reload();
        } catch (e) {
            fail(e);
        }
    }

    async function act(e: Entry, action: 'offer' | 'convert' | 'cancel', ratePlanId?: number) {
        setBusy(e.id);
        setMessage(null);
        try {
            const r = await api<{ reservation?: { id: number; number: string } }>(`waitlist/${e.id}/${action}`, {
                method: 'POST', body: JSON.stringify(ratePlanId ? { rate_plan_id: ratePlanId } : {}),
            });
            setMessage({ ok: true, text: action === 'convert' ? t('res.waitlist.booked_as', { number: r.reservation?.number ?? '' }) : action === 'offer' ? t('res.waitlist.offered_msg') : t('res.waitlist.cancelled_msg') });
            list.reload();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(null);
        }
    }

    const [convertPlan, setConvertPlan] = useState<Record<number, string>>({});

    return (
        <AppLayout title={t('res.waitlist.title')}>
            <Head title={t('res.waitlist.title')} />
            <QueryStatus query={list} variant="table" />
            <div className="mx-auto max-w-5xl space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href="/reservations" className="btn-secondary">{t('res.title')}</Link>
                </div>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('res.waitlist.add')}</h2>
                    {form.guest ? (
                        <div className="flex items-center justify-between text-sm">
                            <span>{form.guest.first_name} {form.guest.last_name}</span>
                            <button type="button" className="btn-secondary" onClick={() => setForm({ ...form, guest: null })}>{t('res.create.change')}</button>
                        </div>
                    ) : (
                        <div>
                            <div className="relative">
                                <Search className="absolute left-2 top-2.5 h-4 w-4 text-slate-500" />
                                <input className="input w-full pl-8" aria-label={t('res.waitlist.search_guest')} placeholder={t('res.waitlist.search_guest')} value={guestQ} onChange={(e) => setGuestQ(e.target.value)} />
                            </div>
                            {hits.map((g) => (
                                <button key={g.id} type="button" className="block w-full px-2 py-1 text-left text-sm hover:bg-slate-50" onClick={() => (setForm({ ...form, guest: g }), setGuestQ(''))}>
                                    {g.first_name} {g.last_name} <span className="text-slate-500">{g.email ?? ''}</span>
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label className="label">{t('res.room_type')}
                            <select className="select w-full" value={form.room_type_id} onChange={(e) => setForm({ ...form, room_type_id: e.target.value })}>
                                <option value="">{t('res.choose')}</option>
                                {types.data?.data.map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
                            </select>
                        </label>
                        <label className="label">{t('res.rate_plan')}
                            <select className="select w-full" value={form.rate_plan_id} onChange={(e) => setForm({ ...form, rate_plan_id: e.target.value })}>
                                <option value="">{t('res.waitlist.decide_later')}</option>
                                {plans.data?.data.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </label>
                        <label className="label">{t('res.adults')}<input className="input w-full" type="number" min={1} max={12} value={form.adults} onChange={(e) => setForm({ ...form, adults: Math.max(1, +e.target.value) })} /></label>
                        <label className="label">{t('res.arrival')}<input className="input w-full" type="date" value={form.arrival} onChange={(e) => setForm({ ...form, arrival: e.target.value })} /></label>
                        <label className="label">{t('res.departure')}<input className="input w-full" type="date" min={form.arrival} value={form.departure} onChange={(e) => setForm({ ...form, departure: e.target.value })} /></label>
                        <label className="label">{t('res.notes')}<input className="input w-full" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label>
                    </div>
                    <button type="button" className="btn-primary" disabled={!form.guest || !form.room_type_id} onClick={add}>{t('res.waitlist.add')}</button>
                </section>

                {message && <Alert variant={message.ok ? 'success' : 'danger'}>{message.text}</Alert>}

                <section className="card p-4">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-medium">{t('res.waitlist.entries')}</h2>
                        <select className="select" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t('res.waitlist.open')}>
                            <option value="">{t('res.waitlist.open')}</option>
                            {['waiting', 'offered', 'converted', 'cancelled'].map((s) => <option key={s} value={s}>{t(`res.waitlist.status.${s}`)}</option>)}
                        </select>
                    </div>
                    {list.loading && !list.data ? <Loader2 className="h-5 w-5 animate-spin" /> : list.data?.data.length === 0 ? (
                        <p className="text-sm text-slate-500">{t('res.waitlist.empty')}</p>
                    ) : (
                        <div className="space-y-2">
                            {list.data?.data.map((e) => (
                                <div key={e.id} className="flex flex-col gap-2 rounded-md border border-slate-200 p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div className="font-medium">{e.guest?.first_name} {e.guest?.last_name} <span className={BADGE[e.status] ?? 'badge-slate'}>{t(`res.waitlist.status.${e.status}`)}</span></div>
                                        <div className="text-slate-600">{e.room_type?.name} · {formatDate(e.arrival)} → {formatDate(e.departure)} · {t('res.waitlist.adults_short', { count: e.adults })}{e.rate_plan ? ` · ${e.rate_plan.code}` : ''}</div>
                                        {e.guest?.phone && <div className="text-slate-500">{e.guest.phone}</div>}
                                        {e.notes && <div className="text-slate-500">{e.notes}</div>}
                                        {['waiting', 'offered'].includes(e.status) && (
                                            <div className={cn('text-xs', e.available ? 'text-emerald-700' : 'text-slate-500')}>{e.available ? t('res.waitlist.available') : t('res.waitlist.still_sold_out')}</div>
                                        )}
                                        {e.reservation_id && <Link className="text-navy-600 underline" href={`/reservations/${e.reservation_id}`}>{t('res.waitlist.open_booking')}</Link>}
                                    </div>
                                    {['waiting', 'offered'].includes(e.status) && (
                                        <div className="flex flex-wrap items-center gap-2">
                                            {e.status === 'waiting' && <button type="button" className="btn-secondary" disabled={busy === e.id || !e.available} onClick={() => act(e, 'offer')}>{t('res.waitlist.offer')}</button>}
                                            {!e.rate_plan && (
                                                <select className="select" value={convertPlan[e.id] ?? ''} onChange={(ev) => setConvertPlan({ ...convertPlan, [e.id]: ev.target.value })} aria-label={t('res.rate_plan')}>
                                                    <option value="">{t('res.rate_plan')}</option>
                                                    {plans.data?.data.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
                                                </select>
                                            )}
                                            <button type="button" className="btn-primary" disabled={busy === e.id || !e.available || (!e.rate_plan && !convertPlan[e.id])}
                                                onClick={() => act(e, 'convert', convertPlan[e.id] ? +convertPlan[e.id] : undefined)}>{t('res.create.book')}</button>
                                            <button type="button" className="btn-secondary" disabled={busy === e.id} onClick={() => act(e, 'cancel')}>{t('res.waitlist.remove')}</button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
