import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { fcfa, Kpis, severityClass } from './shared';

/** Mobile-first owner page: one API call, four tiles, takings, top alerts. */
export default function Owner() {
    const t = useT();
    const perms = (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
    const [date, setDate] = useState('');
    const q = useApi<{ today: Kpis; yesterday: Kpis }>(`insights/owner${date ? `?date=${date}` : ''}`, [date]);
    const k = q.data?.today;
    const y = q.data?.yesterday;

    const tile = (label: string, value: string, prev?: string, sub?: string) => (
        <div className="card p-3">
            <div className="text-xs text-slate-500">{label}</div>
            <div className="text-lg font-semibold text-slate-900">{value}</div>
            {sub && <div className="text-xs text-slate-500">{sub}</div>}
            {prev && <div className="text-xs text-slate-500">{t('insights.owner.vs_yesterday', { value: prev })}</div>}
        </div>
    );

    return (
        <AppLayout title={t('insights.owner.title')} subtitle={t('insights.owner.subtitle')}>
            <Head title={t('insights.owner.title')} />
            <div className="mx-auto max-w-xl space-y-3">
                <div className="flex items-end gap-2">
                    <label className="flex-1">
                        <span className="label">{t('insights.owner.date')}</span>
                        <input type="date" className="input w-full" value={date || k?.date || ''} onChange={(e) => setDate(e.target.value)} />
                    </label>
                    {perms.includes('insights.group') && <Link href="/owner/group" className="btn-secondary">{t('insights.owner.group')}</Link>}
                </div>
                {q.error && <p className="text-sm text-red-700">{q.error.message}</p>}
                {q.loading && !k && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}
                {k && (
                    <>
                        <div className="grid grid-cols-2 gap-2">
                            {tile(t('insights.owner.revenue'), fcfa(k.revenue), y && fcfa(y.revenue))}
                            {tile(t('insights.owner.occupancy'), `${k.occupancy} %`, y && `${y.occupancy} %`, t('insights.owner.rooms_sold', { occupied: k.occupied, rooms: k.rooms }))}
                            {tile(t('insights.owner.adr'), fcfa(k.adr), y && fcfa(y.adr))}
                            {tile(t('insights.owner.revpar'), fcfa(k.revpar), y && fcfa(y.revpar))}
                        </div>
                        <div className="card p-3">
                            <h2 className="mb-2 font-semibold text-slate-900">{t('insights.owner.cash')}</h2>
                            {k.cash_by_method.length === 0 && <p className="text-sm text-slate-500">{t('insights.owner.no_cash')}</p>}
                            <ul className="text-sm">
                                {k.cash_by_method.map((c) => (
                                    <li key={c.method} className="flex justify-between border-b border-slate-100 py-1 last:border-0">
                                        <span>{c.method}</span><span className="font-medium">{fcfa(c.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="card p-3">
                            <div className="mb-2 flex items-center justify-between">
                                <h2 className="font-semibold text-slate-900">{t('insights.owner.top_alerts')} ({k.open_alerts})</h2>
                                <Link href="/insights/alerts" className="hit-44 text-sm text-blue-700">{t('insights.owner.all_alerts')}</Link>
                            </div>
                            {k.top_alerts.length === 0 && <p className="text-sm text-slate-500">{t('insights.owner.no_alerts')}</p>}
                            <ul className="space-y-2">
                                {k.top_alerts.map((a) => (
                                    <li key={a.id} className={`rounded border p-2 text-sm ${severityClass[a.severity]}`}>
                                        <div className="font-medium">{a.title}</div>
                                        <div className="text-xs">{t(`insights.rule.${a.rule}`)}{a.amount ? ` · ${fcfa(a.amount)}` : ''}</div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
