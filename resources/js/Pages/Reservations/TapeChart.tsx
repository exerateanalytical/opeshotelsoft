import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Room { id: number; number: string; hk_status: string; room_type_id: number; room_type: { id: number; code: string; name: string } | null }
interface Bar { id: number; number: string; status: string; room_id: number | null; room_type_id: number; arrival: string; departure: string; guest: string; vip: boolean }
interface Block { room_id: number; from: string; to: string; reason: string }
interface Feed { from: string; to: string; rooms: Room[]; bars: Bar[]; out_of_order: Block[] }

const COL = 44; // px per day
const DAY = 86_400_000;
const iso = (d: Date) => d.toISOString().slice(0, 10);
const shift = (s: string, n: number) => iso(new Date(Date.parse(s) + n * DAY));
const diff = (a: string, b: string) => Math.round((Date.parse(b) - Date.parse(a)) / DAY);

const BAR: Record<string, string> = {
    tentative: 'bg-amber-100 text-amber-900 border-amber-300',
    confirmed: 'bg-blue-100 text-blue-900 border-blue-300',
    checked_in: 'bg-emerald-100 text-emerald-900 border-emerald-300',
    checked_out: 'bg-slate-100 text-slate-600 border-slate-300',
};

/** Greedy lane packing so overlapping unassigned bars do not sit on top of each other. */
function lanes(bars: Bar[]): Bar[][] {
    const out: Bar[][] = [];
    for (const b of [...bars].sort((x, y) => x.arrival.localeCompare(y.arrival))) {
        const lane = out.find((l) => l[l.length - 1].departure <= b.arrival);
        lane ? lane.push(b) : out.push([b]);
    }
    return out.length ? out : [[]];
}

export default function TapeChart() {
    const t = useT();
    const [from, setFrom] = useState(iso(new Date()));
    const [span, setSpan] = useState<14 | 30>(14);
    const to = shift(from, span);
    const feed = useApi<Feed>(`reservations/tape-chart?from=${from}&to=${to}`);
    const [dragId, setDragId] = useState<number | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [move, setMove] = useState<{ bar: Bar; room: Room; reason: string } | null>(null);
    const [busy, setBusy] = useState(false);

    const days = useMemo(() => Array.from({ length: span }, (_, i) => shift(from, i)), [from, span]);
    const groups = useMemo(() => {
        const g = new Map<number, { type: Room['room_type']; rooms: Room[] }>();
        for (const r of feed.data?.rooms ?? []) {
            if (!g.has(r.room_type_id)) g.set(r.room_type_id, { type: r.room_type, rooms: [] });
            g.get(r.room_type_id)!.rooms.push(r);
        }
        return [...g.entries()];
    }, [feed.data]);

    const bars = feed.data?.bars ?? [];
    const blocks = feed.data?.out_of_order ?? [];

    async function assign(bar: Bar, room: Room, reason?: string) {
        setBusy(true);
        setMessage(null);
        try {
            if (bar.status === 'checked_in') {
                await api(`reservations/${bar.id}/move-room`, { method: 'POST', body: JSON.stringify({ room_id: room.id, reason }) });
            } else {
                await api(`reservations/${bar.id}/assign-room`, { method: 'POST', body: JSON.stringify({ room_id: room.id }) });
            }
            setMessage({ ok: true, text: t('res.tape.moved', { number: bar.number, room: room.number }) });
            setMove(null);
            feed.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        } finally {
            setBusy(false);
        }
    }

    function drop(room: Room) {
        const bar = bars.find((b) => b.id === dragId);
        setDragId(null);
        if (!bar || bar.room_id === room.id) return;
        if (bar.status === 'checked_out') return setMessage({ ok: false, text: t('res.tape.checked_out_locked') });
        if (bar.status === 'checked_in') return setMove({ bar, room, reason: '' });
        assign(bar, room);
    }

    function renderBar(b: Bar) {
        const start = Math.max(0, diff(from, b.arrival));
        const end = Math.min(span, diff(from, b.departure));
        if (end <= 0 || start >= span) return null;
        return (
            <button
                key={b.id}
                type="button"
                draggable={b.status !== 'checked_out'}
                onDragStart={(e) => (setDragId(b.id), e.dataTransfer.setData('text/plain', String(b.id)))}
                onDragEnd={() => setDragId(null)}
                onClick={() => router.visit(`/reservations/${b.id}`)}
                title={`${b.number} · ${b.guest} · ${b.arrival} → ${b.departure} · ${t(`res.status.${b.status}`)}`}
                className={cn('absolute top-1 bottom-1 truncate rounded border px-1 text-left text-xs', BAR[b.status] ?? BAR.confirmed, dragId === b.id && 'opacity-50')}
                style={{ left: start * COL + 1, width: (end - start) * COL - 2 }}
            >
                {b.vip && '★ '}{b.guest || b.number}
            </button>
        );
    }

    function renderBlock(k: Block, i: number) {
        const start = Math.max(0, diff(from, k.from));
        const end = Math.min(span, diff(from, k.to));
        if (end <= 0 || start >= span) return null;
        return (
            <div key={`ooo-${i}`} title={k.reason} className="absolute top-1 bottom-1 truncate rounded border border-red-300 bg-red-50 px-1 text-xs text-red-800"
                style={{ left: start * COL + 1, width: (end - start) * COL - 2 }}>
                {t('res.tape.ooo')} {k.reason}
            </div>
        );
    }

    const width = span * COL;

    return (
        <AppLayout>
            <Head title={t('res.tape.title')} />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold">{t('res.tape.title')}</h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="button" className="btn-secondary" aria-label={t('common.previous')} onClick={() => setFrom(shift(from, -7))}><ChevronLeft className="h-4 w-4" /></button>
                        <input type="date" className="input" value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} />
                        <button type="button" className="btn-secondary" aria-label={t('common.next')} onClick={() => setFrom(shift(from, 7))}><ChevronRight className="h-4 w-4" /></button>
                        <select className="input" value={span} onChange={(e) => setSpan(+e.target.value as 14 | 30)}>
                            <option value={14}>{t('res.tape.days', { count: 14 })}</option>
                            <option value={30}>{t('res.tape.days', { count: 30 })}</option>
                        </select>
                        <Link href="/reservations" className="btn-secondary">{t('res.tape.list')}</Link>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2 text-xs">
                    {Object.entries(BAR).map(([s, c]) => <span key={s} className={cn('rounded border px-2 py-0.5', c)}>{t(`res.status.${s}`)}</span>)}
                    <span className="rounded border border-red-300 bg-red-50 px-2 py-0.5 text-red-800">{t('res.tape.out_of_order')}</span>
                    <span className="text-gray-500">{t('res.tape.hint')}</span>
                </div>

                {message && <div className={cn('rounded-md border p-3 text-sm', message.ok ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-red-300 bg-red-50 text-red-800')}>{message.text}</div>}

                {move && (
                    <div className="card space-y-2 p-4">
                        <p className="text-sm">{t('res.tape.move_confirm', { guest: move.bar.guest, number: move.bar.number, room: move.room.number })}</p>
                        <label className="label" htmlFor="move-reason">{t('res.reason')}</label>
                        <input id="move-reason" className="input w-full" value={move.reason} onChange={(e) => setMove({ ...move, reason: e.target.value })} placeholder={t('res.tape.reason_ph')} />
                        <div className="flex gap-2">
                            <button type="button" className="btn-primary" disabled={busy || !move.reason.trim()} onClick={() => assign(move.bar, move.room, move.reason.trim())}>{t('res.tape.move_guest')}</button>
                            <button type="button" className="btn-secondary" onClick={() => setMove(null)}>{t('common.cancel')}</button>
                        </div>
                    </div>
                )}

                {feed.error && <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">{feed.error.message}</div>}

                <div className="card overflow-x-auto">
                    {feed.loading && !feed.data ? (
                        <div className="p-4"><Loader2 className="h-5 w-5 animate-spin" /></div>
                    ) : (
                        <div style={{ minWidth: width + 80 }}>
                            <div className="sticky top-0 flex border-b border-gray-200 bg-white text-xs text-gray-500">
                                <div className="sticky left-0 z-10 w-20 shrink-0 bg-white px-2 py-1">{t('res.room')}</div>
                                {days.map((d) => {
                                    const dt = new Date(d);
                                    const weekend = dt.getUTCDay() === 0 || dt.getUTCDay() === 6;
                                    return (
                                        <div key={d} className={cn('shrink-0 border-l border-gray-100 py-1 text-center', weekend && 'bg-gray-50')} style={{ width: COL }}>
                                            <div>{dt.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' }).slice(0, 2)}</div>
                                            <div className="font-medium text-gray-700">{d.slice(8)}</div>
                                        </div>
                                    );
                                })}
                            </div>
                            {groups.length === 0 && <p className="p-4 text-sm text-gray-500">{t('res.tape.no_rooms')}</p>}
                            {groups.map(([typeId, g]) => {
                                const unassigned = bars.filter((b) => b.room_type_id === typeId && !b.room_id);
                                return (
                                    <Fragment key={typeId}>
                                        <div className="sticky left-0 border-b border-gray-200 bg-gray-50 px-2 py-1 text-xs font-semibold text-gray-700">
                                            {g.type?.name ?? t('res.room_type')} ({g.rooms.length})
                                        </div>
                                        {unassigned.length > 0 && lanes(unassigned).map((lane, i) => (
                                            <div key={`u-${i}`} className="flex border-b border-gray-100">
                                                <div className="sticky left-0 z-10 w-20 shrink-0 bg-white px-2 py-2 text-xs text-amber-700">{i === 0 ? t('res.tape.unassigned') : ''}</div>
                                                <div className="relative h-9" style={{ width }}>{lane.map(renderBar)}</div>
                                            </div>
                                        ))}
                                        {g.rooms.map((r) => (
                                            <div key={r.id} className="flex border-b border-gray-100">
                                                <div className="sticky left-0 z-10 w-20 shrink-0 bg-white px-2 py-2 text-sm font-medium">{r.number}</div>
                                                <div
                                                    className={cn('relative h-9', dragId && 'bg-blue-50/40')}
                                                    style={{ width }}
                                                    onDragOver={(e) => e.preventDefault()}
                                                    onDrop={(e) => (e.preventDefault(), drop(r))}
                                                >
                                                    <div className="absolute inset-0 flex">
                                                        {days.map((d) => <div key={d} className="h-full shrink-0 border-l border-gray-100" style={{ width: COL }} />)}
                                                    </div>
                                                    {blocks.filter((k) => k.room_id === r.id).map(renderBlock)}
                                                    {bars.filter((b) => b.room_id === r.id).map(renderBar)}
                                                </div>
                                            </div>
                                        ))}
                                    </Fragment>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
