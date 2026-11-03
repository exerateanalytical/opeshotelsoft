import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import CashierBar from '@/Components/CashierBar';
import { DoorOpen, DoorClosed, BedDouble, Loader2, Moon } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

/** Displayed label for a housekeeping status (the raw value stays as is). */
function hkLabel(t: (k: string) => string, s: string) {
    const v = t(`fo.hk.${s}`);
    return v === `fo.hk.${s}` ? s.replace(/_/g, ' ') : v;
}

interface Room { id: number; number: string; hk_status: string }

/** Vacant rooms of the booked type; choosing one checks the guest straight in. */
function RoomPicker({ reservation, onPicked }: { reservation: Reservation; onPicked: (roomId: number) => void }) {
    const t = useT();
    const rooms = useApi<{ data: Room[] }>(`rooms?filter[room_type_id]=${reservation.room_type_id}&filter[occupied]=0&filter[active]=1&per_page=200`);
    return (
        <select className="select w-44" aria-label={t('fo.desk.assign_room')} defaultValue="" onChange={(e) => e.target.value && onPicked(Number(e.target.value))}>
            <option value="" disabled>{rooms.loading ? t('common.loading') : t('fo.desk.assign_room')}</option>
            {rooms.data?.data.map((rm) => (
                <option key={rm.id} value={rm.id}>{rm.number} · {hkLabel(t, rm.hk_status)}</option>
            ))}
        </select>
    );
}

interface Reservation {
    id: number;
    room_type_id: number;
    number: string;
    arrival: string;
    departure: string;
    status: string;
    guest: { first_name: string; last_name: string; vip: boolean } | null;
    room: { number: string } | null;
    room_type: { code: string } | null;
}

interface Board {
    business_date: string;
    arrivals: Reservation[];
    departures: Reservation[];
    in_house: number;
    rooms: Record<string, number>;
    occupied: number;
    total_rooms: number;
}

interface AuditCheck { arrivals_not_checked_in: number; departures_not_checked_out: number; open_cashier_shifts: number }

export default function FrontDesk() {
    const t = useT();
    const board = useApi<Board>('front-desk');
    const [busy, setBusy] = useState<string | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    async function run(key: string, path: string, body: object = {}, confirmText?: string) {
        if (confirmText && !confirm(confirmText)) return;
        setBusy(key);
        setMessage(null);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            setMessage({ ok: true, text: t('fo.desk.done') });
            board.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        } finally {
            setBusy(null);
        }
    }

    async function nightAudit() {
        try {
            const check = await api<AuditCheck>('night-audit');
            const issues = [
                check.arrivals_not_checked_in && t('fo.desk.audit_arrivals', { count: check.arrivals_not_checked_in }),
                check.departures_not_checked_out && t('fo.desk.audit_departures', { count: check.departures_not_checked_out }),
                check.open_cashier_shifts && t('fo.desk.audit_shifts', { count: check.open_cashier_shifts }),
            ].filter(Boolean).join('\n');
            await run('audit', 'night-audit', { mark_no_shows: true },
                t('fo.desk.audit_confirm', { date: board.data?.business_date ?? '' }) + (issues ? `\n\n${t('fo.desk.audit_issues')}\n${issues}` : ''));
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        }
    }

    const b = board.data;
    const list = (title: string, icon: React.ReactNode, rows: Reservation[], action: 'check-in' | 'check-out') => (
        <div className="card">
            <div className="flex items-center gap-2 px-5 py-4 border-b border-slate-200">
                {icon}<h3 className="font-semibold text-slate-800">{title}</h3>
                <span className="ml-auto text-sm text-slate-500">{rows.length}</span>
            </div>
            {rows.length === 0 ? <p className="px-5 py-10 text-center text-sm text-slate-500">{t('fo.desk.none_today')}</p> : (
                <ul className="divide-y divide-slate-100">
                    {rows.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                            <div className="min-w-0 flex-1 basis-48">
                                <p className="font-medium text-slate-900 truncate">
                                    <Link href={`/reservations/${r.id}`} className="underline-offset-2 hover:underline">{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '—'}</Link>{r.guest?.vip && <span className="ml-1 text-amber-700" aria-label="VIP">★</span>}
                                </p>
                                <p className="text-sm text-slate-600">
                                    {r.number} · {r.room ? t('fo.desk.room_n', { number: r.room.number }) : t('fo.desk.type_unassigned', { type: r.room_type?.code ?? '' })} · {t('fo.desk.until', { date: formatDate(r.departure) })}
                                </p>
                            </div>
                            {action === 'check-in' && !r.room && (
                                <RoomPicker reservation={r} onPicked={(roomId) => run(`check-in-${r.id}`, `reservations/${r.id}/check-in`, { room_id: roomId })} />
                            )}
                            <button type="button" className="btn-primary min-w-28" disabled={busy === `${action}-${r.id}` || (action === 'check-in' && !r.room)}
                                onClick={() => run(`${action}-${r.id}`, `reservations/${r.id}/${action}`)}>
                                {busy === `${action}-${r.id}` ? <Loader2 className="w-4 h-4 animate-spin" aria-label="..." /> : action === 'check-in' ? t('fo.desk.check_in') : t('fo.desk.check_out')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );

    return (
        <AppLayout
            title={t('fo.desk.title')}
            subtitle={b ? t('fo.desk.business_date', { date: formatDate(b.business_date) }) : t('fo.desk.subtitle')}
            actions={
                <div className="btn-row">
                    <Link href="/front-desk/in-house" className="btn-secondary"><BedDouble className="w-4 h-4" /> {t('fo.desk.in_house')}</Link>
                    <button type="button" className="btn-primary" onClick={nightAudit} disabled={busy === 'audit' || !b}>
                        {busy === 'audit' ? <Loader2 className="w-4 h-4 animate-spin" /> : <Moon className="w-4 h-4" />} {t('fo.desk.night_audit')}
                    </button>
                </div>
            }
        >
            <Head title={t('fo.desk.title')} />
            <CashierBar />

            {(message || board.error) && (
                <div role={message?.ok ? 'status' : 'alert'} className={`mb-4 rounded-lg text-sm px-4 py-3 ${message?.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>
                    {message?.text ?? board.error?.message}
                </div>
            )}

            {!b ? <Loader2 className="w-6 h-6 mx-auto mt-16 animate-spin text-slate-500" aria-label="..." /> : (
                <>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        {[
                            [t('fo.desk.arrivals'), b.arrivals.length], [t('fo.desk.departures'), b.departures.length], [t('fo.desk.in_house'), b.in_house],
                            [t('fo.desk.occupied'), `${b.occupied} / ${b.total_rooms}`],
                        ].map(([label, value]) => (
                            <div key={label as string} className="card px-5 py-4">
                                <p className="text-sm font-medium text-slate-600">{label}</p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
                            </div>
                        ))}
                    </div>
                    <div className="grid lg:grid-cols-2 gap-6 mb-6">
                        {list(t('fo.desk.arrivals'), <DoorOpen className="w-4 h-4 text-emerald-700" aria-hidden="true" />, b.arrivals, 'check-in')}
                        {list(t('fo.desk.departures'), <DoorClosed className="w-4 h-4 text-amber-700" aria-hidden="true" />, b.departures, 'check-out')}
                    </div>
                    <div className="card px-5 py-4">
                        <div className="flex items-center gap-2 mb-3"><BedDouble className="w-4 h-4 text-slate-500" /><h3 className="font-semibold text-slate-800">{t('fo.desk.room_status')}</h3></div>
                        <div className="flex flex-wrap gap-3">
                            {Object.entries(b.rooms).map(([s, n]) => (
                                <span key={s} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-700"><b>{n}</b> {hkLabel(t, s)}</span>
                            ))}
                        </div>
                    </div>
                </>
            )}
        </AppLayout>
    );
}
