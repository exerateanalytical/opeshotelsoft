import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Loader2, Plus, Search, Trash2, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Allocation { id: number; room_type_id: number; date: string; rooms: number; picked_up: number; rate: number | null }
interface Block {
    id: number; code: string; name: string; status: string; arrival: string; departure: string;
    cutoff_date: string | null; company_id: number | null; rate_plan_id: number | null; notes: string | null; allocations: Allocation[];
}
interface Reservation {
    id: number; number: string; status: string; arrival: string; departure: string;
    guest: { first_name: string; last_name: string } | null; room_type: { name: string } | null; room: { number: string } | null;
}
interface Guest { id: number; first_name: string; last_name: string; phone?: string | null; email?: string | null }
interface Named { id: number; name: string; code?: string }
type List<T> = T[] | { data: T[] };
const rowsOf = <T,>(l: List<T> | null | undefined): T[] => (!l ? [] : Array.isArray(l) ? l : l.data ?? []);

interface Entry { guest: Guest; room_type_id: string; adults: string }

function errorText(e: unknown) {
    return e instanceof ApiError ? (e.problem.errors ? Object.values(e.problem.errors).flat().join(' ') : e.message) : String(e);
}

export default function GroupShow({ id }: { id: string }) {
    const t = useT();
    const block = useApi<Block>(`blocks/${id}`);
    const roomTypes = useApi<List<Named>>('room-types?per_page=200');
    const ratePlans = useApi<List<Named>>('rate-plans?per_page=200');
    const pickups = useApi<List<Reservation>>(`reservations?room_block_id=${id}&per_page=200`);
    const [entries, setEntries] = useState<Entry[]>([]);
    const [ratePlan, setRatePlan] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    const b = block.data;
    const rtName = (rid: number | string) => rowsOf(roomTypes.data).find((r) => String(r.id) === String(rid))?.name ?? `#${rid}`;

    // Allocation and pickup per room type: peak rooms per night and picked-up room-nights.
    const byType = new Map<number, { nights: number; rooms: number; peak: number; picked: number; peakPicked: number }>();
    for (const a of b?.allocations ?? []) {
        const x = byType.get(a.room_type_id) ?? { nights: 0, rooms: 0, peak: 0, picked: 0, peakPicked: 0 };
        x.nights++;
        x.rooms += a.rooms;
        x.picked += a.picked_up ?? 0;
        x.peak = Math.max(x.peak, a.rooms);
        x.peakPicked = Math.max(x.peakPicked, a.picked_up ?? 0);
        byType.set(a.room_type_id, x);
    }
    const blockTypes = Array.from(byType.keys());

    async function submitRoomingList() {
        if (!b) return;
        setBusy('rl');
        setMessage(null);
        try {
            const res = await api<{ data: unknown[] }>(`blocks/${b.id}/rooming-list`, { method: 'POST', body: JSON.stringify({
                rate_plan_id: ratePlan ? Number(ratePlan) : null,
                entries: entries.map((e) => ({ guest_id: e.guest.id, room_type_id: Number(e.room_type_id), adults: Number(e.adults) || 1 })),
            }) });
            setMessage({ ok: true, text: t('groups.show.created', { count: res?.data?.length ?? entries.length }) });
            setEntries([]);
            block.reload();
            pickups.reload();
        } catch (e) {
            setMessage({ ok: false, text: errorText(e) });
        } finally {
            setBusy(null);
        }
    }

    async function release() {
        if (!b || !confirm(t('groups.show.release_confirm'))) return;
        setBusy('rel');
        setMessage(null);
        try {
            await api(`blocks/${b.id}/release`, { method: 'POST', body: '{}' });
            setMessage({ ok: true, text: t('groups.show.released') });
            block.reload();
        } catch (e) {
            setMessage({ ok: false, text: errorText(e) });
        } finally {
            setBusy(null);
        }
    }

    return (
        <AppLayout
            title={b ? `${b.code} · ${b.name}` : t('groups.show.block')}
            subtitle={b ? `${formatDate(b.arrival)} → ${formatDate(b.departure)}${b.cutoff_date ? ` · ${t('groups.show.cutoff', { date: formatDate(b.cutoff_date) })}` : ''}` : ''}
            actions={
                <div className="flex gap-2">
                    <Link href="/groups" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('res.back')}</Link>
                    {b && b.status !== 'released' && <button className="btn-secondary" disabled={busy === 'rel'} onClick={release}>{t('groups.show.release')}</button>}
                </div>
            }
        >
            <Head title={b?.code ?? t('groups.show.block')} />

            {(message || block.error) && (
                <div className={cn('mb-4 rounded-lg text-sm px-4 py-3', message?.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>
                    {message?.text ?? block.error?.message}
                </div>
            )}

            {!b ? (!block.error && <Loader2 className="w-6 h-6 mx-auto mt-16 animate-spin text-slate-400" />) : (
                <div className="grid lg:grid-cols-2 gap-6">
                    <div className="space-y-6">
                        <div className="card overflow-x-auto">
                            <div className="flex items-center px-5 py-4 border-b border-slate-100">
                                <h3 className="font-semibold text-slate-800">{t('groups.show.allocation')}</h3>
                                <span className="ml-auto text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">{t(`groups.status.${b.status}`)}</span>
                            </div>
                            <table className="table-auto w-full">
                                <thead><tr><th>{t('res.room_type')}</th><th className="text-right">{t('groups.show.rooms_night')}</th><th className="text-right">{t('groups.show.picked_up')}</th><th className="text-right">{t('groups.show.room_nights')}</th><th>{t('groups.pickup')}</th></tr></thead>
                                <tbody>
                                    {blockTypes.map((rt) => {
                                        const x = byType.get(rt)!;
                                        const pct = x.rooms ? Math.round((x.picked / x.rooms) * 100) : 0;
                                        return (
                                            <tr key={rt}>
                                                <td className="text-sm">{rtName(rt)}</td>
                                                <td className="text-right text-sm">{x.peak}</td>
                                                <td className="text-right text-sm">{x.peakPicked}</td>
                                                <td className="text-right text-sm">{x.picked} / {x.rooms}</td>
                                                <td className="w-32">
                                                    <div className="h-2 rounded bg-slate-100 overflow-hidden"><div className={cn('h-full', pct >= 100 ? 'bg-emerald-500' : 'bg-sky-500')} style={{ width: `${Math.min(pct, 100)}%` }} /></div>
                                                    <div className="text-xs text-slate-400 mt-0.5">{pct}%</div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {blockTypes.length === 0 && <tr><td colSpan={5} className="text-center py-8 text-slate-400 text-sm">{t('groups.show.no_alloc')}</td></tr>}
                                </tbody>
                            </table>
                            {b.notes && <p className="px-5 py-3 text-sm text-slate-600 border-t border-slate-100 whitespace-pre-line">{b.notes}</p>}
                        </div>

                        <div className="card overflow-x-auto">
                            <div className="px-5 py-4 border-b border-slate-100"><h3 className="font-semibold text-slate-800">{t('groups.show.pickups')}</h3></div>
                            {pickups.error && <div className="mx-5 my-3 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{pickups.error.message}</div>}
                            <table className="table-auto w-full">
                                <thead><tr><th>{t('groups.show.number')}</th><th>{t('res.guest')}</th><th>{t('res.room')}</th><th>{t('groups.dates')}</th><th>{t('res.status')}</th></tr></thead>
                                <tbody>
                                    {pickups.loading && !pickups.data && <tr><td colSpan={5} className="py-8"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-400" /></td></tr>}
                                    {pickups.data && rowsOf(pickups.data).length === 0 && <tr><td colSpan={5} className="text-center py-8 text-slate-400 text-sm">{t('groups.show.none_yet')}</td></tr>}
                                    {rowsOf(pickups.data).map((r) => (
                                        <tr key={r.id}>
                                            <td><Link href={`/reservations/${r.id}`} className="font-mono text-sm text-sky-700 hover:underline">{r.number}</Link></td>
                                            <td className="text-sm">{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '—'}</td>
                                            <td className="text-sm">{r.room ? r.room.number : r.room_type?.name}</td>
                                            <td className="text-sm whitespace-nowrap">{formatDate(r.arrival)} → {formatDate(r.departure)}</td>
                                            <td className="text-xs">{t(`res.status.${r.status}`)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {b.status !== 'released' ? (
                        <div className="card px-5 py-4 space-y-4 self-start">
                            <h3 className="font-semibold text-slate-800">{t('groups.show.rooming_list')}</h3>
                            <GuestPicker onPick={(g) => setEntries([...entries, { guest: g, room_type_id: blockTypes[0] ? String(blockTypes[0]) : '', adults: '1' }])} />

                            {entries.length > 0 && (
                                <div className="space-y-2">
                                    {entries.map((e, i) => (
                                        <div key={i} className="grid grid-cols-[1fr_9rem_4rem_auto] gap-2 items-center">
                                            <span className="text-sm truncate">{e.guest.first_name} {e.guest.last_name}</span>
                                            <select className="input" required value={e.room_type_id} onChange={(ev) => setEntries(entries.map((x, j) => j === i ? { ...x, room_type_id: ev.target.value } : x))}>
                                                <option value="" disabled>{t('groups.room_type_ph')}</option>
                                                {blockTypes.map((rt) => <option key={rt} value={rt}>{rtName(rt)}</option>)}
                                            </select>
                                            <input className="input" type="number" min={1} title={t('res.adults')} value={e.adults} onChange={(ev) => setEntries(entries.map((x, j) => j === i ? { ...x, adults: ev.target.value } : x))} />
                                            <button type="button" className="text-slate-400 hover:text-red-600" title={t('groups.remove')} onClick={() => setEntries(entries.filter((_, j) => j !== i))}><Trash2 className="w-4 h-4" /></button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {!b.rate_plan_id && (
                                <label className="text-xs text-slate-500 block">{t('groups.show.rate_plan_none')}
                                    <select className="input mt-1" value={ratePlan} onChange={(e) => setRatePlan(e.target.value)}>
                                        <option value="" disabled>{t('groups.show.rate_plan_ph')}</option>
                                        {rowsOf(ratePlans.data).map((r) => <option key={r.id} value={r.id}>{r.code ? `${r.code} · ` : ''}{r.name}</option>)}
                                    </select>
                                </label>
                            )}
                            <button className="btn-primary w-full justify-center" disabled={busy === 'rl' || entries.length === 0 || entries.some((e) => !e.room_type_id) || (!b.rate_plan_id && !ratePlan)}
                                onClick={submitRoomingList}>
                                {busy === 'rl' && <Loader2 className="w-4 h-4 animate-spin" />} {t('groups.show.create_n', { count: entries.length || '' })}
                            </button>
                        </div>
                    ) : (
                        <div className="card px-5 py-4 text-sm text-slate-500 self-start">{t('groups.show.released_info')}</div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function GuestPicker({ onPick }: { onPick: (g: Guest) => void }) {
    const t = useT();
    const [q, setQ] = useState('');
    const [term, setTerm] = useState('');
    const [creating, setCreating] = useState(false);
    const [g, setG] = useState({ first_name: '', last_name: '', phone: '', email: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const tm = setTimeout(() => setTerm(q.trim()), 300);
        return () => clearTimeout(tm);
    }, [q]);
    const results = useApi<List<Guest>>(term.length >= 2 ? `guests?q=${encodeURIComponent(term)}&per_page=10` : null);

    return (
        <div className="space-y-2">
            <div className="flex gap-2">
                <label className="relative flex-1">
                    <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input className="input pl-9" placeholder={t('groups.picker.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                </label>
                <button type="button" className="btn-secondary" onClick={() => setCreating(!creating)}><UserPlus className="w-4 h-4" /> {t('groups.picker.new')}</button>
            </div>
            {term.length >= 2 && (
                <div className="border border-slate-100 rounded-lg max-h-48 overflow-y-auto">
                    {results.loading && <Loader2 className="w-4 h-4 m-3 animate-spin text-slate-400" />}
                    {!results.loading && rowsOf(results.data).length === 0 && <p className="text-sm text-slate-400 px-3 py-2">{t('groups.picker.no_match')}</p>}
                    {rowsOf(results.data).map((r) => (
                        <button key={r.id} type="button" className="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 flex items-center gap-2"
                            onClick={() => { onPick(r); setQ(''); }}>
                            <Plus className="w-3 h-3 text-slate-400" /> {r.first_name} {r.last_name}
                            <span className="ml-auto text-xs text-slate-400">{r.phone ?? r.email ?? ''}</span>
                        </button>
                    ))}
                </div>
            )}
            {creating && (
                <form className="grid grid-cols-1 sm:grid-cols-2 gap-2 border border-slate-100 rounded-lg p-3" onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError(null);
                    try {
                        const created = await api<Guest>('guests', { method: 'POST', body: JSON.stringify({
                            first_name: g.first_name, last_name: g.last_name, phone: g.phone || null, email: g.email || null,
                        }) });
                        onPick(created);
                        setG({ first_name: '', last_name: '', phone: '', email: '' });
                        setCreating(false);
                    } catch (err) {
                        setError(errorText(err));
                    } finally {
                        setBusy(false);
                    }
                }}>
                    <input className="input" required placeholder={t('res.create.field.first_name')} value={g.first_name} onChange={(e) => setG({ ...g, first_name: e.target.value })} />
                    <input className="input" required placeholder={t('res.create.field.last_name')} value={g.last_name} onChange={(e) => setG({ ...g, last_name: e.target.value })} />
                    <input className="input" placeholder={t('res.phone')} value={g.phone} onChange={(e) => setG({ ...g, phone: e.target.value })} />
                    <input className="input" type="email" placeholder={t('res.email')} value={g.email} onChange={(e) => setG({ ...g, email: e.target.value })} />
                    {error && <div className="sm:col-span-2 rounded-lg text-sm px-3 py-2 bg-red-50 text-red-700">{error}</div>}
                    <button className="btn-primary sm:col-span-2 justify-center" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('groups.picker.create_add')}</button>
                </form>
            )}
        </div>
    );
}
