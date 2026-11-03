import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Badge, Banner, errMsg, Field, Loading, today } from './shared';
import { useT } from '@/lib/i18n';

interface AssetClass { id: number; code: string; name: string; asset_account: string; depreciation_account: string; expense_account: string; method: string; useful_life_months: number }
interface Asset { id: number; code: string; name: string; asset_account: string; acquired_on: string; cost: number; accumulated: number; net_book_value: number; method: string; status: string; disposal_result: number | null }
interface Run { id: number; year: number; month: number; total: number }

export default function Assets() {
    const t = useT();
    const classes = useApi<AssetClass[]>('accounting/asset-classes');
    const assets = useApi<Asset[]>('accounting/fixed-assets');
    const runs = useApi<Run[]>('accounting/depreciation-runs');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const now = new Date();
    const [cls, setCls] = useState({ code: '', name: '', asset_account: '244000', depreciation_account: '284400', expense_account: '681000', method: 'straight_line', useful_life_months: 60 });
    const [acq, setAcq] = useState({ asset_class_id: '', name: '', acquired_on: today(), cost: '', supplier_invoice_id: '' });
    const [period, setPeriod] = useState({ year: now.getFullYear(), month: now.getMonth() + 1 });
    const [dispose, setDispose] = useState<Asset | null>(null);
    const [disp, setDisp] = useState({ date: today(), proceeds: '0' });

    async function post(path: string, body: object, msg: string) {
        setBusy(true); setError(null); setOk(null);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            setOk(msg); setDispose(null);
            classes.reload(); assets.reload(); runs.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    return (
        <div className="space-y-4">
            <Banner error={error} success={ok} />
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <form className="card grid grid-cols-2 gap-2 p-4" onSubmit={(e) => { e.preventDefault(); post('accounting/asset-classes', cls, t('fin.assets.class_saved')); }}>
                    <h4 className="col-span-2 font-semibold">{t('fin.assets.class')}</h4>
                    <Field label={t('fin.col.code')}><input className="input w-full" required value={cls.code} onChange={(e) => setCls({ ...cls, code: e.target.value })} /></Field>
                    <Field label={t('fin.col.name')}><input className="input w-full" required value={cls.name} onChange={(e) => setCls({ ...cls, name: e.target.value })} /></Field>
                    <Field label={t('fin.assets.asset_account')}><input className="input w-full" value={cls.asset_account} onChange={(e) => setCls({ ...cls, asset_account: e.target.value })} /></Field>
                    <Field label={t('fin.assets.depreciation_account')}><input className="input w-full" value={cls.depreciation_account} onChange={(e) => setCls({ ...cls, depreciation_account: e.target.value })} /></Field>
                    <Field label={t('fin.assets.method')}>
                        <select className="input w-full" value={cls.method} onChange={(e) => setCls({ ...cls, method: e.target.value })}>
                            <option value="straight_line">{t('fin.assets.straight_line')}</option><option value="declining_balance">{t('fin.assets.declining_balance')}</option>
                        </select>
                    </Field>
                    <Field label={t('fin.assets.life_months')}><input type="number" className="input w-full" value={cls.useful_life_months} onChange={(e) => setCls({ ...cls, useful_life_months: Number(e.target.value) })} /></Field>
                    <button className="btn-secondary col-span-2" disabled={busy}>{t('fin.assets.save_class')}</button>
                </form>
                <form className="card grid grid-cols-2 gap-2 p-4" onSubmit={(e) => {
                    e.preventDefault();
                    const body: Record<string, unknown> = { asset_class_id: Number(acq.asset_class_id) };
                    if (acq.supplier_invoice_id) body.supplier_invoice_id = Number(acq.supplier_invoice_id);
                    else Object.assign(body, { name: acq.name, acquired_on: acq.acquired_on, cost: Number(acq.cost) });
                    post('accounting/fixed-assets', body, t('fin.assets.registered'));
                }}>
                    <h4 className="col-span-2 font-semibold">{t('fin.assets.acquire')}</h4>
                    <Field label={t('fin.col.class')}>
                        <select className="input w-full" required value={acq.asset_class_id} onChange={(e) => setAcq({ ...acq, asset_class_id: e.target.value })}>
                            <option value="">{t('fin.choose')}</option>
                            {classes.data?.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('fin.assets.supplier_invoice')}><input className="input w-full" value={acq.supplier_invoice_id} onChange={(e) => setAcq({ ...acq, supplier_invoice_id: e.target.value })} /></Field>
                    <Field label={t('fin.col.name')}><input className="input w-full" value={acq.name} onChange={(e) => setAcq({ ...acq, name: e.target.value })} /></Field>
                    <Field label={t('fin.assets.acquired_on')}><input type="date" className="input w-full" value={acq.acquired_on} onChange={(e) => setAcq({ ...acq, acquired_on: e.target.value })} /></Field>
                    <Field label={t('fin.assets.cost_fcfa')}><input type="number" className="input w-full" value={acq.cost} onChange={(e) => setAcq({ ...acq, cost: e.target.value })} /></Field>
                    <button className="btn-primary self-end" disabled={busy}>{t('fin.assets.register')}</button>
                </form>
            </div>

            <form className="card grid grid-cols-2 gap-2 p-4 sm:flex sm:items-end" onSubmit={(e) => { e.preventDefault(); post('accounting/depreciation-runs', period, t('fin.assets.depreciation_posted', { period: `${period.month}/${period.year}` })); }}>
                <Field label={t('fin.periods.year')}><input type="number" className="input w-full sm:w-28" value={period.year} onChange={(e) => setPeriod({ ...period, year: Number(e.target.value) })} /></Field>
                <Field label={t('fin.periods.month')}><input type="number" min={1} max={12} className="input w-full sm:w-20" value={period.month} onChange={(e) => setPeriod({ ...period, month: Number(e.target.value) })} /></Field>
                <button className="btn-primary col-span-2" disabled={busy}>{t('fin.assets.run_depreciation')}</button>
                <p className="col-span-2 text-xs text-slate-500">{t('fin.assets.last_runs')} {runs.data?.slice(0, 3).map((r) => `${r.month}/${r.year} ${formatCurrency(r.total)}`).join(' · ') || t('fin.none')}</p>
            </form>

            {dispose && (
                <form className="card grid grid-cols-2 gap-2 p-4 sm:flex sm:items-end" onSubmit={(e) => { e.preventDefault(); post(`accounting/fixed-assets/${dispose.id}/dispose`, { date: disp.date, proceeds: Number(disp.proceeds) }, t('fin.assets.disposed')); }}>
                    <p className="col-span-2 text-sm">{t('fin.assets.dispose_title', { code: dispose.code, value: formatCurrency(dispose.net_book_value) })}</p>
                    <Field label={t('fin.col.date')}><input type="date" className="input w-full" value={disp.date} onChange={(e) => setDisp({ ...disp, date: e.target.value })} /></Field>
                    <Field label={t('fin.assets.proceeds_fcfa')}><input type="number" className="input w-full" value={disp.proceeds} onChange={(e) => setDisp({ ...disp, proceeds: e.target.value })} /></Field>
                    <button className="btn-primary" disabled={busy}>{t('fin.assets.dispose')}</button>
                    <button type="button" className="btn-secondary" onClick={() => setDispose(null)}>{t('common.cancel')}</button>
                </form>
            )}

            <div className="card overflow-x-auto">
                <Loading on={assets.loading} />
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.assets.asset')}</th><th className="p-2">{t('fin.assets.acquired')}</th><th className="p-2 text-right">{t('fin.assets.cost')}</th><th className="p-2 text-right">{t('fin.assets.book_value')}</th><th className="p-2">{t('fin.col.status')}</th><th /></tr></thead>
                    <tbody>
                        {assets.data?.map((a) => (
                            <tr key={a.id} className="border-t border-slate-100">
                                <td className="p-2">{a.name}<span className="block text-xs text-slate-500">{a.code} · {a.asset_account} · {t(`fin.assets.${a.method}`)}</span></td>
                                <td className="p-2 whitespace-nowrap">{formatDate(a.acquired_on)}</td>
                                <td className="p-2 text-right whitespace-nowrap">{formatCurrency(a.cost)}</td>
                                <td className="p-2 text-right whitespace-nowrap">{formatCurrency(a.net_book_value)}</td>
                                <td className="p-2"><Badge s={a.status} />{a.disposal_result !== null && <span className="block text-xs text-slate-500">{t('fin.assets.result', { amount: formatCurrency(a.disposal_result) })}</span>}</td>
                                <td className="p-2 text-right">{a.status === 'active' && <button className="btn-secondary text-xs" onClick={() => setDispose(a)}>{t('fin.assets.dispose')}</button>}</td>
                            </tr>
                        ))}
                        {assets.data?.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-slate-400">{t('fin.assets.empty')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
