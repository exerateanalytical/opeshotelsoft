import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import StockTabs from './StockTabs';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num, Paged, Ref, usePerms } from './ControlShared';

interface RecipeRow { id: number; code: string; name: string; kind: string; unit_cost: number; price: number; margin_pct: number | null; food_cost_pct: number | null; below_target: boolean; target_margin_pct: number }
interface Analysis { plate_cost: number; price: number; net_price: number; margin_pct: number | null; food_cost_pct: number | null; below_target: boolean; lines: { id: number; kind: string; name: string; quantity: number; unit: string; yield_pct: number; cost: number }[] }
interface Detail { id: number; code: string; name: string; yield_qty: number; yield_unit: string; analysis: Analysis }
interface DraftLine { ref: string; quantity: string; unit: string; yield_pct: string }

export default function Recipes() {
    const t = useT();
    const canManage = usePerms().includes('stockcontrol.manage');
    const list = useApi<RecipeRow[]>('stock-control/recipes');
    const items = useApi<Paged<Ref>>('stock/items?per_page=200');
    const menu = useApi<Paged<Ref>>('pos/menu-items?per_page=200');
    const [openId, setOpenId] = useState<number | null>(null);
    const detail = useApi<Detail>(openId ? `stock-control/recipes/${openId}` : null, [openId]);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [form, setForm] = useState({ code: '', name: '', kind: 'dish', pos_menu_item_id: '', yield_qty: '1', yield_unit: 'portion', target_margin_pct: '65' });
    const [lines, setLines] = useState<DraftLine[]>([{ ref: '', quantity: '', unit: '', yield_pct: '100' }]);
    const subs = (list.data ?? []).filter((r) => r.kind === 'sub_recipe');

    async function save() {
        try {
            await api('stock-control/recipes', {
                method: 'POST',
                body: JSON.stringify({
                    code: form.code, name: form.name, kind: form.kind, yield_qty: Number(form.yield_qty), yield_unit: form.yield_unit, target_margin_pct: Number(form.target_margin_pct),
                    pos_menu_item_id: form.kind === 'dish' && form.pos_menu_item_id ? Number(form.pos_menu_item_id) : null,
                    lines: lines.filter((l) => l.ref && l.quantity).map((l) => ({
                        ...(l.ref.startsWith('s') ? { sub_recipe_id: Number(l.ref.slice(1)) } : { stock_item_id: Number(l.ref.slice(1)) }),
                        quantity: Number(l.quantity), unit: l.unit || null, yield_pct: Number(l.yield_pct || 100),
                    })),
                }),
            });
            setMsg({ ok: true, text: t('stock2.recipes.saved') });
            setForm({ ...form, code: '', name: '' });
            setLines([{ ref: '', quantity: '', unit: '', yield_pct: '100' }]);
            list.reload();
        } catch (e) { setMsg({ ok: false, text: errMsg(e) }); }
    }
    async function recost(id: number) {
        try { await api(`stock-control/recipes/${id}/recost`, { method: 'POST', body: '{}' }); list.reload(); detail.reload(); } catch (e) { setMsg({ ok: false, text: errMsg(e) }); }
    }
    const setLine = (i: number, patch: Partial<DraftLine>) => setLines(lines.map((l, k) => (k === i ? { ...l, ...patch } : l)));

    return (
        <AppLayout title={t('stock2.recipes.title')} subtitle={t('stock2.recipes.subtitle')}>
            <Head title={t('stock2.recipes.title')} />
            <QueryStatus query={list} variant="table" />
            <StockTabs current="recipes" />
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            <div className="card mb-4 overflow-x-auto p-3">
                <table className="w-full table-auto text-sm">
                    <thead><tr className="text-left text-xs uppercase text-slate-500">
                        {['code', 'name', 'kind', 'cost', 'price', 'margin', 'food_cost'].map((h) => <th key={h} className="px-2 py-2">{t(`stock2.recipes.col.${h}`)}</th>)}<th /></tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {(list.data ?? []).map((r) => (
                            <tr key={r.id} className="cursor-pointer" onClick={() => setOpenId(r.id)}>
                                <td className="px-2 py-2">{r.code}</td><td className="px-2 py-2">{r.name}</td><td className="px-2 py-2">{t(`stock2.recipes.kind.${r.kind}`)}</td>
                                <td className="px-2 py-2">{fcfa(r.unit_cost)}</td><td className="px-2 py-2">{r.kind === 'dish' ? fcfa(r.price) : '-'}</td>
                                <td className={`px-2 py-2 ${r.below_target ? 'text-red-700' : ''}`}>{r.margin_pct === null ? '-' : `${r.margin_pct} %`}</td>
                                <td className="px-2 py-2">{r.food_cost_pct === null ? '-' : `${r.food_cost_pct} %`}</td>
                                <td className="px-2 py-2">{canManage && <button type="button" className="btn-secondary" onClick={(e) => { e.stopPropagation(); recost(r.id); }}>{t('stock2.recipes.recost')}</button>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {list.data?.length === 0 && <p className="py-4 text-center text-sm text-slate-500">{t('stock2.common.nothing')}</p>}
            </div>

            {detail.data && openId && (
                <div className="card mb-4 p-3 text-sm">
                    <h2 className="mb-2 font-medium">{detail.data.code} - {detail.data.name} ({num(detail.data.yield_qty)} {detail.data.yield_unit})</h2>
                    <table className="w-full table-auto">
                        <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="px-2 py-1">{t('stock2.recipes.col.ingredient')}</th><th className="px-2 py-1">{t('stock2.recipes.col.quantity')}</th><th className="px-2 py-1">{t('stock2.recipes.col.yield')}</th><th className="px-2 py-1">{t('stock2.recipes.col.cost')}</th></tr></thead>
                        <tbody>{detail.data.analysis.lines.map((l) => (
                            <tr key={l.id}><td className="px-2 py-1">{l.name}</td><td className="px-2 py-1">{num(l.quantity)} {l.unit}</td><td className="px-2 py-1">{l.yield_pct} %</td><td className="px-2 py-1">{fcfa(l.cost)}</td></tr>
                        ))}</tbody>
                    </table>
                    <p className="mt-2">{t('stock2.recipes.plate_cost')}: <b>{fcfa(detail.data.analysis.plate_cost)}</b>
                        {detail.data.analysis.margin_pct !== null && <> - {t('stock2.recipes.col.margin')}: <b className={detail.data.analysis.below_target ? 'text-red-700' : ''}>{detail.data.analysis.margin_pct} %</b> ({t('stock2.recipes.net_price')} {fcfa(detail.data.analysis.net_price)})</>}</p>
                </div>
            )}

            {canManage && (
                <div className="card p-3 text-sm">
                    <h2 className="mb-2 font-medium">{t('stock2.recipes.new')}</h2>
                    <div className="mb-2 grid grid-cols-2 gap-2 md:grid-cols-4 [&>*]:min-w-0">
                        <input className="input" placeholder={t('stock2.recipes.col.code')} aria-label={t('stock2.recipes.col.code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
                        <input className="input" placeholder={t('stock2.recipes.col.name')} aria-label={t('stock2.recipes.col.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        <select className="input" aria-label={t('stock2.recipes.col.kind')} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                            <option value="dish">{t('stock2.recipes.kind.dish')}</option><option value="sub_recipe">{t('stock2.recipes.kind.sub_recipe')}</option>
                        </select>
                        {form.kind === 'dish' && (
                            <select className="input" value={form.pos_menu_item_id} onChange={(e) => setForm({ ...form, pos_menu_item_id: e.target.value })} aria-label={t('stock2.recipes.menu_item')}>
                                <option value="">{t('stock2.recipes.menu_item')}</option>
                                {(menu.data?.data ?? []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        )}
                        <input className="input" type="number" placeholder={t('stock2.recipes.yield_qty')} aria-label={t('stock2.recipes.yield_qty')} value={form.yield_qty} onChange={(e) => setForm({ ...form, yield_qty: e.target.value })} />
                        <input className="input" placeholder={t('stock2.recipes.yield_unit')} aria-label={t('stock2.recipes.yield_unit')} value={form.yield_unit} onChange={(e) => setForm({ ...form, yield_unit: e.target.value })} />
                        <input className="input" type="number" placeholder={t('stock2.recipes.target_margin')} aria-label={t('stock2.recipes.target_margin')} value={form.target_margin_pct} onChange={(e) => setForm({ ...form, target_margin_pct: e.target.value })} />
                    </div>
                    {lines.map((l, i) => (
                        <div key={i} className="mb-1 grid grid-cols-2 gap-2 md:grid-cols-4 [&>*]:min-w-0">
                            <select className="input" value={l.ref} onChange={(e) => setLine(i, { ref: e.target.value })} aria-label={t('stock2.recipes.col.ingredient')}>
                                <option value="">{t('stock2.recipes.col.ingredient')}</option>
                                {(items.data?.data ?? []).map((it) => <option key={`i${it.id}`} value={`i${it.id}`}>{it.name}</option>)}
                                {subs.map((s) => <option key={`s${s.id}`} value={`s${s.id}`}>{s.name} ({t('stock2.recipes.kind.sub_recipe')})</option>)}
                            </select>
                            <input className="input" type="number" placeholder={t('stock2.recipes.col.quantity')} aria-label={t('stock2.recipes.col.quantity')} value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />
                            <input className="input" placeholder={t('stock2.recipes.unit')} aria-label={t('stock2.recipes.unit')} value={l.unit} onChange={(e) => setLine(i, { unit: e.target.value })} />
                            <input className="input" type="number" placeholder={t('stock2.recipes.col.yield')} aria-label={t('stock2.recipes.col.yield')} value={l.yield_pct} onChange={(e) => setLine(i, { yield_pct: e.target.value })} />
                        </div>
                    ))}
                    <div className="mt-2 flex gap-2">
                        <button type="button" className="btn-secondary" onClick={() => setLines([...lines, { ref: '', quantity: '', unit: '', yield_pct: '100' }])}>{t('stock2.recipes.add_line')}</button>
                        <button type="button" className="btn-primary" onClick={save}>{t('stock2.common.save')}</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
