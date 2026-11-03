import { ArrowRightLeft, Ban, BedDouble, ChefHat, Gift, LayoutGrid, List, Printer, CloudOff, Loader2, Minus, Percent, Plus, RefreshCw, Scissors, Search, Trash2, Wallet, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import CashierBar from '@/Components/CashierBar';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import FloorPlan, { FloorTable } from './FloorPlan';
import { Banner, Check, Code, errText, MenuItem, Msg, Paginated } from './shared';
import { useT } from '@/lib/i18n';

type Panel = null | 'discount' | 'split' | 'settle' | 'room' | 'void' | 'comp' | 'transfer' | { voidLine: number } | { compLine: number };

/** A check whose settle failed on a network error, replayed through POST pos/sync. */
interface QueuedCheck {
    client_uuid: string; check_id: number; outlet_id: number; table_number: string | null;
    items: { menu_item_id: number; quantity: number; notes: string | null }[];
    payments: object[];
}
const QUEUE_KEY = 'pos.offlineQueue';
function readQueue(): QueuedCheck[] {
    try { return JSON.parse(localStorage.getItem(QUEUE_KEY) ?? '[]'); } catch { return []; }
}
function writeQueue(q: QueuedCheck[]) {
    try { localStorage.setItem(QUEUE_KEY, JSON.stringify(q)); } catch { /* storage unavailable */ }
}

interface ResvRow { id: number; number: string; guest: { first_name: string; last_name: string } | null; room: { number: string } | null; departure: string }

export default function Terminal({ outletId }: { outletId: number }) {
    const t = useT();
    const checks = useApi<Paginated<Check>>(`pos/checks?outlet_id=${outletId}&status=open`);
    const menu = useApi<Paginated<MenuItem>>('pos/menu-items?filter[active]=1&per_page=500&sort=name');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const check = useApi<Check>(selectedId ? `pos/checks/${selectedId}` : null);
    const [msg, setMsg] = useState<Msg>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [table, setTable] = useState('');
    const [covers, setCovers] = useState('1');
    const [category, setCategory] = useState<string>('');
    const [panel, setPanel] = useState<Panel>(null);
    const [refresh, setRefresh] = useState(0);
    const [notes, setNotes] = useState('');
    const [queue, setQueue] = useState<QueuedCheck[]>(readQueue);
    const [syncing, setSyncing] = useState(false);
    const [view, setView] = useState<'floor' | 'list'>('floor');
    const reloadChecks = checks.reload;

    const syncQueue = useCallback(async () => {
        const pending = readQueue();
        if (!pending.length) return;
        setSyncing(true);
        try {
            const r = await api<{ results: { client_uuid: string; status: string; error?: string }[] }>('pos/sync', { method: 'POST', body: JSON.stringify({ checks: pending }) });
            const done = new Set(r.results.filter((x) => x.status !== 'rejected').map((x) => x.client_uuid));
            const rejected = r.results.filter((x) => x.status === 'rejected');
            const left = pending.filter((p) => !done.has(p.client_uuid));
            writeQueue(left);
            setQueue(left);
            setMsg(rejected.length ? { ok: false, text: t('pos.term.sync_rejected', { errors: rejected.map((x) => x.error).join('; ') }) } : { ok: true, text: t('pos.term.synced', { count: done.size }) });
            reloadChecks();
        } catch {
            /* still offline: keep the queue */
        } finally {
            setSyncing(false);
        }
    }, [reloadChecks]);

    useEffect(() => {
        const timer = window.setInterval(syncQueue, 30000);
        window.addEventListener('online', syncQueue);
        return () => { window.clearInterval(timer); window.removeEventListener('online', syncQueue); };
    }, [syncQueue]);

    async function settle(ch: Check, payments: object[], change: number) {
        setBusy('settle');
        setMsg(null);
        try {
            await api(`pos/checks/${ch.id}/settle`, { method: 'POST', body: JSON.stringify({ payments }) });
            setMsg({ ok: true, text: change > 0 ? t('pos.term.settled_change', { amount: formatCurrency(change) }) : t('pos.term.settled') });
        } catch (e) {
            if (e instanceof ApiError) {
                setMsg({ ok: false, text: errText(e) });
                setBusy(null);
                return;
            }
            const entry: QueuedCheck = {
                client_uuid: crypto.randomUUID(), check_id: ch.id, outlet_id: ch.outlet_id, table_number: ch.table_number,
                items: ch.lines.filter((l) => !l.voided).map((l) => ({ menu_item_id: l.pos_menu_item_id, quantity: l.quantity, notes: l.notes })),
                payments,
            };
            const next = [...readQueue().filter((q) => q.check_id !== ch.id), entry];
            writeQueue(next);
            setQueue(next);
            setMsg({ ok: false, text: t('pos.term.offline_saved') });
        }
        setBusy(null);
        setPanel(null);
        setSelectedId(null);
        setRefresh((x) => x + 1);
        checks.reload();
    }

    async function lineCall(key: string, path: string, method: 'PATCH' | 'POST', body?: object) {
        setBusy(key);
        setMsg(null);
        try {
            await api(path, { method, body: body ? JSON.stringify(body) : undefined });
            checks.reload();
            check.reload();
        } catch (e) {
            setMsg({ ok: false, text: errText(e) });
        } finally {
            setBusy(null);
        }
    }

    const items = useMemo(() => (menu.data?.data ?? []).filter((m) => m.outlet_id === null || m.outlet_id === outletId), [menu.data, outletId]);
    const categories = useMemo(() => Array.from(new Set(items.map((m) => m.category ?? 'Other'))).sort(), [items]);
    const shown = items.filter((m) => !category || (m.category ?? 'Other') === category);

    async function run<T>(key: string, path: string, body: object, ok: string, after?: (r: T) => void) {
        setBusy(key);
        setMsg(null);
        try {
            const r = await api<T>(path, { method: 'POST', body: JSON.stringify(body) });
            setMsg({ ok: true, text: ok });
            after?.(r);
            checks.reload();
            check.reload();
            setRefresh((x) => x + 1);
            return true;
        } catch (e) {
            setMsg({ ok: false, text: errText(e) });
            return false;
        } finally {
            setBusy(null);
        }
    }

    const c = selectedId && check.data?.id === selectedId ? check.data : null;
    const liveLines = c?.lines.filter((l) => !l.voided) ?? [];
    const unfired = liveLines.filter((l) => !l.fired_at).length;
    const pendingComps = liveLines.filter((l) => l.comp_status === 'pending').length;

    async function openTable(ft: FloorTable) {
        await run<Check>('table' + ft.id, `pos/tables/${ft.id}/check`, {}, t('pos.term.table_opened', { name: ft.name }), (r) => { setSelectedId(r.id); setPanel(null); });
    }

    return (
        <div>
            <CashierBar refresh={refresh} />
            <Banner msg={msg} />
            {queue.length > 0 && (
                <div className="card p-3 mb-3 flex items-center justify-between text-sm text-amber-800 bg-amber-50">
                    <span className="flex items-center gap-2"><CloudOff className="w-4 h-4" /> {t('pos.term.offline_queue', { count: queue.length })}</span>
                    <button className="btn-secondary flex items-center gap-1" disabled={syncing} onClick={syncQueue}>
                        <RefreshCw className={`w-4 h-4 ${syncing ? 'animate-spin' : ''}`} /> {t('pos.term.retry')}
                    </button>
                </div>
            )}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
                {/* Open checks */}
                <div className="card p-4 lg:col-span-3">
                    <div className="flex justify-between items-center mb-3">
                        <h3 className="font-semibold text-slate-700">{view === 'floor' ? t('pos.term.tables') : t('pos.term.open_checks')}</h3>
                        <div className="flex gap-1">
                            <button title={t('pos.term.floor_plan')} className={view === 'floor' ? 'btn-primary px-2' : 'btn-secondary px-2'} onClick={() => setView('floor')}><LayoutGrid className="w-4 h-4" /></button>
                            <button title={t('pos.term.list')} className={view === 'list' ? 'btn-primary px-2' : 'btn-secondary px-2'} onClick={() => setView('list')}><List className="w-4 h-4" /></button>
                        </div>
                    </div>
                    {view === 'floor' && <FloorPlan outletId={outletId} refresh={refresh} selectedId={selectedId} onTable={openTable} onCheck={(id) => { setSelectedId(id); setPanel(null); }} />}
                    {view === 'list' && <>
                    <form
                        className="flex gap-2 mb-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            run<Check>('open', 'pos/checks', { outlet_id: outletId, table_number: table || null, covers: Number(covers) || 1 }, t('pos.term.check_opened'), (r) => {
                                setSelectedId(r.id);
                                setTable('');
                                setCovers('1');
                            });
                        }}
                    >
                        <input className="input w-full" placeholder={t('pos.term.table_ph')} value={table} onChange={(e) => setTable(e.target.value)} maxLength={10} />
                        <input className="input w-16" type="number" min={1} max={200} title={t('pos.term.covers')} value={covers} onChange={(e) => setCovers(e.target.value)} />
                        <button className="btn-primary px-3" disabled={busy === 'open'} title={t('pos.term.open_check')}><Plus className="w-4 h-4" /></button>
                    </form>
                    {checks.loading && !checks.data && <Loader2 className="w-5 h-5 animate-spin text-slate-400" />}
                    {checks.error && <p className="text-sm text-red-600">{checks.error.message}</p>}
                    <div className="grid grid-cols-2 lg:grid-cols-1 gap-2 max-h-[60vh] overflow-y-auto">
                        {checks.data?.data.map((k) => (
                            <button
                                key={k.id}
                                onClick={() => { setSelectedId(k.id); setPanel(null); }}
                                className={`text-left rounded-lg border p-3 min-h-[64px] ${selectedId === k.id ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50'}`}
                            >
                                <div className="flex justify-between font-medium text-slate-800"><span>{t('pos.kot.table', { name: k.table_number ?? '-' })}</span><span>{formatCurrency(k.total)}</span></div>
                                <div className="text-xs text-slate-500">{k.number} · {t('pos.common.covers_short', { count: k.covers })} · {t('pos.term.items_n', { count: k.lines.filter((l) => !l.voided).length })}</div>
                            </button>
                        ))}
                        {checks.data && checks.data.data.length === 0 && <p className="text-sm text-slate-400">{t('pos.term.no_open')}</p>}
                    </div>
                    </>}
                </div>

                {/* Menu grid */}
                <div className="card p-4 lg:col-span-5">
                    <div className="flex gap-2 overflow-x-auto pb-2 mb-3">
                        {['', ...categories].map((cat) => (
                            <button key={cat || 'all'} onClick={() => setCategory(cat)} className={`px-4 py-2 rounded-full text-sm whitespace-nowrap ${category === cat ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700'}`}>
                                {cat === 'Other' ? t('pos.term.other') : cat || t('pos.term.all')}
                            </button>
                        ))}
                    </div>
                    {menu.loading && !menu.data && <Loader2 className="w-5 h-5 animate-spin text-slate-400" />}
                    {!c && <p className="text-sm text-amber-600 mb-2">{t('pos.term.select_hint')}</p>}
                    <input className="input w-full mb-2" placeholder={t('pos.term.note_ph')} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={255} />
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        {shown.map((m) => (
                            <button
                                key={m.id}
                                disabled={!c || busy === `add${m.id}`}
                                onClick={() => c && run('add' + m.id, `pos/checks/${c.id}/items`, { menu_item_id: m.id, quantity: 1, notes: notes || null }, t('pos.term.item_added', { name: m.name }), () => setNotes(''))}
                                className="rounded-lg border border-slate-200 p-3 min-h-[80px] text-left hover:bg-emerald-50 disabled:opacity-50"
                            >
                                <div className="font-medium text-slate-800 text-sm">{m.name}</div>
                                <div className="text-xs text-slate-500">{formatCurrency(m.price)}</div>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Current check */}
                <div className="card p-4 lg:col-span-4">
                    {!selectedId && <p className="text-sm text-slate-400">{t('pos.term.no_selected')}</p>}
                    {selectedId && !c && <Loader2 className="w-5 h-5 animate-spin text-slate-400" />}
                    {c && (
                        <>
                            <div className="flex justify-between items-start mb-3">
                                <div>
                                    <h3 className="font-semibold text-slate-800">{t('pos.term.check_header', { number: c.number, table: c.table_number ?? '-' })}</h3>
                                    <p className="text-xs text-slate-500">{t('pos.term.covers_n', { count: c.covers })} · {formatDateTime(c.created_at)} · {c.status}</p>
                                </div>
                                <button onClick={() => setSelectedId(null)} className="text-slate-400"><X className="w-4 h-4" /></button>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="table-auto w-full text-sm">
                                    <tbody>
                                        {c.lines.map((l) => (
                                            <tr key={l.id} className={l.voided ? 'line-through text-slate-400' : ''}>
                                                <td className="py-1">
                                                    {!l.voided && !l.fired_at && c.status === 'open' ? (
                                                        <span className="inline-flex items-center gap-1 mr-1">
                                                            <button title={t('pos.term.less')} className="btn-secondary px-2 py-1" disabled={l.quantity <= 1 || busy === `q${l.id}`}
                                                                onClick={() => lineCall(`q${l.id}`, `pos/checks/${c.id}/lines/${l.id}`, 'PATCH', { quantity: l.quantity - 1 })}><Minus className="w-3 h-3" /></button>
                                                            <span className="w-6 text-center">{l.quantity}</span>
                                                            <button title={t('pos.term.more')} className="btn-secondary px-2 py-1" disabled={busy === `q${l.id}`}
                                                                onClick={() => lineCall(`q${l.id}`, `pos/checks/${c.id}/lines/${l.id}`, 'PATCH', { quantity: l.quantity + 1 })}><Plus className="w-3 h-3" /></button>
                                                        </span>
                                                    ) : <>{l.quantity} × </>}
                                                    {l.name}{l.fired_at ? <ChefHat className="inline w-3 h-3 ml-1 text-emerald-600" /> : null}
                                                    {l.notes && <div className="text-xs text-slate-500 italic">{l.notes}</div>}
                                                    {l.list_price ? <div className="text-xs text-slate-500">{t('pos.term.price_level_was', { price: formatCurrency(l.list_price) })}</div> : null}
                                                    {l.comp_status && <div className="text-xs"><span className={l.comp_status === 'approved' ? 'badge-green' : 'badge-yellow'}>{l.comp_status === 'approved' ? t('pos.term.comped') : t('pos.term.comp_pending')}</span> {l.comp_reason}</div>}
                                                </td>
                                                <td className="py-1 text-right">{formatCurrency(l.amount)}</td>
                                                <td className="py-1 text-right w-8">
                                                    {!l.voided && c.status === 'open' && !l.fired_at && (
                                                        <button title={t('pos.term.remove_line')} disabled={busy === `rm${l.id}`} onClick={() => lineCall(`rm${l.id}`, `pos/checks/${c.id}/lines/${l.id}/remove`, 'POST')} className="text-slate-500"><X className="w-4 h-4" /></button>
                                                    )}
                                                    {!l.voided && c.status === 'open' && !l.comp_status && (
                                                        <button title={t('pos.term.comp_item')} onClick={() => setPanel({ compLine: l.id })} className="text-slate-500 mr-1"><Gift className="w-4 h-4" /></button>
                                                    )}
                                                    {!l.voided && c.status === 'open' && l.fired_at && (
                                                        <button title={t('pos.term.void_line')} onClick={() => setPanel({ voidLine: l.id })} className="text-red-500"><Trash2 className="w-4 h-4" /></button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="border-t mt-2 pt-2 text-sm space-y-1">
                                <div className="flex justify-between"><span>{t('pos.term.subtotal')}</span><span>{formatCurrency(c.subtotal)}</span></div>
                                {(c.comp_total ?? 0) > 0 && <div className="flex justify-between text-amber-700"><span>{t('pos.term.comps')}</span><span>-{formatCurrency(c.comp_total ?? 0)}</span></div>}
                                {c.discount > 0 && <div className="flex justify-between text-amber-700"><span>{t('pos.term.discount_reason', { reason: c.discount_reason ?? '' })}</span><span>-{formatCurrency(c.discount)}</span></div>}
                                <div className="flex justify-between font-semibold text-lg"><span>{t('pos.term.total')}</span><span>{formatCurrency(c.total)}</span></div>
                            </div>
                            {c.status === 'open' && (
                                <div className="grid grid-cols-3 gap-2 mt-3">
                                    <button className="btn-primary py-3 flex items-center justify-center gap-1" disabled={!unfired || busy === 'fire'}
                                        onClick={() => run<{ stations: unknown }>('fire', `pos/checks/${c.id}/fire`, {}, t('pos.term.fired', { count: unfired }))}>
                                        <ChefHat className="w-4 h-4" /> {t('pos.term.fire')}
                                    </button>
                                    <button className="btn-secondary py-3 flex items-center justify-center gap-1" onClick={() => setPanel('discount')}><Percent className="w-4 h-4" /> {t('pos.term.discount')}</button>
                                    <button className="btn-secondary py-3 flex items-center justify-center gap-1" onClick={() => setPanel('split')}><Scissors className="w-4 h-4" /> {t('pos.term.split')}</button>
                                    <button className="btn-primary py-3 flex items-center justify-center gap-1" disabled={!liveLines.length} onClick={() => setPanel('settle')}><Wallet className="w-4 h-4" /> {t('pos.term.settle')}</button>
                                    <button className="btn-secondary py-3 flex items-center justify-center gap-1" disabled={!liveLines.length} onClick={() => setPanel('room')}><BedDouble className="w-4 h-4" /> {t('pos.term.room')}</button>
                                    <button className="btn-secondary py-3 flex items-center justify-center gap-1 text-red-600" onClick={() => setPanel('void')}><Ban className="w-4 h-4" /> {t('pos.term.void')}</button>
                                    <button className="btn-secondary py-3 flex items-center justify-center gap-1" disabled={!liveLines.length} onClick={() => setPanel('comp')}><Gift className="w-4 h-4" /> {t('pos.term.comp')}</button>
                                    <button className="btn-secondary py-3 flex items-center justify-center gap-1" onClick={() => setPanel('transfer')}><ArrowRightLeft className="w-4 h-4" /> {t('pos.term.move')}</button>
                                    <a className="btn-secondary py-3 flex items-center justify-center gap-1" href={`/pos/checks/${c.id}/kot`} target="_blank" rel="noreferrer"><Printer className="w-4 h-4" /> {t('pos.term.kot')}</a>
                                </div>
                            )}
                            {c.status === 'open' && pendingComps > 0 && (
                                <div className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">
                                    <p className="text-amber-800 mb-2">{t('pos.term.comps_pending', { count: pendingComps })}</p>
                                    <div className="flex gap-2">
                                        <button className="btn-primary" disabled={busy === 'compd'} onClick={() => run('compd', `pos/checks/${c.id}/comp/decide`, { approve: true }, t('pos.term.comp_approved'))}>{t('pos.term.approve')}</button>
                                        <button className="btn-secondary" disabled={busy === 'compd'} onClick={() => run('compd', `pos/checks/${c.id}/comp/decide`, { approve: false }, t('pos.term.comp_rejected'))}>{t('pos.term.reject')}</button>
                                    </div>
                                </div>
                            )}
                            {panel && c.status === 'open' && (
                                <div className="mt-4 border-t pt-3">
                                    {panel === 'discount' && <ReasonForm label={t('pos.term.discount')} withAmount busy={busy === 'disc'} onCancel={() => setPanel(null)}
                                        onSubmit={(reason, amount) => run('disc', `pos/checks/${c.id}/discount`, { amount, reason }, t('pos.term.discount_applied'), () => setPanel(null))} />}
                                    {panel === 'void' && <ReasonForm label={t('pos.term.void_check')} busy={busy === 'void'} onCancel={() => setPanel(null)}
                                        onSubmit={(reason) => run('void', `pos/checks/${c.id}/void`, { reason }, t('pos.term.check_voided'), () => { setPanel(null); setSelectedId(null); })} />}
                                    {panel === 'comp' && <ReasonForm label={t('pos.term.comp_check')} busy={busy === 'comp'} onCancel={() => setPanel(null)}
                                        onSubmit={(reason) => run('comp', `pos/checks/${c.id}/comp`, { reason }, t('pos.term.comp_recorded'), () => setPanel(null))} />}
                                    {typeof panel === 'object' && 'compLine' in panel && <ReasonForm label={t('pos.term.comp_item_sup')} busy={busy === 'comp'} onCancel={() => setPanel(null)}
                                        onSubmit={(reason) => run('comp', `pos/checks/${c.id}/comp`, { line_id: panel.compLine, reason }, t('pos.term.comp_recorded'), () => setPanel(null))} />}
                                    {panel === 'transfer' && <TransferForm outletId={outletId} busy={busy === 'move'} onCancel={() => setPanel(null)}
                                        onSubmit={(tableId) => run('move', `pos/checks/${c.id}/transfer`, { table_id: tableId }, t('pos.term.check_moved'), () => setPanel(null))} />}
                                    {typeof panel === 'object' && 'voidLine' in panel && <ReasonForm label={t('pos.term.void_line')} busy={busy === 'vline'} onCancel={() => setPanel(null)}
                                        onSubmit={(reason) => run('vline', `pos/checks/${c.id}/lines/${panel.voidLine}/void`, { reason }, t('pos.term.line_voided'), () => setPanel(null))} />}
                                    {panel === 'split' && <SplitForm check={c} busy={busy === 'split'} onCancel={() => setPanel(null)}
                                        onSubmit={(ids) => run<Check>('split', `pos/checks/${c.id}/split`, { line_ids: ids }, t('pos.term.check_split'), (r) => { setPanel(null); setSelectedId(r.id); })} />}
                                    {panel === 'settle' && <SettleForm total={c.total} busy={busy === 'settle'} onCancel={() => setPanel(null)}
                                        onSubmit={(payments, change) => settle(c, payments, change)} />}
                                    {panel === 'room' && <RoomForm busy={busy === 'room'} onCancel={() => setPanel(null)}
                                        onSubmit={(rid) => run('room', `pos/checks/${c.id}/charge-to-room`, { reservation_id: rid }, t('pos.term.charged_room'), () => { setPanel(null); setSelectedId(null); })} />}
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function ReasonForm({ label, withAmount, busy, onSubmit, onCancel }: { label: string; withAmount?: boolean; busy: boolean; onSubmit: (reason: string, amount: number) => void; onCancel: () => void }) {
    const t = useT();
    const [reason, setReason] = useState('');
    const [amount, setAmount] = useState('');
    return (
        <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); onSubmit(reason, Number(amount) || 0); }}>
            <p className="text-sm font-medium text-slate-700">{label}</p>
            {withAmount && <input className="input w-full" type="number" min={0} placeholder={t('pos.term.amount_fcfa')} value={amount} onChange={(e) => setAmount(e.target.value)} required />}
            <input className="input w-full" placeholder={t('pos.term.reason')} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} required />
            <div className="flex gap-2">
                <button className="btn-primary" disabled={busy}>{busy ? t('pos.common.saving') : t('pos.common.confirm')}</button>
                <button type="button" className="btn-secondary" onClick={onCancel}>{t('pos.common.cancel')}</button>
            </div>
        </form>
    );
}

function SplitForm({ check, busy, onSubmit, onCancel }: { check: Check; busy: boolean; onSubmit: (ids: number[]) => void; onCancel: () => void }) {
    const t = useT();
    const [ids, setIds] = useState<number[]>([]);
    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-slate-700">{t('pos.term.split_title')}</p>
            {check.lines.filter((l) => !l.voided).map((l) => (
                <label key={l.id} className="flex items-center gap-2 text-sm py-1">
                    <input type="checkbox" className="w-5 h-5" checked={ids.includes(l.id)} onChange={(e) => setIds(e.target.checked ? [...ids, l.id] : ids.filter((x) => x !== l.id))} />
                    {l.quantity} × {l.name} · {formatCurrency(l.amount)}
                </label>
            ))}
            <div className="flex gap-2">
                <button className="btn-primary" disabled={busy || !ids.length} onClick={() => onSubmit(ids)}>{t('pos.term.split')}</button>
                <button className="btn-secondary" onClick={onCancel}>{t('pos.common.cancel')}</button>
            </div>
        </div>
    );
}

interface PayRow { method: string; amount: string; tendered: string; reference: string }

function SettleForm({ total, busy, onSubmit, onCancel }: { total: number; busy: boolean; onSubmit: (p: object[], change: number) => void; onCancel: () => void }) {
    const t = useT();
    const methods = useApi<Code[]>('setup/payment-methods');
    const [rows, setRows] = useState<PayRow[]>([{ method: 'CASH', amount: String(total), tendered: '', reference: '' }]);
    const paid = rows.reduce((s, r) => s + (Number(r.amount) || 0), 0);
    const remaining = total - paid;
    const change = rows.reduce((s, r) => s + Math.max(0, (Number(r.tendered) || 0) - (Number(r.amount) || 0)), 0);
    const set = (i: number, patch: Partial<PayRow>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
    const active = (methods.data ?? []).filter((m) => m.active !== false);
    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-slate-700">{t('pos.term.settle_amount', { amount: formatCurrency(total) })}</p>
            {rows.map((r, i) => (
                <div key={i} className="grid grid-cols-2 gap-2 border border-slate-200 rounded-lg p-2">
                    <select className="input" value={r.method} onChange={(e) => set(i, { method: e.target.value })}>
                        {active.length === 0 && <option value={r.method}>{r.method}</option>}
                        {active.map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
                    </select>
                    <input className="input" type="number" min={1} placeholder={t('pos.term.amount')} value={r.amount} onChange={(e) => set(i, { amount: e.target.value })} />
                    <input className="input" type="number" min={0} placeholder={t('pos.term.tendered')} value={r.tendered} onChange={(e) => set(i, { tendered: e.target.value })} />
                    <div className="flex gap-1">
                        <input className="input w-full" placeholder={t('pos.term.reference')} value={r.reference} onChange={(e) => set(i, { reference: e.target.value })} />
                        {rows.length > 1 && <button className="text-red-500" onClick={() => setRows(rows.filter((_, j) => j !== i))}><X className="w-4 h-4" /></button>}
                    </div>
                </div>
            ))}
            <button className="btn-secondary text-sm" onClick={() => setRows([...rows, { method: active[0]?.code ?? 'CASH', amount: remaining > 0 ? String(remaining) : '', tendered: '', reference: '' }])}>
                <Plus className="w-4 h-4 inline" /> {t('pos.term.add_payment')}
            </button>
            <div className="text-sm">
                <div className={remaining === 0 ? 'text-emerald-700' : 'text-amber-700'}>{t('pos.term.remaining', { amount: formatCurrency(remaining) })}</div>
                {change > 0 && <div className="font-semibold text-emerald-700 text-lg">{t('pos.term.change_due', { amount: formatCurrency(change) })}</div>}
            </div>
            <div className="flex gap-2">
                <button className="btn-primary py-3 px-6" disabled={busy || remaining !== 0}
                    onClick={() => onSubmit(rows.filter((r) => Number(r.amount) > 0).map((r) => ({ method: r.method, amount: Number(r.amount), tendered: r.tendered ? Number(r.tendered) : null, reference: r.reference || null })), change)}>
                    {busy ? t('pos.term.settling') : t('pos.term.settle')}
                </button>
                <button className="btn-secondary" onClick={onCancel}>{t('pos.common.cancel')}</button>
            </div>
        </div>
    );
}

function TransferForm({ outletId, busy, onSubmit, onCancel }: { outletId: number; busy: boolean; onSubmit: (id: number) => void; onCancel: () => void }) {
    const t = useT();
    const tables = useApi<Paginated<{ id: number; name: string; seats: number }>>(`pos/tables?filter[outlet_id]=${outletId}&filter[active]=1&per_page=200&sort=name`);
    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-slate-700">{t('pos.term.move_title')}</p>
            {tables.loading && <Loader2 className="w-4 h-4 animate-spin text-slate-400" />}
            {tables.error && <p className="text-sm text-red-600">{tables.error.message}</p>}
            <div className="grid grid-cols-3 gap-2">
                {tables.data?.data.map((tb) => <button key={tb.id} className="btn-secondary py-3" disabled={busy} onClick={() => onSubmit(tb.id)}>{tb.name}</button>)}
            </div>
            <button className="btn-secondary" onClick={onCancel}>{t('pos.common.cancel')}</button>
        </div>
    );
}

function RoomForm({ busy, onSubmit, onCancel }: { busy: boolean; onSubmit: (id: number) => void; onCancel: () => void }) {
    const t = useT();
    const [q, setQ] = useState('');
    const list = useApi<Paginated<ResvRow>>(`reservations?status=checked_in&q=${encodeURIComponent(q)}&per_page=20`);
    return (
        <div className="space-y-2">
            <p className="text-sm font-medium text-slate-700">{t('pos.term.room_title')}</p>
            <div className="relative">
                <Search className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                <input className="input w-full pl-9" placeholder={t('pos.term.room_search')} value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
            {list.loading && <Loader2 className="w-4 h-4 animate-spin text-slate-400" />}
            {list.error && <p className="text-sm text-red-600">{list.error.message}</p>}
            <div className="max-h-60 overflow-y-auto space-y-1">
                {list.data?.data.map((r) => (
                    <button key={r.id} disabled={busy} onClick={() => onSubmit(r.id)} className="w-full text-left border border-slate-200 rounded-lg p-3 hover:bg-emerald-50">
                        <div className="font-medium text-sm">{t('pos.term.room_n', { number: r.room?.number ?? '-' })} · {r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : t('pos.term.guest')}</div>
                        <div className="text-xs text-slate-500">{r.number} · {t('pos.term.departs', { date: r.departure })}</div>
                    </button>
                ))}
                {list.data && list.data.data.length === 0 && <p className="text-sm text-slate-400">{t('pos.term.no_guests')}</p>}
            </div>
            <button className="btn-secondary" onClick={onCancel}>{t('pos.common.cancel')}</button>
        </div>
    );
}
