import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { AlertRow, fcfa, Kpis, severityClass } from './shared';

interface GroupData {
    hotels: (Kpis & { code: string; name: string })[];
    totals: { revenue: number; rooms: number; occupied: number; occupancy: number; adr: number; open_alerts: number };
    top_alerts: AlertRow[];
}

export default function Group() {
    const t = useT();
    const [date, setDate] = useState('');
    const q = useApi<GroupData>(`insights/group${date ? `?date=${date}` : ''}`, [date]);
    const g = q.data;
    const names = Object.fromEntries((g?.hotels ?? []).map((h) => [h.hotel_id, h.code]));

    return (
        <AppLayout title={t('insights.group.title')} subtitle={t('insights.group.subtitle')}>
            <Head title={t('insights.group.title')} />
            <div className="mx-auto max-w-3xl space-y-3">
                <label className="block max-w-xs">
                    <span className="label">{t('insights.owner.date')}</span>
                    <input type="date" className="input w-full" value={date} onChange={(e) => setDate(e.target.value)} />
                </label>
                {q.error && <p className="text-sm text-red-700">{q.error.message}</p>}
                {q.loading && !g && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}
                {g && (
                    <>
                        <div className="card overflow-x-auto">
                            <table className="table-auto w-full text-sm">
                                <thead>
                                    <tr className="text-left text-slate-500">
                                        <th className="p-2">{t('insights.group.hotel')}</th>
                                        <th className="p-2 text-right">{t('insights.owner.revenue')}</th>
                                        <th className="p-2 text-right">{t('insights.owner.occupancy')}</th>
                                        <th className="p-2 text-right">{t('insights.owner.adr')}</th>
                                        <th className="p-2 text-right">{t('insights.group.alerts')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {g.hotels.map((h) => (
                                        <tr key={h.hotel_id} className="border-t border-slate-100">
                                            <td className="p-2">{h.name}</td>
                                            <td className="p-2 text-right whitespace-nowrap">{fcfa(h.revenue)}</td>
                                            <td className="p-2 text-right">{h.occupancy} %</td>
                                            <td className="p-2 text-right whitespace-nowrap">{fcfa(h.adr)}</td>
                                            <td className="p-2 text-right">{h.open_alerts}</td>
                                        </tr>
                                    ))}
                                    <tr className="border-t border-slate-300 font-semibold">
                                        <td className="p-2">{t('insights.group.total')}</td>
                                        <td className="p-2 text-right whitespace-nowrap">{fcfa(g.totals.revenue)}</td>
                                        <td className="p-2 text-right">{g.totals.occupancy} %</td>
                                        <td className="p-2 text-right whitespace-nowrap">{fcfa(g.totals.adr)}</td>
                                        <td className="p-2 text-right">{g.totals.open_alerts}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div className="card p-3">
                            <h2 className="mb-2 font-semibold text-slate-900">{t('insights.owner.top_alerts')}</h2>
                            {g.top_alerts.length === 0 && <p className="text-sm text-slate-500">{t('insights.owner.no_alerts')}</p>}
                            <ul className="space-y-2">
                                {g.top_alerts.map((a) => (
                                    <li key={a.id} className={`rounded border p-2 text-sm ${severityClass[a.severity]}`}>
                                        <span className="font-medium">{names[a.hotel_id]}</span> · {a.title}{a.amount ? ` · ${fcfa(a.amount)}` : ''}
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
