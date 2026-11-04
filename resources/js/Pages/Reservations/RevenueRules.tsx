import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Plan { id: number; code: string; name: string; parent_rate_plan_id: number | null }
interface Detail {
    rule: { min_los: number | null; max_los: number | null; advance_min_days: number | null; advance_max_days: number | null; closed_arrival_days: number[] | null; derived_adjust_amount: number } | null;
    package: { name: string; pricing: 'per_room' | 'per_person'; room_amount: number; adult_amount: number; child_amount: number; inclusions: { name: string }[] | null } | null;
}

const toNum = (v: string) => (v === '' ? null : +v);

export default function RevenueRules() {
    const t = useT();
    const plans = useApi<{ data: Plan[] }>('revenue/rate-plans');
    const [planId, setPlanId] = useState<number | null>(null);
    useEffect(() => { if (!planId && plans.data?.data.length) setPlanId(plans.data.data[0].id); }, [plans.data]);
    const detail = useApi<Detail>(planId ? `revenue/rate-plans/${planId}` : null);
    const [rule, setRule] = useState({ min_los: '', max_los: '', advance_min_days: '', advance_max_days: '', days: [] as number[], derived: '0' });
    const [pkg, setPkg] = useState({ name: '', pricing: 'per_room', room_amount: '0', adult_amount: '0', child_amount: '0', inclusions: '' });
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

    useEffect(() => {
        const r = detail.data?.rule, p = detail.data?.package;
        setRule({ min_los: String(r?.min_los ?? ''), max_los: String(r?.max_los ?? ''), advance_min_days: String(r?.advance_min_days ?? ''), advance_max_days: String(r?.advance_max_days ?? ''), days: r?.closed_arrival_days ?? [], derived: String(r?.derived_adjust_amount ?? 0) });
        setPkg({ name: p?.name ?? '', pricing: p?.pricing ?? 'per_room', room_amount: String(p?.room_amount ?? 0), adult_amount: String(p?.adult_amount ?? 0), child_amount: String(p?.child_amount ?? 0), inclusions: (p?.inclusions ?? []).map((i) => i.name).join('\n') });
    }, [detail.data]);

    const run = async (fn: () => Promise<unknown>) => {
        try { await fn(); setMsg({ ok: true, text: t('revenue.common.saved') }); detail.reload(); } catch (e) { setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) }); }
    };
    const plan = plans.data?.data.find((p) => p.id === planId);

    return (
        <AppLayout title={t('revenue.rules.title')}>
            <Head title={t('revenue.rules.title')} />
            <div className="mx-auto max-w-4xl space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <select className="select sm:w-72" aria-label={t('res.rate_plan')} value={planId ?? ''} onChange={(e) => setPlanId(+e.target.value)}>
                        {plans.data?.data.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                    </select>
                </div>
                <QueryStatus query={plans} variant="form" rows={2} />
                {plans.data?.data.length === 0 && <div className="card"><EmptyState title={t('states3.empty.rate_plans.title')} description={t('states3.empty.rate_plans.text')} action={<a href="/properties" className="btn-secondary">{t('states3.empty.outlets.action')}</a>} /></div>}
                <QueryStatus query={detail} variant="form" rows={2} />
                {msg && <div className={`rounded-md border p-3 text-sm ${msg.ok ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-red-300 bg-red-50 text-red-800'}`}>{msg.text}</div>}

                <section className="card space-y-3 p-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label className="label">{t('revenue.rules.min_los')}<input className="input w-full" type="number" value={rule.min_los} onChange={(e) => setRule({ ...rule, min_los: e.target.value })} /></label>
                        <label className="label">{t('revenue.rules.max_los')}<input className="input w-full" type="number" value={rule.max_los} onChange={(e) => setRule({ ...rule, max_los: e.target.value })} /></label>
                        <label className="label">{t('revenue.rules.adv_min')}<input className="input w-full" type="number" value={rule.advance_min_days} onChange={(e) => setRule({ ...rule, advance_min_days: e.target.value })} /></label>
                        <label className="label">{t('revenue.rules.adv_max')}<input className="input w-full" type="number" value={rule.advance_max_days} onChange={(e) => setRule({ ...rule, advance_max_days: e.target.value })} /></label>
                    </div>
                    <div className="label">{t('revenue.rules.closed_days')}
                        <div className="flex flex-wrap gap-3">
                            {[1, 2, 3, 4, 5, 6, 0].map((d) => (
                                <label key={d} className="flex items-center gap-1 text-sm">
                                    <input type="checkbox" checked={rule.days.includes(d)} onChange={(e) => setRule({ ...rule, days: e.target.checked ? [...rule.days, d] : rule.days.filter((x) => x !== d) })} />{t(`revenue.day.${d}`)}
                                </label>
                            ))}
                        </div>
                    </div>
                    {plan?.parent_rate_plan_id && (
                        <label className="label">{t('revenue.rules.derived_amount')}<input className="input w-full" type="number" value={rule.derived} onChange={(e) => setRule({ ...rule, derived: e.target.value })} />
                            <span className="text-xs text-slate-500">{t('revenue.rules.derived_note')}</span></label>
                    )}
                    <button type="button" className="btn-primary" onClick={() => run(() => api(`revenue/rate-plans/${planId}/rules`, { method: 'PUT', body: JSON.stringify({ min_los: toNum(rule.min_los), max_los: toNum(rule.max_los), advance_min_days: toNum(rule.advance_min_days), advance_max_days: toNum(rule.advance_max_days), closed_arrival_days: rule.days, derived_adjust_amount: +rule.derived || 0 }) }))}>{t('revenue.common.save')}</button>
                </section>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{t('revenue.rules.package')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label className="label">{t('revenue.rules.pkg_name')}<input className="input w-full" value={pkg.name} onChange={(e) => setPkg({ ...pkg, name: e.target.value })} /></label>
                        <label className="label">{t('revenue.rules.pricing')}
                            <select className="input w-full" value={pkg.pricing} onChange={(e) => setPkg({ ...pkg, pricing: e.target.value })}>
                                <option value="per_room">{t('revenue.rules.per_room')}</option>
                                <option value="per_person">{t('revenue.rules.per_person')}</option>
                            </select>
                        </label>
                        {pkg.pricing === 'per_room' ? (
                            <label className="label">{t('revenue.rules.room_amount')}<input className="input w-full" type="number" value={pkg.room_amount} onChange={(e) => setPkg({ ...pkg, room_amount: e.target.value })} /></label>
                        ) : (
                            <>
                                <label className="label">{t('revenue.rules.adult_amount')}<input className="input w-full" type="number" value={pkg.adult_amount} onChange={(e) => setPkg({ ...pkg, adult_amount: e.target.value })} /></label>
                                <label className="label">{t('revenue.rules.child_amount')}<input className="input w-full" type="number" value={pkg.child_amount} onChange={(e) => setPkg({ ...pkg, child_amount: e.target.value })} /></label>
                            </>
                        )}
                    </div>
                    <label className="label">{t('revenue.rules.inclusions')}<textarea className="input w-full" rows={3} value={pkg.inclusions} onChange={(e) => setPkg({ ...pkg, inclusions: e.target.value })} /></label>
                    <div className="flex gap-2">
                        <button type="button" className="btn-primary" disabled={!pkg.name} onClick={() => run(() => api(`revenue/rate-plans/${planId}/package`, { method: 'PUT', body: JSON.stringify({ name: pkg.name, pricing: pkg.pricing, room_amount: +pkg.room_amount || 0, adult_amount: +pkg.adult_amount || 0, child_amount: +pkg.child_amount || 0, inclusions: pkg.inclusions.split('\n').map((s) => s.trim()).filter(Boolean).map((name) => ({ name })) }) }))}>{t('revenue.common.save')}</button>
                        {detail.data?.package && <button type="button" className="btn-secondary" onClick={() => run(() => api(`revenue/rate-plans/${planId}/package`, { method: 'DELETE' }))}>{t('revenue.rules.remove_pkg')}</button>}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
