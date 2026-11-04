import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import Alert from '@/Components/ui/Alert';
import { ContractActions, QuoteTool } from '@/Components/gape/RevenueTools';

interface Promo { id: number; code: string; name: string; type: 'percent' | 'amount'; value: number; uses: number; max_uses: number | null; active: boolean }
interface Contract { id: number; code: string; name: string; valid_from: string; valid_to: string; last_room_availability: boolean; active: boolean; notes?: string | null; company?: { name: string }; rate_plan?: { code: string; derived_adjust_percent: number }; allotments: { id: number; rooms: number; start_date: string; end_date: string; room_type_id: number }[] }

export default function RevenueOffers() {
    const t = useT();
    const can = useCan();
    const promos = useApi<{ data: Promo[] }>('revenue/promo-codes');
    const contracts = useApi<{ data: Contract[] }>('revenue/contracts');
    const companies = useApi<{ data: { id: number; name: string }[] }>('companies?per_page=200');
    const plans = useApi<{ data: { id: number; code: string; name: string; parent_rate_plan_id: number | null }[] }>('revenue/rate-plans');
    const types = useApi<{ data: { id: number; name: string }[] }>('room-types?per_page=100');
    const [msg, setMsg] = useState<string | null>(null);
    const [p, setP] = useState({ code: '', name: '', type: 'percent', value: '10', max_uses: '' });
    const [c, setC] = useState({ company_id: '', code: '', name: '', parent_rate_plan_id: '', discount_percent: '15', valid_from: '', valid_to: '', last_room_availability: false });
    const [al, setAl] = useState({ contract: 0, room_type_id: '', rooms: '1', start_date: '', end_date: '', release_days: '7' });

    const run = async (fn: () => Promise<unknown>, done?: () => void) => {
        setMsg(null);
        try { await fn(); done?.(); } catch (e) { setMsg(e instanceof ApiError ? e.message : String(e)); }
    };

    return (
        <AppLayout title={t('revenue.offers.title')}>
            <Head title={t('revenue.offers.title')} />
            <div className="mx-auto max-w-5xl space-y-4">
                {msg && <Alert variant="danger">{msg}</Alert>}

                <QuoteTool types={types.data?.data ?? []} plans={(plans.data?.data ?? []).map((x) => ({ id: x.id, name: x.name, code: x.code }))} />

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.offers.promos')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        <input className="input" aria-label={t('revenue.common.code')} placeholder={t('revenue.common.code')} value={p.code} onChange={(e) => setP({ ...p, code: e.target.value })} />
                        <input className="input" aria-label={t('revenue.common.name')} placeholder={t('revenue.common.name')} value={p.name} onChange={(e) => setP({ ...p, name: e.target.value })} />
                        <select className="select" value={p.type} onChange={(e) => setP({ ...p, type: e.target.value })} aria-label={t('revenue.offers.percent')}>
                            <option value="percent">{t('revenue.offers.percent')}</option>
                            <option value="amount">{t('revenue.offers.amount')}</option>
                        </select>
                        <input className="input" type="number" title={t('revenue.offers.value')} value={p.value} onChange={(e) => setP({ ...p, value: e.target.value })} />
                        <input className="input" type="number" aria-label={t('revenue.offers.max_uses')} placeholder={t('revenue.offers.max_uses')} value={p.max_uses} onChange={(e) => setP({ ...p, max_uses: e.target.value })} />
                        <button type="button" className="btn-primary" disabled={!p.code || !p.name} onClick={() => run(() => api('revenue/promo-codes', { method: 'POST', body: JSON.stringify({ ...p, value: +p.value, max_uses: p.max_uses ? +p.max_uses : null }) }), () => { setP({ code: '', name: '', type: 'percent', value: '10', max_uses: '' }); promos.reload(); })}>{t('revenue.common.add')}</button>
                    </div>
                    <QueryStatus query={promos} variant="list" rows={2} />
                    {promos.data?.data.length === 0 && <EmptyState title={t('states3.empty.promos.title')} description={t('states3.empty.promos.text')} />}
                    {promos.data?.data.map((x) => (
                        <div key={x.id} className="flex items-center justify-between rounded-md border border-slate-200 p-2 text-sm">
                            <span><strong>{x.code}</strong> · {x.name} · {x.type === 'percent' ? `${x.value}%` : formatCurrency(x.value)} · {t('revenue.offers.uses')} {x.uses}{x.max_uses ? `/${x.max_uses}` : ''}</span>
                            {can('revenue.manage') && <button type="button" className="btn-secondary" onClick={() => run(() => api(`revenue/promo-codes/${x.id}`, { method: 'PATCH', body: JSON.stringify({ name: x.name, type: x.type, value: x.value, active: !x.active }) }), promos.reload)}>{x.active ? t('revenue.common.reject') : t('revenue.common.accept')}</button>}
                        </div>
                    ))}
                </section>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.offers.contracts')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <select className="select" value={c.company_id} onChange={(e) => setC({ ...c, company_id: e.target.value })} aria-label={t('revenue.offers.company')}>
                            <option value="">{t('revenue.offers.company')}</option>
                            {companies.data?.data.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </select>
                        <input className="input" aria-label={t('revenue.common.code')} placeholder={t('revenue.common.code')} value={c.code} onChange={(e) => setC({ ...c, code: e.target.value })} />
                        <input className="input" aria-label={t('revenue.common.name')} placeholder={t('revenue.common.name')} value={c.name} onChange={(e) => setC({ ...c, name: e.target.value })} />
                        <select className="select" value={c.parent_rate_plan_id} onChange={(e) => setC({ ...c, parent_rate_plan_id: e.target.value })} aria-label={t('revenue.offers.parent')}>
                            <option value="">{t('revenue.offers.parent')}</option>
                            {plans.data?.data.filter((x) => !x.parent_rate_plan_id).map((x) => <option key={x.id} value={x.id}>{x.code}</option>)}
                        </select>
                        <label className="label">{t('revenue.offers.discount')}<input className="input w-full" type="number" value={c.discount_percent} onChange={(e) => setC({ ...c, discount_percent: e.target.value })} /></label>
                        <label className="label">{t('revenue.offers.valid_from')}<input className="input w-full" type="date" value={c.valid_from} onChange={(e) => setC({ ...c, valid_from: e.target.value })} /></label>
                        <label className="label">{t('revenue.offers.valid_to')}<input className="input w-full" type="date" min={c.valid_from} value={c.valid_to} onChange={(e) => setC({ ...c, valid_to: e.target.value })} /></label>
                        <label className="min-h-11 flex items-end gap-2 text-sm"><input type="checkbox" checked={c.last_room_availability} onChange={(e) => setC({ ...c, last_room_availability: e.target.checked })} />{t('revenue.offers.lra')}</label>
                    </div>
                    <button type="button" className="btn-primary" disabled={!c.company_id || !c.code || !c.name || !c.parent_rate_plan_id || !c.valid_from || !c.valid_to}
                        onClick={() => run(() => api('revenue/contracts', { method: 'POST', body: JSON.stringify({ ...c, company_id: +c.company_id, parent_rate_plan_id: +c.parent_rate_plan_id, discount_percent: +c.discount_percent }) }), contracts.reload)}>{t('revenue.common.add')}</button>

                    <QueryStatus query={contracts} variant="list" rows={2} />
                    {contracts.data?.data.length === 0 && <EmptyState title={t('states3.empty.contracts.title')} description={t('states3.empty.contracts.text')} />}
                    {contracts.data?.data.map((x) => (
                        <div key={x.id} className="space-y-2 rounded-md border border-slate-200 p-3 text-sm">
                            <div className="font-medium">{x.code} · {x.name} · {x.company?.name} · {x.rate_plan?.code} ({x.rate_plan?.derived_adjust_percent}%)</div>
                            <div className="text-slate-600">{formatDate(x.valid_from)} → {formatDate(x.valid_to)}{x.last_room_availability ? ` · ${t('revenue.offers.lra')}` : ''}</div>
                            <ContractActions c={x} types={types.data?.data ?? []} onSaved={contracts.reload} />
                            {x.allotments.map((a) => <div key={a.id}>{a.rooms} × {types.data?.data.find((y) => y.id === a.room_type_id)?.name} · {formatDate(a.start_date)} → {formatDate(a.end_date)}</div>)}
                            {al.contract === x.id ? (
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                    <select className="select" value={al.room_type_id} onChange={(e) => setAl({ ...al, room_type_id: e.target.value })} aria-label={t('revenue.common.room_type')}>
                                        <option value="">{t('revenue.common.room_type')}</option>
                                        {types.data?.data.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
                                    </select>
                                    <input className="input" type="number" title={t('revenue.offers.rooms')} value={al.rooms} onChange={(e) => setAl({ ...al, rooms: e.target.value })} />
                                    <input className="input" type="date" value={al.start_date} onChange={(e) => setAl({ ...al, start_date: e.target.value })} />
                                    <input className="input" type="date" min={al.start_date} value={al.end_date} onChange={(e) => setAl({ ...al, end_date: e.target.value })} />
                                    <input className="input" type="number" title={t('revenue.offers.release_days')} value={al.release_days} onChange={(e) => setAl({ ...al, release_days: e.target.value })} />
                                    <button type="button" className="btn-primary" disabled={!al.room_type_id || !al.start_date || !al.end_date} onClick={() => run(() => api(`revenue/contracts/${x.id}/allotments`, { method: 'POST', body: JSON.stringify({ room_type_id: +al.room_type_id, rooms: +al.rooms, start_date: al.start_date, end_date: al.end_date, release_days: +al.release_days }) }), () => { setAl({ ...al, contract: 0 }); contracts.reload(); })}>{t('revenue.common.save')}</button>
                                </div>
                            ) : <button type="button" className="btn-secondary" onClick={() => setAl({ ...al, contract: x.id })}>{t('revenue.offers.allotment')}</button>}
                        </div>
                    ))}
                    <button type="button" className="btn-secondary" onClick={() => run(() => api('revenue/allotments/release', { method: 'POST', body: '{}' }), contracts.reload)}>{t('revenue.offers.release_now')}</button>
                </section>
            </div>
        </AppLayout>
    );
}
