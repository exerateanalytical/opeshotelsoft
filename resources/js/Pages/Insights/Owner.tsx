import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import Modal from '@/Components/ui/Modal';
import Tabs from '@/Components/ui/Tabs';
import EmptyState from '@/Components/ui/EmptyState';
import { fcfa, Kpis, severityClass, type AlertRow } from './shared';

interface DigestRow { id: number; date: string; messages: number | null; sent_at: string | null }

/** Mobile-first owner page: one API call, four tiles, takings, top alerts. */
export default function Owner() {
    const t = useT();
    const perms = (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
    const [date, setDate] = useState('');
    const q = useApi<{ today: Kpis; yesterday: Kpis }>(`insights/owner${date ? `?date=${date}` : ''}`, [date]);
    const [tab, setTab] = useState('today');
    const [alertId, setAlertId] = useState<number | null>(null);
    const digests = useApi<DigestRow[]>(tab === 'digests' ? 'insights/digests' : null, [tab]);
    const alertQ = useApi<AlertRow & { hotel?: { name: string } }>(alertId ? `insights/alerts/${alertId}` : null, [alertId]);
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
            <div className="space-y-3 lg:space-y-4">
                <div className="flex items-end gap-2">
                    <label className="flex-1 lg:flex-none lg:w-64">
                        <span className="label">{t('insights.owner.date')}</span>
                        <input type="date" className="input w-full" value={date || k?.date || ''} onChange={(e) => setDate(e.target.value)} />
                    </label>
                    {perms.includes('insights.group') && <Link href="/owner/group" className="btn-secondary">{t('insights.owner.group')}</Link>}
                </div>
                <Tabs label={t('gapf.owner.tabs')} value={tab} onChange={setTab} tabs={[{ key: 'today', label: t('gapf.owner.tab_today') }, { key: 'digests', label: t('gapf.owner.tab_digests') }]} />
                {tab === 'digests' && (
                    <div role="tabpanel" id="panel-digests" className="card p-3">
                        {digests.error && <p className="text-sm text-red-700">{digests.error.message}</p>}
                        {digests.data && digests.data.length === 0 && <EmptyState title={t('gapf.owner.no_digests')} />}
                        {digests.data && digests.data.length > 0 && (
                            <div className="table-wrap">
                                <table className="table-std table-cards">
                                    <caption className="sr-only">{t('gapf.owner.tab_digests')}</caption>
                                    <thead><tr><th>{t('gapf.owner.digest_date')}</th><th className="num">{t('gapf.owner.digest_messages')}</th><th>{t('gapf.owner.digest_sent')}</th></tr></thead>
                                    <tbody>
                                        {digests.data.map((d) => (
                                            <tr key={d.id}>
                                                <td data-label={t('gapf.owner.digest_date')}>{d.date?.slice(0, 10)}</td>
                                                <td data-label={t('gapf.owner.digest_messages')} className="num">{d.messages ?? 0}</td>
                                                <td data-label={t('gapf.owner.digest_sent')}>{d.sent_at ? new Date(d.sent_at).toLocaleString() : t('gapf.owner.not_sent')}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
                {tab === 'today' && q.error && <p className="text-sm text-red-700">{q.error.message}</p>}
                {tab === 'today' && q.loading && !k && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}
                {tab === 'today' && k && (
                    <>
                        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4 lg:gap-4">
                            {tile(t('insights.owner.revenue'), fcfa(k.revenue), y && fcfa(y.revenue))}
                            {tile(t('insights.owner.occupancy'), `${k.occupancy} %`, y && `${y.occupancy} %`, t('insights.owner.rooms_sold', { occupied: k.occupied, rooms: k.rooms }))}
                            {tile(t('insights.owner.adr'), fcfa(k.adr), y && fcfa(y.adr))}
                            {tile(t('insights.owner.revpar'), fcfa(k.revpar), y && fcfa(y.revpar))}
                        </div>
                        <div className="grid gap-3 lg:grid-cols-2 lg:gap-4 lg:items-start">
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
                                    <li key={a.id}>
                                        <button type="button" onClick={() => setAlertId(a.id)} className={`w-full rounded border p-2 text-left text-sm ${severityClass[a.severity]}`}>
                                            <div className="font-medium">{a.title}</div>
                                            <div className="text-xs">{t(`insights.rule.${a.rule}`)}{a.amount ? ` · ${fcfa(a.amount)}` : ''}</div>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        </div>
                    </>
                )}
            </div>
            <Modal open={alertId !== null} onClose={() => setAlertId(null)} title={alertQ.data?.title ?? t('gapf.owner.alert_detail')}>
                {alertQ.error && <p className="text-sm text-red-700">{alertQ.error.message}</p>}
                {alertQ.data && (
                    <dl className="space-y-2 text-sm">
                        <div><dt className="text-slate-500">{t('gapf.owner.alert_rule')}</dt><dd>{t(`insights.rule.${alertQ.data.rule}`)}</dd></div>
                        <div><dt className="text-slate-500">{t('gapf.owner.alert_severity')}</dt><dd>{alertQ.data.severity}{alertQ.data.status ? ` · ${alertQ.data.status}` : ''}</dd></div>
                        {alertQ.data.amount ? <div><dt className="text-slate-500">{t('gapf.owner.alert_amount')}</dt><dd>{fcfa(alertQ.data.amount)}</dd></div> : null}
                        <div><dt className="text-slate-500">{t('gapf.owner.alert_when')}</dt><dd>{alertQ.data.occurred_at ? new Date(alertQ.data.occurred_at).toLocaleString() : ''}</dd></div>
                        {alertQ.data.detail && <div><dt className="text-slate-500">{t('gapf.owner.alert_detail')}</dt><dd className="whitespace-pre-wrap">{alertQ.data.detail}</dd></div>}
                        {alertQ.data.evidence?.map((e, i) => <div key={i}><dt className="text-slate-500">{e.label}</dt><dd>{e.url ? <a className="text-blue-700 underline" href={e.url}>{e.type} #{e.id}</a> : `${e.type} #${e.id}`}</dd></div>)}
                    </dl>
                )}
            </Modal>
        </AppLayout>
    );
}
