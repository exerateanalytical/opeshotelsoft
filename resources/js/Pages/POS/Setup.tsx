import { Loader2, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { Banner, errText, MenuItem, Msg, Outlet, Paginated } from './shared';
import { useT } from '@/lib/i18n';

interface Table { id: number; outlet_id: number; name: string; seats: number; pos_x: number; pos_y: number; active: boolean }
interface PriceList {
    id: number; outlet_id: number | null; name: string; days: number[]; starts_at: string; ends_at: string; percent_off: number; priority: number; active: boolean;
    items: { pos_menu_item_id: number; price: number }[];
}
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

/** Outlet set-up: floor-plan tables and price levels (happy hour). Changes need pos.configure. */
export default function Setup({ outlets, outletId }: { outlets: Outlet[]; outletId: number }) {
    const tr = useT();
    const tables = useApi<Paginated<Table>>(`pos/tables?filter[outlet_id]=${outletId}&per_page=200&sort=name`);
    const lists = useApi<Paginated<PriceList>>('pos/price-lists?per_page=100');
    const menu = useApi<Paginated<MenuItem>>('pos/menu-items?filter[active]=1&per_page=500&sort=name');
    const [msg, setMsg] = useState<Msg>(null);
    const [t, setT] = useState({ name: '', seats: '4', pos_x: '0', pos_y: '0' });
    const [pl, setPl] = useState<{ name: string; outlet_id: string; days: number[]; starts_at: string; ends_at: string; percent_off: string; items: { pos_menu_item_id: string; price: string }[] } | null>(null);

    async function call(path: string, method: string, body: object | undefined, ok: string, after?: () => void) {
        setMsg(null);
        try {
            await api(path, { method, body: body ? JSON.stringify(body) : undefined });
            setMsg({ ok: true, text: ok });
            tables.reload();
            lists.reload();
            after?.();
        } catch (e) {
            setMsg({ ok: false, text: errText(e) });
        }
    }

    const outletName = (id: number | null) => (id ? outlets.find((o) => o.id === id)?.name ?? `#${id}` : tr('pos.common.all_outlets'));

    return (
        <div>
            <Banner msg={msg} />
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="card p-4">
                    <h3 className="font-semibold text-slate-800 mb-2">{tr('pos.setup.tables', { name: outletName(outletId) })}</h3>
                    <form className="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-3" onSubmit={(e) => {
                        e.preventDefault();
                        call('pos/tables', 'POST', { outlet_id: outletId, name: t.name, seats: Number(t.seats), pos_x: Number(t.pos_x), pos_y: Number(t.pos_y) }, tr('pos.setup.table_added'), () => setT({ ...t, name: '' }));
                    }}>
                        <input className="input" placeholder={tr('pos.menu.name')} required maxLength={10} value={t.name} onChange={(e) => setT({ ...t, name: e.target.value })} />
                        <input className="input" type="number" min={1} max={50} title={tr('pos.setup.seats')} value={t.seats} onChange={(e) => setT({ ...t, seats: e.target.value })} />
                        <input className="input" type="number" min={0} max={30} title={tr('pos.setup.column')} value={t.pos_x} onChange={(e) => setT({ ...t, pos_x: e.target.value })} />
                        <input className="input" type="number" min={0} max={30} title={tr('pos.setup.row')} value={t.pos_y} onChange={(e) => setT({ ...t, pos_y: e.target.value })} />
                        <button className="btn-primary"><Plus className="w-4 h-4" /> {tr('pos.common.add')}</button>
                    </form>
                    <p className="text-xs text-slate-500 mb-2">{tr('pos.setup.grid_hint')}</p>
                    {tables.loading && !tables.data && <Loader2 className="w-4 h-4 animate-spin text-slate-400" />}
                    {tables.error && <p className="text-sm text-red-600">{tables.error.message}</p>}
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm"><tbody>
                            {tables.data?.data.map((r) => (
                                <tr key={r.id} className="border-t border-slate-100">
                                    <td className="py-1 font-medium">{r.name}</td><td>{tr('pos.setup.seats_n', { count: r.seats })}</td><td>{tr('pos.setup.col_row', { col: r.pos_x, row: r.pos_y })}</td>
                                    <td>{r.active ? <span className="badge-green">{tr('pos.common.active')}</span> : <span className="badge-slate">{tr('pos.common.off')}</span>}</td>
                                    <td className="text-right">{r.active && <button className="btn-secondary" onClick={() => call(`pos/tables/${r.id}`, 'DELETE', undefined, tr('pos.setup.table_deactivated'))}>{tr('pos.common.deactivate')}</button>}</td>
                                </tr>
                            ))}
                        </tbody></table>
                    </div>
                </div>

                <div className="card p-4">
                    <div className="flex justify-between items-center mb-2">
                        <h3 className="font-semibold text-slate-800">{tr('pos.setup.price_levels')}</h3>
                        {!pl && <button className="btn-secondary" onClick={() => setPl({ name: '', outlet_id: String(outletId), days: [], starts_at: '17:00', ends_at: '19:00', percent_off: '0', items: [] })}><Plus className="w-4 h-4" /> {tr('pos.common.new')}</button>}
                    </div>
                    {pl && (
                        <form className="space-y-2 mb-3" onSubmit={(e) => {
                            e.preventDefault();
                            call('pos/price-lists', 'POST', {
                                name: pl.name, outlet_id: pl.outlet_id ? Number(pl.outlet_id) : null, days: pl.days, starts_at: pl.starts_at, ends_at: pl.ends_at,
                                percent_off: Number(pl.percent_off) || 0, items: pl.items.filter((i) => i.pos_menu_item_id).map((i) => ({ pos_menu_item_id: Number(i.pos_menu_item_id), price: Number(i.price) })),
                            }, tr('pos.setup.level_saved'), () => setPl(null));
                        }}>
                            <input className="input w-full" placeholder={tr('pos.setup.level_name_ph')} required value={pl.name} onChange={(e) => setPl({ ...pl, name: e.target.value })} />
                            <select className="input w-full" value={pl.outlet_id} onChange={(e) => setPl({ ...pl, outlet_id: e.target.value })}>
                                <option value="">{tr('pos.common.all_outlets')}</option>
                                {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                            <div className="flex flex-wrap gap-1">
                                {DAYS.map((d, i) => {
                                    const on = pl.days.includes(i + 1);
                                    return <button type="button" key={d} className={on ? 'btn-primary' : 'btn-secondary'} onClick={() => setPl({ ...pl, days: on ? pl.days.filter((x) => x !== i + 1) : [...pl.days, i + 1] })}>{tr(`pos.day.${d}`)}</button>;
                                })}
                            </div>
                            <p className="text-xs text-slate-500">{tr('pos.setup.days_hint')}</p>
                            <div className="grid grid-cols-3 gap-2">
                                <label className="label">{tr('pos.setup.from')}<input className="input w-full" type="time" required value={pl.starts_at} onChange={(e) => setPl({ ...pl, starts_at: e.target.value })} /></label>
                                <label className="label">{tr('pos.setup.to')}<input className="input w-full" type="time" required value={pl.ends_at} onChange={(e) => setPl({ ...pl, ends_at: e.target.value })} /></label>
                                <label className="label">{tr('pos.setup.percent_off')}<input className="input w-full" type="number" min={0} max={100} value={pl.percent_off} onChange={(e) => setPl({ ...pl, percent_off: e.target.value })} /></label>
                            </div>
                            <p className="text-xs text-slate-500">{tr('pos.setup.fixed_hint')}</p>
                            {pl.items.map((it, i) => (
                                <div key={i} className="flex gap-2">
                                    <select className="input w-full" value={it.pos_menu_item_id} onChange={(e) => setPl({ ...pl, items: pl.items.map((x, j) => (j === i ? { ...x, pos_menu_item_id: e.target.value } : x)) })}>
                                        <option value="">{tr('pos.setup.item_ph')}</option>
                                        {menu.data?.data.map((m) => <option key={m.id} value={m.id}>{m.name} ({formatCurrency(m.price)})</option>)}
                                    </select>
                                    <input className="input w-28" type="number" min={0} placeholder={tr('pos.menu.price')} required value={it.price} onChange={(e) => setPl({ ...pl, items: pl.items.map((x, j) => (j === i ? { ...x, price: e.target.value } : x)) })} />
                                    <button type="button" title={tr('pos.common.remove')} className="text-slate-500" onClick={() => setPl({ ...pl, items: pl.items.filter((_, j) => j !== i) })}><X className="w-4 h-4" /></button>
                                </div>
                            ))}
                            <button type="button" className="btn-secondary" onClick={() => setPl({ ...pl, items: [...pl.items, { pos_menu_item_id: '', price: '' }] })}><Plus className="w-4 h-4" /> {tr('pos.setup.fixed_price')}</button>
                            <div className="flex gap-2"><button className="btn-primary">{tr('pos.common.save')}</button><button type="button" className="btn-secondary" onClick={() => setPl(null)}>{tr('pos.common.cancel')}</button></div>
                        </form>
                    )}
                    {lists.error && <p className="text-sm text-red-600">{lists.error.message}</p>}
                    {lists.data?.data.map((l) => (
                        <div key={l.id} className="border-t border-slate-100 py-2 text-sm flex justify-between gap-2">
                            <div>
                                <div className="font-medium text-slate-800">{l.name} {l.active ? <span className="badge-green">{tr('pos.common.active')}</span> : <span className="badge-slate">{tr('pos.common.off')}</span>}</div>
                                <div className="text-slate-500">
                                    {outletName(l.outlet_id)} · {l.days.length ? l.days.map((d) => tr(`pos.day.${DAYS[d - 1]}`)).join(', ') : tr('pos.setup.every_day')} · {l.starts_at.slice(0, 5)}–{l.ends_at.slice(0, 5)}
                                    {l.percent_off > 0 ? ` · ${tr('pos.setup.pct_off_n', { pct: l.percent_off })}` : ''}{l.items.length ? ` · ${tr('pos.setup.fixed_n', { count: l.items.length })}` : ''}
                                </div>
                            </div>
                            {l.active && <button className="btn-secondary" onClick={() => call(`pos/price-lists/${l.id}`, 'DELETE', undefined, tr('pos.setup.level_deactivated'))}>{tr('pos.common.deactivate')}</button>}
                        </div>
                    ))}
                    {lists.data?.data.length === 0 && <p className="text-sm text-slate-400">{tr('pos.setup.no_levels')}</p>}
                </div>
            </div>
        </div>
    );
}
