import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2, Plus } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { errorText } from './lib';
import Alert from '@/Components/ui/Alert';

type Kind = 'multiplier' | 'birthday' | 'enrolment';
interface Promotion { id: number; name: string; kind: Kind; multiplier: number; bonus_points: number; starts_on: string | null; ends_on: string | null; outlet_ids: number[] | null; active: boolean }
interface Outlet { id: number; code: string; name: string }
interface Form { name: string; kind: Kind; multiplier: string; bonus_points: string; starts_on: string; ends_on: string; outlet_ids: number[] }

const EMPTY: Form = { name: '', kind: 'multiplier', multiplier: '2', bonus_points: '0', starts_on: '', ends_on: '', outlet_ids: [] };

export default function LoyaltyPromotions() {
    const t = useT();
    const list = useApi<Promotion[]>('loyalty/promotions');
    const outlets = useApi<Outlet[] | { data: Outlet[] }>('outlets');
    const outletList: Outlet[] = Array.isArray(outlets.data) ? outlets.data : outlets.data?.data ?? [];
    const [form, setForm] = useState<Form | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!form) return;
        setBusy(true); setError(null);
        try {
            await api('loyalty/promotions', { method: 'POST', body: JSON.stringify({
                name: form.name, kind: form.kind,
                multiplier: form.kind === 'multiplier' ? Number(form.multiplier) : 1,
                bonus_points: form.kind === 'multiplier' ? 0 : Number(form.bonus_points),
                starts_on: form.starts_on || null, ends_on: form.ends_on || null,
                outlet_ids: form.kind === 'multiplier' && form.outlet_ids.length ? form.outlet_ids : null,
            }) });
            setForm(null); list.reload();
        } catch (err) { setError(errorText(err)); } finally { setBusy(false); }
    }

    async function toggle(p: Promotion) {
        try { await api(`loyalty/promotions/${p.id}`, { method: 'PATCH', body: JSON.stringify({ active: !p.active }) }); list.reload(); } catch (err) { setError(errorText(err)); }
    }

    const outletName = (id: number) => outletList.find((o) => o.id === id)?.name ?? `#${id}`;

    return (
        <AppLayout title={t('loyalty.promo.title')} subtitle={t('loyalty.promo.subtitle')}
            actions={<button className="btn-primary inline-flex items-center gap-2" onClick={() => setForm({ ...EMPTY })}><Plus className="w-4 h-4" /> {t('loyalty.promo.new')}</button>}>
            <Head title={t('loyalty.promo.title')} />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}

            {form && (
                <form onSubmit={save} className="card p-4 mb-4 grid gap-3 sm:grid-cols-3 text-sm">
                    <label className="sm:col-span-2">{t('loyalty.promo.name')}<input className="input w-full" required maxLength={100} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
                    <label>{t('loyalty.promo.kind')}
                        <select className="select w-full" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as Kind })}>
                            {(['multiplier', 'birthday', 'enrolment'] as Kind[]).map((k) => <option key={k} value={k}>{t(`loyalty.promo.kind_${k}`)}</option>)}
                        </select>
                    </label>
                    {form.kind === 'multiplier'
                        ? <label>{t('loyalty.promo.multiplier')}<input type="number" min={1} max={10} step={0.25} className="input w-full" value={form.multiplier} onChange={(e) => setForm({ ...form, multiplier: e.target.value })} /></label>
                        : <label>{t('loyalty.promo.bonus')}<input type="number" min={0} className="input w-full" value={form.bonus_points} onChange={(e) => setForm({ ...form, bonus_points: e.target.value })} /></label>}
                    <label>{t('loyalty.promo.starts')}<input type="date" className="input w-full" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} /></label>
                    <label>{t('loyalty.promo.ends')}<input type="date" className="input w-full" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} /></label>
                    {form.kind === 'multiplier' && (
                        <fieldset className="sm:col-span-3">
                            <legend className="mb-1">{t('loyalty.promo.outlets')} <span className="text-slate-500">({t('loyalty.promo.all_hint')})</span></legend>
                            <div className="flex flex-wrap gap-3">
                                {outletList.map((o) => (
                                    <label key={o.id} className="inline-flex items-center gap-1">
                                        <input type="checkbox" checked={form.outlet_ids.includes(o.id)} onChange={(e) => setForm({ ...form, outlet_ids: e.target.checked ? [...form.outlet_ids, o.id] : form.outlet_ids.filter((x) => x !== o.id) })} /> {o.name}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    )}
                    <div className="sm:col-span-3 flex gap-2 justify-end">
                        <button type="button" className="btn-secondary" onClick={() => setForm(null)}>{t('loyalty.promo.cancel')}</button>
                        <button className="btn-primary" disabled={busy}>{t('loyalty.promo.save')}</button>
                    </div>
                </form>
            )}

            {list.loading && !list.data && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            {list.error && <div className="text-sm text-red-600">{list.error.message}</div>}
            {list.data && (
                <div className="card table-wrap">
                    <table className="table-auto w-full text-sm">
                        <thead><tr className="text-left text-xs text-slate-500 border-b">
                            <th className="p-2">{t('loyalty.promo.name')}</th><th className="p-2">{t('loyalty.promo.kind')}</th><th className="p-2">{t('loyalty.promo.rule')}</th>
                            <th className="p-2">{t('loyalty.promo.period')}</th><th className="p-2">{t('loyalty.promo.outlets')}</th><th className="p-2" />
                        </tr></thead>
                        <tbody>
                            {list.data.map((p) => (
                                <tr key={p.id} className={`border-b last:border-0 ${p.active ? '' : 'text-slate-500'}`}>
                                    <td className="p-2">{p.name}</td>
                                    <td className="p-2">{t(`loyalty.promo.kind_${p.kind}`)}</td>
                                    <td className="p-2">{p.kind === 'multiplier' ? `× ${p.multiplier}` : `+${p.bonus_points.toLocaleString()} pts`}</td>
                                    <td className="p-2 whitespace-nowrap">{p.starts_on ? formatDate(p.starts_on) : '…'} → {p.ends_on ? formatDate(p.ends_on) : '…'}</td>
                                    <td className="p-2">{p.outlet_ids?.length ? p.outlet_ids.map(outletName).join(', ') : t('loyalty.promo.all')}</td>
                                    <td className="p-2 text-right"><button className="btn-secondary" onClick={() => toggle(p)}>{p.active ? t('loyalty.promo.deactivate') : t('loyalty.promo.activate')}</button></td>
                                </tr>
                            ))}
                            {list.data.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-slate-500">{t('loyalty.promo.empty')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
