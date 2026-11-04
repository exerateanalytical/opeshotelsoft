import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface RoomType { id: number; code: string; name: string }
interface RatePlan { id: number; code: string; name: string }
interface Result { booking_ref: string; total: number; deposit_required: number; payer: { first_name: string; last_name: string }; rooms: { id: number; number: string; guest: { first_name: string; last_name: string }; room_type: { name: string }; total_amount: number }[] }
interface Page<T> { data: T[] }

const iso = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);
const blank = () => ({ room_type_id: 0, rate_plan_id: 0, adults: 1, first_name: '', last_name: '' });

export default function GroupBooking() {
    const t = useT();
    const types = useApi<Page<RoomType> | RoomType[]>('room-types');
    const plans = useApi<Page<RatePlan> | RatePlan[]>('rate-plans');
    const list = <T,>(d?: Page<T> | T[] | null): T[] => (Array.isArray(d) ? d : d?.data ?? []);
    const [payer, setPayer] = useState({ first_name: '', last_name: '', email: '', phone: '' });
    const [dates, setDates] = useState({ arrival: iso(1), departure: iso(3) });
    const [rooms, setRooms] = useState([blank()]);
    const [route, setRoute] = useState(true);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<Result | null>(null);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault(); setBusy(true); setError('');
        try {
            setResult(await api<Result>('experience/bookings', { method: 'POST', body: JSON.stringify({
                payer: { ...payer, email: payer.email || null, phone: payer.phone || null }, ...dates, route_to_payer: route,
                rooms: rooms.map((r) => ({ room_type_id: r.room_type_id || list(types.data)[0]?.id, rate_plan_id: r.rate_plan_id || list(plans.data)[0]?.id, adults: r.adults, guest: r.last_name ? { first_name: r.first_name || '-', last_name: r.last_name } : undefined })),
            }) }));
        } catch (err) { setError(err instanceof ApiError ? err.message : String(err)); }
        setBusy(false);
    };
    const set = (i: number, patch: Partial<ReturnType<typeof blank>>) => setRooms(rooms.map((r, j) => (i === j ? { ...r, ...patch } : r)));

    return (
        <AppLayout title={t('experience.group.title')}>
            <Head title={t('experience.group.title')} />
            <form onSubmit={submit} className="space-y-4">
                {error && <Alert variant="danger">{error}</Alert>}
                {result && (
                    <Alert variant="success">
                        {t('experience.group.done', { ref: result.booking_ref, count: result.rooms.length, total: result.total.toLocaleString() })}
                    </Alert>
                )}
                <div className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <h2 className="font-semibold sm:col-span-4">{t('experience.group.payer')}</h2>
                    <div><label className="label" htmlFor="pf">{t('experience.group.first_name')}</label><input id="pf" className="input" value={payer.first_name} onChange={(e) => setPayer({ ...payer, first_name: e.target.value })} required /></div>
                    <div><label className="label" htmlFor="pl">{t('experience.group.last_name')}</label><input id="pl" className="input" value={payer.last_name} onChange={(e) => setPayer({ ...payer, last_name: e.target.value })} required /></div>
                    <div><label className="label" htmlFor="pe">{t('experience.group.email')}</label><input id="pe" type="email" className="input" value={payer.email} onChange={(e) => setPayer({ ...payer, email: e.target.value })} /></div>
                    <div><label className="label" htmlFor="pp">{t('experience.group.phone')}</label><input id="pp" className="input" value={payer.phone} onChange={(e) => setPayer({ ...payer, phone: e.target.value })} /></div>
                    <div><label className="label" htmlFor="ga">{t('experience.group.arrival')}</label><input id="ga" type="date" className="input" value={dates.arrival} onChange={(e) => setDates({ ...dates, arrival: e.target.value })} required /></div>
                    <div><label className="label" htmlFor="gd">{t('experience.group.departure')}</label><input id="gd" type="date" className="input" value={dates.departure} onChange={(e) => setDates({ ...dates, departure: e.target.value })} required /></div>
                    <label className="min-h-11 flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={route} onChange={(e) => setRoute(e.target.checked)} />{t('experience.group.route')}</label>
                </div>
                <div className="card space-y-3 p-4">
                    <h2 className="font-semibold">{t('experience.group.rooms')}</h2>
                    <QueryStatus query={types} variant="form" rows={1} />
                    <QueryStatus query={plans} variant="form" rows={1} />
                    {types.data && list(types.data).length === 0 && <EmptyState title={t('states3.empty.group_types.title')} description={t('states3.empty.group_types.text')} action={<a href="/properties" className="btn-primary">{t('states3.empty.outlets.action')}</a>} />}
                    {rooms.map((r, i) => (
                        <div key={i} className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            <select aria-label={t('experience.group.room_type')} className="select" value={r.room_type_id} onChange={(e) => set(i, { room_type_id: +e.target.value })}>
                                {list(types.data).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                            </select>
                            <select aria-label={t('experience.group.rate_plan')} className="select" value={r.rate_plan_id} onChange={(e) => set(i, { rate_plan_id: +e.target.value })}>
                                {list(plans.data).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                            </select>
                            <input aria-label={t('experience.group.adults')} type="number" min={1} max={8} className="input" value={r.adults} onChange={(e) => set(i, { adults: +e.target.value })} />
                            <input aria-label={t('experience.group.first_name')} placeholder={t('experience.group.first_name')} className="input" value={r.first_name} onChange={(e) => set(i, { first_name: e.target.value })} />
                            <input aria-label={t('experience.group.last_name')} placeholder={t('experience.group.last_name')} className="input" value={r.last_name} onChange={(e) => set(i, { last_name: e.target.value })} />
                            <button type="button" className="btn-secondary" disabled={rooms.length === 1} onClick={() => setRooms(rooms.filter((_, j) => j !== i))}>{t('experience.group.remove')}</button>
                        </div>
                    ))}
                    <button type="button" className="btn-secondary" onClick={() => setRooms([...rooms, blank()])}>{t('experience.group.add_room')}</button>
                </div>
                <button type="submit" className="btn-primary" disabled={busy || !types.data || list(types.data).length === 0}>{t('experience.group.book')}</button>
            </form>
        </AppLayout>
    );
}
