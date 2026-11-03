import { Loader2, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { Banner, errText, MenuItem, Msg, Outlet, Paginated } from './shared';
import { useT } from '@/lib/i18n';

const blank = { code: '', name: '', name_en: '', outlet_id: '', category: '', price: '', charge_code: 'FNB', kitchen_station: '', active: true };
type Form = typeof blank;

export default function MenuTab({ outlets }: { outlets: Outlet[] }) {
    const t = useT();
    const [q, setQ] = useState('');
    const [cat, setCat] = useState('');
    const [outlet, setOutlet] = useState('');
    const qs = new URLSearchParams({ q, per_page: '200', sort: 'category' });
    if (cat) qs.set('filter[category]', cat);
    if (outlet) qs.set('filter[outlet_id]', outlet);
    const list = useApi<Paginated<MenuItem>>(`pos/menu-items?${qs}`);
    const [form, setForm] = useState<Form | null>(null);
    const [editId, setEditId] = useState<number | null>(null);
    const [msg, setMsg] = useState<Msg>(null);
    const [busy, setBusy] = useState(false);
    const outletName = (id: number | null) => (id ? outlets.find((o) => o.id === id)?.name ?? `#${id}` : t('pos.common.all_outlets'));

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!form) return;
        setBusy(true);
        setMsg(null);
        const body = {
            ...form, price: Number(form.price), outlet_id: form.outlet_id ? Number(form.outlet_id) : null,
            name_en: form.name_en || null, category: form.category || null, kitchen_station: form.kitchen_station || null,
        };
        try {
            await api(editId ? `pos/menu-items/${editId}` : 'pos/menu-items', { method: editId ? 'PUT' : 'POST', body: JSON.stringify(body) });
            setMsg({ ok: true, text: editId ? t('pos.menu.updated') : t('pos.menu.created') });
            setForm(null);
            setEditId(null);
            list.reload();
        } catch (err) {
            setMsg({ ok: false, text: errText(err) });
        } finally {
            setBusy(false);
        }
    }

    async function remove(m: MenuItem) {
        if (!confirm(t('pos.menu.confirm_delete', { name: m.name }))) return;
        try {
            await api(`pos/menu-items/${m.id}`, { method: 'DELETE' });
            setMsg({ ok: true, text: t('pos.menu.deleted') });
            list.reload();
        } catch (err) {
            setMsg({ ok: false, text: errText(err) });
        }
    }

    const field = (k: keyof Form, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
        <label className="text-sm">
            <span className="text-slate-600">{label}</span>
            <input className="input w-full" value={String(form![k])} onChange={(e) => setForm({ ...form!, [k]: e.target.value })} {...props} />
        </label>
    );

    return (
        <div>
            <Banner msg={msg} />
            <div className="flex flex-wrap gap-2 mb-4">
                <div className="relative flex-1 min-w-[180px]">
                    <Search className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
                    <input className="input w-full pl-9" placeholder={t('pos.menu.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <input className="input w-40" placeholder={t('pos.menu.category')} value={cat} onChange={(e) => setCat(e.target.value)} />
                <select className="input w-44" value={outlet} onChange={(e) => setOutlet(e.target.value)}>
                    <option value="">{t('pos.menu.any_outlet')}</option>
                    {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
                <button className="btn-primary flex items-center gap-1" onClick={() => { setForm({ ...blank }); setEditId(null); }}><Plus className="w-4 h-4" /> {t('pos.menu.new')}</button>
            </div>

            {form && (
                <form onSubmit={save} className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    {field('code', t('pos.menu.code'), { required: true, maxLength: 20 })}
                    {field('name', t('pos.menu.name'), { required: true, maxLength: 150 })}
                    {field('name_en', t('pos.menu.name_en'), { maxLength: 150 })}
                    {field('category', t('pos.menu.category'), { maxLength: 50 })}
                    {field('price', t('pos.menu.price_fcfa'), { type: 'number', min: 0, required: true })}
                    {field('charge_code', t('pos.menu.charge_code'), { maxLength: 16 })}
                    {field('kitchen_station', t('pos.menu.station_full'), { maxLength: 30, placeholder: t('pos.menu.station_ph') })}
                    <label className="text-sm">
                        <span className="text-slate-600">{t('pos.menu.outlet')}</span>
                        <select className="input w-full" value={form.outlet_id} onChange={(e) => setForm({ ...form, outlet_id: e.target.value })}>
                            <option value="">{t('pos.common.all_outlets')}</option>
                            {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </select>
                    </label>
                    <label className="text-sm flex items-center gap-2 mt-5">
                        <input type="checkbox" checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} /> {t('pos.common.active')}
                    </label>
                    <div className="sm:col-span-3 flex gap-2">
                        <button className="btn-primary" disabled={busy}>{busy ? t('pos.common.saving') : t('pos.common.save')}</button>
                        <button type="button" className="btn-secondary" onClick={() => { setForm(null); setEditId(null); }}>{t('pos.common.cancel')}</button>
                    </div>
                </form>
            )}

            <div className="card overflow-x-auto">
                {list.loading && !list.data && <Loader2 className="w-5 h-5 animate-spin text-slate-400 m-4" />}
                {list.error && <p className="text-sm text-red-600 p-4">{list.error.message}</p>}
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-3">{t('pos.menu.code')}</th><th>{t('pos.menu.name')}</th><th>{t('pos.menu.category')}</th><th>{t('pos.menu.outlet')}</th><th>{t('pos.menu.station')}</th><th className="text-right">{t('pos.menu.price')}</th><th>{t('pos.menu.status')}</th><th /></tr></thead>
                    <tbody>
                        {list.data?.data.map((m) => (
                            <tr key={m.id} className="border-t">
                                <td className="p-3 font-mono">{m.code}</td>
                                <td>{m.name}</td>
                                <td>{m.category ?? '-'}</td>
                                <td>{outletName(m.outlet_id)}</td>
                                <td>{m.kitchen_station ?? '-'}</td>
                                <td className="text-right">{formatCurrency(m.price)}</td>
                                <td>{m.active ? <span className="text-emerald-700">{t('pos.common.active')}</span> : <span className="text-slate-400">{t('pos.common.inactive')}</span>}</td>
                                <td className="text-right whitespace-nowrap pr-3">
                                    <button className="text-slate-500 mr-2" title={t('pos.common.edit')} onClick={() => {
                                        setEditId(m.id);
                                        setForm({ code: m.code, name: m.name, name_en: m.name_en ?? '', outlet_id: m.outlet_id ? String(m.outlet_id) : '', category: m.category ?? '', price: String(m.price), charge_code: m.charge_code, kitchen_station: m.kitchen_station ?? '', active: m.active });
                                    }}><Pencil className="w-4 h-4" /></button>
                                    <button className="text-red-500" title={t('pos.common.delete')} onClick={() => remove(m)}><Trash2 className="w-4 h-4" /></button>
                                </td>
                            </tr>
                        ))}
                        {list.data && list.data.data.length === 0 && <tr><td colSpan={8} className="p-4 text-slate-400">{t('pos.menu.empty')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
