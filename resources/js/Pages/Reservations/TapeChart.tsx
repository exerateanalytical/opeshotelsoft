import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Loader2, Wrench } from 'lucide-react';
import Button from '@/Components/ui/Button';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { useIsPhone } from '@/lib/useMediaQuery';
import StatusBadge, { STATUS_ICON } from './StatusBadge';
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
    checked_out: 'bg-slate-100 text-slate-700 border-slate-300',
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
    const phone = useIsPhone();
    const [from, setFrom] = useState(iso(new Date()));
    const [span, setSpan] = useState<14 | 30>(14);
    const to = shift(from, span);
    const feed = useApi<Feed>(`reservations/tape-chart?from=${from}&to=${to}`);
    const [dragId, setDragId] = useState<number | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [move, setMove] = useState<{ bar: Bar; room: Room; reason: string } | null>(null);
    const [busy, setBusy] = useState(false);
    // R-B: drag modes. move = drop on a room; swap = drop on another stay; split = drop on a room, then pick the date.
    const [mode, setMode] = useState<'move' | 'swap' | 'split'>('move');
    const [split, setSplit] = useState<{ bar: Bar; room: Room; date: string } | null>(null);

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
                await api('experience/tape/move', { method: 'POST', body: JSON.stringify({ reservation_id: bar.id, room_id: room.id }) });
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

    /** R-B: run a tape operation; the server checks conflicts and answers with a readable reason. */
    async function op(path: string, body: object, ok: string) {
        setBusy(true);
        setMessage(null);
        try {
            await api(`experience/tape/${path}`, { method: 'POST', body: JSON.stringify(body) });
            setMessage({ ok: true, text: ok });
            setSplit(null);
            feed.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        } finally {
            setBusy(false);
        }
    }

    function dropOnBar(target: Bar) {
        const bar = bars.find((b) => b.id === dragId);
        setDragId(null);
        if (!bar || bar.id === target.id || mode !== 'swap') return;
        op('swap', { reservation_id: bar.id, with_reservation_id: target.id }, t('experience.tape.swapped', { a: bar.number, b: target.number }));
    }

    /** Drag the right edge to extend or shorten the stay (a day is COL px). */
    function startResize(e: React.MouseEvent, b: Bar) {
        e.preventDefault();
        e.stopPropagation();
        const x0 = e.clientX;
        const up = (u: MouseEvent) => {
            window.removeEventListener('mouseup', up);
            const days = Math.round((u.clientX - x0) / COL);
            if (days !== 0) op('resize', { reservation_id: b.id, departure: shift(b.departure, days) }, t('experience.tape.resized', { number: b.number, date: shift(b.departure, days) }));
        };
        window.addEventListener('mouseup', up);
    }

    function drop(room: Room) {
        const bar = bars.find((b) => b.id === dragId);
        setDragId(null);
        if (!bar || bar.room_id === room.id) return;
        if (bar.status === 'checked_out') return setMessage({ ok: false, text: t('res.tape.checked_out_locked') });
        if (mode === 'split') return setSplit({ bar, room, date: shift(bar.arrival, Math.max(1, Math.floor(diff(bar.arrival, bar.departure) / 2))) });
        if (bar.status === 'checked_in') return setMove({ bar, room, reason: '' });
        assign(bar, room);
    }

    function renderBar(b: Bar) {
        const start = Math.max(0, diff(from, b.arrival));
        const end = Math.min(span, diff(from, b.departure));
        if (end <= 0 || start >= span) return null;
        const Icon = STATUS_ICON[b.status];
        return (
            <button
                key={b.id}
                type="button"
                draggable={b.status !== 'checked_out'}
                onDragStart={(e) => (setDragId(b.id), e.dataTransfer.setData('text/plain', String(b.id)))}
                onDragEnd={() => setDragId(null)}
                onDragOver={(e) => mode === 'swap' && e.preventDefault()}
                onDrop={(e) => { if (mode === 'swap') { e.preventDefault(); e.stopPropagation(); dropOnBar(b); } }}
                onClick={() => router.visit(`/reservations/${b.id}`)}
                title={`${b.number} · ${b.guest} · ${b.arrival} → ${b.departure} · ${t(`res.status.${b.status}`)}`}
                aria-label={`${b.guest || b.number}, ${b.arrival} → ${b.departure}, ${t(`res.status.${b.status}`)}`}
                className={cn('absolute top-0.5 bottom-0.5 flex items-center gap-1 overflow-hidden whitespace-nowrap rounded border px-1.5 text-left text-xs font-medium', BAR[b.status] ?? BAR.confirmed, dragId === b.id && 'opacity-60')}
                style={{ left: start * COL + 1, width: (end - start) * COL - 2 }}
            >
                {Icon && (end - start) > 1 && <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />}
                <span className="truncate">{b.vip && '★ '}{b.guest || b.number}</span>
                {b.status !== 'checked_out' && (
                    <span role="separator" aria-label={t('experience.tape.resize')} title={t('experience.tape.resize')} draggable={false}
                        onMouseDown={(e) => startResize(e, b)} onClick={(e) => e.stopPropagation()}
                        className="absolute right-0 top-0 hidden h-full w-2 cursor-ew-resize border-l border-current opacity-40 lg:block" />
                )}
            </button>
        );
    }

    function renderBlock(k: Block, i: number) {
        const start = Math.max(0, diff(from, k.from));
        const end = Math.min(span, diff(from, k.to));
        if (end <= 0 || start >= span) return null;
        return (
            <div key={`ooo-${i}`} title={k.reason} className="absolute top-0.5 bottom-0.5 flex items-center gap-1 overflow-hidden whitespace-nowrap rounded border border-dashed border-red-400 bg-red-50 px-1.5 text-xs font-medium text-red-800"
                style={{ left: start * COL + 1, width: (end - start) * COL - 2 }}>
                <Wrench className="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span className="truncate">{t('res.tape.ooo')} {k.reason}</span>
            </div>
        );
    }

    const width = span * COL;
    const roomNo = (b: Bar) => feed.data?.rooms.find((r) => r.id === b.room_id)?.number;
    const visibleBars = bars.filter((b) => diff(from, b.departure) > 0 && diff(from, b.arrival) < span).sort((a, b) => a.arrival.localeCompare(b.arrival));

    return (
        <AppLayout
            title={t('res.tape.title')}
            actions={<Link href="/reservations" className="btn-secondary">{t('res.tape.list')}</Link>}
        >
            <Head title={t('res.tape.title')} />
            <div className="space-y-4">
                <div className="flex flex-wrap items-end gap-2">
                    <button type="button" className="btn-secondary btn-icon" aria-label={t('common.previous')} onClick={() => setFrom(shift(from, -7))}><ChevronLeft className="h-4 w-4" aria-hidden="true" /></button>
                    <Field label={t('res.arrival')} className="w-44">
                        <input type="date" className="input" value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} />
                    </Field>
                    <button type="button" className="btn-secondary btn-icon" aria-label={t('common.next')} onClick={() => setFrom(shift(from, 7))}><ChevronRight className="h-4 w-4" aria-hidden="true" /></button>
                    <Field label={t('res.tape.days', { count: span })} className="w-36">
                        <select className="select" value={span} onChange={(e) => setSpan(+e.target.value as 14 | 30)}>
                            <option value={14}>{t('res.tape.days', { count: 14 })}</option>
                            <option value={30}>{t('res.tape.days', { count: 30 })}</option>
                        </select>
                    </Field>
                </div>

                <ul className="flex flex-wrap gap-2 text-xs" aria-label={t('res.status')}>
                    {Object.entries(BAR).map(([s, c]) => {
                        const I = STATUS_ICON[s];
                        return <li key={s} className={cn('inline-flex items-center gap-1 rounded border px-2 py-0.5 font-medium', c)}>{I && <I className="h-3.5 w-3.5" aria-hidden="true" />}{t(`res.status.${s}`)}</li>;
                    })}
                    <li className="inline-flex items-center gap-1 rounded border border-dashed border-red-400 bg-red-50 px-2 py-0.5 font-medium text-red-800"><Wrench className="h-3.5 w-3.5" aria-hidden="true" />{t('res.tape.out_of_order')}</li>
                </ul>

                {message && <div role="status" className={cn('rounded-md border p-3 text-sm', message.ok ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-red-300 bg-red-50 text-red-800')}>{message.text}</div>}

                {!phone && (
                    <div className="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label={t('experience.tape.mode')}>
                        {(['move', 'swap', 'split'] as const).map((m) => (
                            <button key={m} type="button" aria-pressed={mode === m} className={cn('btn-sm', mode === m ? 'btn-primary' : 'btn-secondary')} onClick={() => setMode(m)}>{t(`experience.tape.mode_${m}`)}</button>
                        ))}
                        <span className="text-slate-600">{t(`experience.tape.hint_${mode}`)} {t('res.tape.hint')}</span>
                    </div>
                )}

                <Modal open={!!split} onClose={() => setSplit(null)} title={t('experience.tape.mode_split')}
                    footer={split && <>
                        <Button variant="secondary" onClick={() => setSplit(null)}>{t('common.cancel')}</Button>
                        <Button disabled={busy} onClick={() => op('split', { reservation_id: split.bar.id, split_date: split.date, room_id: split.room.id }, t('experience.tape.split_done', { number: split.bar.number, room: split.room.number }))}>{t('experience.tape.split_go')}</Button>
                    </>}>
                    {split && (
                        <div className="space-y-3">
                            <p className="text-sm">{t('experience.tape.split_confirm', { guest: split.bar.guest, number: split.bar.number, room: split.room.number })}</p>
                            <Field label={t('experience.tape.split_date')}>
                                <input type="date" className="input" min={shift(split.bar.arrival, 1)} max={shift(split.bar.departure, -1)} value={split.date} onChange={(e) => setSplit({ ...split, date: e.target.value })} />
                            </Field>
                        </div>
                    )}
                </Modal>

                <Modal open={!!move} onClose={() => setMove(null)} title={t('res.tape.move_guest')}
                    footer={move && <>
                        <Button variant="secondary" onClick={() => setMove(null)}>{t('common.cancel')}</Button>
                        <Button disabled={busy || !move.reason.trim()} onClick={() => assign(move.bar, move.room, move.reason.trim())}>{t('res.tape.move_guest')}</Button>
                    </>}>
                    {move && (
                        <div className="space-y-3">
                            <p className="text-sm">{t('res.tape.move_confirm', { guest: move.bar.guest, number: move.bar.number, room: move.room.number })}</p>
                            <Field label={t('res.reason')} required>
                                <input className="input" placeholder={t('res.tape.reason_ph')} value={move.reason} onChange={(e) => setMove({ ...move, reason: e.target.value })} />
                            </Field>
                        </div>
                    )}
                </Modal>

                {feed.error && <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">{feed.error.message}</div>}

                {phone ? (
                    <section aria-label={t('res.tape.title')} className="space-y-2">
                        {feed.loading && !feed.data && <Loader2 className="mx-auto h-5 w-5 animate-spin" aria-label="..." />}
                        {feed.data && visibleBars.length === 0 && blocks.length === 0 && <div className="card"><EmptyState title={t('res.index.empty')} /></div>}
                        <ul className="space-y-2">
                            {visibleBars.map((b) => (
                                <li key={b.id}>
                                    <Link href={`/reservations/${b.id}`} className="card flex min-h-14 flex-wrap items-center gap-x-3 gap-y-1 p-3">
                                        <span className="min-w-0 flex-1 basis-40">
                                            <span className="block truncate font-medium text-slate-900">{b.vip && '★ '}{b.guest || b.number}</span>
                                            <span className="block text-sm text-slate-600">{b.arrival} → {b.departure} · {roomNo(b) ? `${t('res.room')} ${roomNo(b)}` : t('res.tape.unassigned')}</span>
                                        </span>
                                        <StatusBadge status={b.status} label={t(`res.status.${b.status}`)} />
                                    </Link>
                                </li>
                            ))}
                            {blocks.map((k, i) => (
                                <li key={`k-${i}`} className="flex items-center gap-2 rounded-lg border border-dashed border-red-400 bg-red-50 p-3 text-sm text-red-800">
                                    <Wrench className="h-4 w-4 shrink-0" aria-hidden="true" />
                                    <span>{t('res.tape.ooo')} {feed.data?.rooms.find((r) => r.id === k.room_id)?.number} · {k.from} → {k.to} · {k.reason}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : (
                    <div className="card max-h-[calc(100dvh-22rem)] min-h-72 overflow-auto overscroll-contain" tabIndex={0} role="region" aria-label={t('res.tape.title')}>
                        {feed.loading && !feed.data ? (
                            <div className="p-4"><Loader2 className="h-5 w-5 animate-spin" aria-label="..." /></div>
                        ) : (
                            <div style={{ minWidth: width + 96 }}>
                                <div className="sticky top-0 z-20 flex border-b border-slate-300 bg-white text-xs text-slate-700">
                                    <div className="sticky left-0 z-30 w-24 shrink-0 border-r border-slate-300 bg-white px-2 py-1.5 font-semibold">{t('res.room')}</div>
                                    {days.map((d) => {
                                        const dt = new Date(d);
                                        const weekend = dt.getUTCDay() === 0 || dt.getUTCDay() === 6;
                                        return (
                                            <div key={d} className={cn('shrink-0 border-l border-slate-200 py-1 text-center', weekend && 'bg-slate-100')} style={{ width: COL }}>
                                                <div>{dt.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' }).slice(0, 2)}</div>
                                                <div className="font-semibold text-slate-900">{d.slice(8)}</div>
                                            </div>
                                        );
                                    })}
                                </div>
                                {groups.length === 0 && <p className="p-4 text-sm text-slate-600">{t('res.tape.no_rooms')}</p>}
                                {groups.map(([typeId, g]) => {
                                    const unassigned = bars.filter((b) => b.room_type_id === typeId && !b.room_id);
                                    return (
                                        <Fragment key={typeId}>
                                            <div className="sticky left-0 border-b border-slate-300 bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-800">
                                                {g.type?.name ?? t('res.room_type')} ({g.rooms.length})
                                            </div>
                                            {unassigned.length > 0 && lanes(unassigned).map((lane, i) => (
                                                <div key={`u-${i}`} className="flex border-b border-slate-200">
                                                    <div className="sticky left-0 z-10 w-24 shrink-0 border-r border-slate-300 bg-white px-2 py-3 text-xs font-medium text-amber-800">{i === 0 ? t('res.tape.unassigned') : ''}</div>
                                                    <div className="relative h-12" style={{ width }}>{lane.map(renderBar)}</div>
                                                </div>
                                            ))}
                                            {g.rooms.map((r) => (
                                                <div key={r.id} className="flex border-b border-slate-200">
                                                    <div className="sticky left-0 z-10 w-24 shrink-0 border-r border-slate-300 bg-white px-2 py-3 text-sm font-semibold">{r.number}</div>
                                                    <div
                                                        className={cn('relative h-12', dragId && 'bg-blue-50')}
                                                        style={{ width }}
                                                        onDragOver={(e) => e.preventDefault()}
                                                        onDrop={(e) => (e.preventDefault(), drop(r))}
                                                    >
                                                        <div className="absolute inset-0 flex">
                                                            {days.map((d) => <div key={d} className="h-full shrink-0 border-l border-slate-200" style={{ width: COL }} />)}
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
                )}
            </div>
        </AppLayout>
    );
}
