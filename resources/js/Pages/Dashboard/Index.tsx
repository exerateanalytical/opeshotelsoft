import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    DoorOpen, DoorClosed, BedDouble, TrendingUp, Wallet, Wrench, Sparkles, Building2, Plus, AlertTriangle, LayoutDashboard,
} from 'lucide-react';
import { AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';
import { cn, formatCurrency } from '@/lib/utils';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Kpis {
    business_date: string;
    rooms_total: number;
    out_of_order: number;
    rooms_available: number;
    rooms_sold: number;
    occupancy_percent: number;
    room_revenue: number;
    total_revenue: number;
    adr: number;
    revpar: number;
    arrivals: number;
    departures: number;
    dirty_rooms: number;
    open_work_orders: number;
    guest_ledger: number;
    city_ledger: number;
}

interface ForecastDay { date: string; rooms_sold: number; occupancy_percent: number; room_revenue: number; adr: number }

interface Reservation {
    id: number;
    number: string;
    arrival: string;
    departure: string;
    status: string;
    guest: { first_name: string; last_name: string; vip: boolean };
    room: { number: string } | null;
    room_type: { code: string } | null;
}

interface Board { business_date: string; arrivals: Reservation[]; departures: Reservation[] }

interface Props {
    property: { id: number; name: string; currency: string } | null;
    auth: { user: { name: string; permissions: string[] } | null };
    [key: string]: unknown;
}

export default function Dashboard() {
    const { property, auth } = usePage<Props>().props;
    const t = useT();
    const perms = auth.user?.permissions ?? [];
    const can = (...p: string[]) => p.some((x) => perms.includes(x));

    // Each widget only loads and shows when the role may see its data (the API enforces the same rules).
    const seeKpis = can('reports.view', 'frontdesk.view');
    const seeRevenue = can('reports.view');
    const seeBoard = can('frontdesk.view');
    const seeForecast = can('reports.view');
    const canBook = can('reservations.manage');

    const hotelKey = property?.id ?? 0;
    const dash = useApi<{ today: Kpis }>(property && seeKpis ? 'reports/dashboard' : null, [hotelKey]);
    const forecast = useApi<ForecastDay[]>(property && seeForecast ? 'reports/forecast?days=14' : null, [hotelKey]);
    const board = useApi<Board>(property && seeBoard ? 'front-desk' : null, [hotelKey]);
    const k = dash.data?.today;
    const currency = property?.currency ?? 'XAF';

    const stats = k ? [
        { label: t('dashboard.occupancy'), value: `${k.occupancy_percent}%`, icon: BedDouble, color: 'text-yellow-700', bg: 'bg-yellow-50', show: true },
        { label: t('dashboard.arrivals'), value: k.arrivals, icon: DoorOpen, color: 'text-blue-600', bg: 'bg-blue-50', show: true },
        { label: t('dashboard.departures'), value: k.departures, icon: DoorClosed, color: 'text-amber-600', bg: 'bg-amber-50', show: true },
        { label: t('dashboard.vacant'), value: Math.max(0, k.rooms_available - k.rooms_sold), icon: Building2, color: 'text-slate-600', bg: 'bg-slate-50', show: true },
        { label: t('dashboard.room_revenue'), value: formatCurrency(k.room_revenue, currency), icon: TrendingUp, color: 'text-yellow-700', bg: 'bg-yellow-50', show: seeRevenue },
        { label: t('dashboard.adr_revpar'), value: `${formatCurrency(k.adr, currency)} / ${formatCurrency(k.revpar, currency)}`, icon: Wallet, color: 'text-violet-600', bg: 'bg-violet-50', show: seeRevenue },
        { label: t('dashboard.dirty'), value: k.dirty_rooms, icon: Sparkles, color: 'text-amber-600', bg: 'bg-amber-50', show: true },
        { label: t('dashboard.work_orders'), value: k.open_work_orders, icon: Wrench, color: 'text-red-600', bg: 'bg-red-50', show: true },
    ].filter((s) => s.show) : [];

    const roomStatus = k ? [
        { label: t('dashboard.occupied'), count: k.rooms_sold, color: 'bg-yellow-500' },
        { label: t('dashboard.free'), count: Math.max(0, k.rooms_available - k.rooms_sold), color: 'bg-slate-300' },
        { label: t('dashboard.dirty_short'), count: k.dirty_rooms, color: 'bg-amber-400' },
        { label: t('dashboard.ooo'), count: k.out_of_order, color: 'bg-red-400' },
    ] : [];

    return (
        <AppLayout
            title={t('dashboard.title')}
            subtitle={property ? t('dashboard.business_date', { hotel: property.name, date: k?.business_date ?? '…' }) : t('dashboard.no_hotel')}
            actions={canBook ? <Link href="/reservations/create" className="btn-primary btn-sm"><Plus className="w-3.5 h-3.5" /> <span className="hidden sm:inline">{t('dashboard.new_reservation')}</span></Link> : undefined}
        >
            <Head title={t('dashboard.title')} />

            {!property && (
                <div className="card p-6 text-sm text-slate-600">{t('dashboard.no_hotel_text')}</div>
            )}
            {property && !seeKpis && !seeBoard && (
                <div className="card p-6 flex items-start gap-3">
                    <LayoutDashboard className="w-5 h-5 text-slate-400 mt-0.5" />
                    <div>
                        <h3 className="text-sm font-semibold text-slate-900">{t('dashboard.welcome', { name: auth.user?.name ?? '' })}</h3>
                        <p className="text-sm text-slate-600 mt-1">{t('dashboard.welcome_text')}</p>
                    </div>
                </div>
            )}
            {dash.error && (
                <div className="card p-4 mb-4 flex items-center gap-2 text-sm text-red-700 bg-red-50">
                    <AlertTriangle className="w-4 h-4" /> {dash.error.message}
                </div>
            )}

            {seeKpis && (
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    {(dash.loading && !k ? Array.from({ length: 8 }) : stats).map((s, i) => {
                        if (!s) return <div key={i} className="stat-tile h-24 animate-pulse bg-slate-50" />;
                        const st = s as (typeof stats)[number];
                        const Icon = st.icon;
                        return (
                            <div key={st.label} className="stat-tile">
                                <div className={cn('w-9 h-9 rounded-lg flex items-center justify-center mb-3', st.bg)}>
                                    <Icon className={cn('w-4.5 h-4.5', st.color)} />
                                </div>
                                <div className="text-2xl font-bold text-slate-900 mb-0.5 break-words">{st.value}</div>
                                <div className="text-xs text-slate-500">{st.label}</div>
                            </div>
                        );
                    })}
                </div>
            )}

            {seeKpis && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                    {seeForecast && (
                        <div className="card p-5 lg:col-span-2">
                            <h3 className="text-sm font-semibold text-slate-900">{t('dashboard.forecast')}</h3>
                            <p className="text-xs text-slate-500 mt-0.5 mb-4">{t('dashboard.forecast_text')}</p>
                            <ResponsiveContainer width="100%" height={200}>
                                <AreaChart data={forecast.data ?? []} margin={{ top: 0, right: 0, left: -20, bottom: 0 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                    <XAxis dataKey="date" tickFormatter={(d: string) => d.slice(5)} tick={{ fontSize: 12, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                    <YAxis tick={{ fontSize: 12, fill: '#94a3b8' }} axisLine={false} tickLine={false} domain={[0, 100]} />
                                    <Tooltip
                                        contentStyle={{ background: '#1e293b', border: 'none', borderRadius: 8, fontSize: 12, color: '#f1f5f9' }}
                                        formatter={(v: number) => [`${v}%`, t('dashboard.occupancy')]}
                                    />
                                    <Area type="monotone" dataKey="occupancy_percent" stroke="#c9a84c" strokeWidth={2} fill="#c9a84c" fillOpacity={0.12} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    <div className={cn('card p-5', !seeForecast && 'lg:col-span-3')}>
                        <h3 className="text-sm font-semibold text-slate-900 mb-4">{t('dashboard.room_status')}</h3>
                        <div className="space-y-3">
                            {roomStatus.map((s) => (
                                <div key={s.label}>
                                    <div className="flex items-center justify-between mb-1">
                                        <span className="text-xs text-slate-600">{s.label}</span>
                                        <span className="text-xs font-semibold text-slate-900">{s.count}</span>
                                    </div>
                                    <div className="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                        <div className={cn('h-full rounded-full', s.color)} style={{ width: `${k?.rooms_total ? (s.count / k.rooms_total) * 100 : 0}%` }} />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-4 pt-4 border-t border-slate-100 flex justify-between text-xs text-slate-500">
                            <span>{t('dashboard.total_rooms', { count: k?.rooms_total ?? '—' })}</span>
                            {can('housekeeping.view') && <Link href="/housekeeping" className="text-yellow-700 font-semibold hover:underline">{t('dashboard.see_all')}</Link>}
                        </div>
                    </div>
                </div>
            )}

            {seeBoard && (
                <div className="card">
                    <div className="flex items-center justify-between gap-2 px-5 py-4 border-b border-slate-100">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-900">{t('dashboard.arrivals')}</h3>
                            <p className="text-xs text-slate-500 mt-0.5">{t('dashboard.expected', { count: board.data?.arrivals.length ?? 0 })}</p>
                        </div>
                        <Link href="/front-desk" className="btn-secondary btn-sm"><DoorOpen className="w-3.5 h-3.5" /> {t('dashboard.front_desk')}</Link>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full">
                            <thead>
                                <tr>
                                    <th>{t('dashboard.guest')}</th><th>{t('dashboard.number')}</th><th>{t('dashboard.room')}</th>
                                    <th>{t('dashboard.type')}</th><th>{t('dashboard.departure')}</th><th>{t('dashboard.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(board.data?.arrivals ?? []).map((a) => (
                                    <tr key={a.id}>
                                        <td className="font-medium text-slate-900">{a.guest.first_name} {a.guest.last_name}{a.guest.vip && <span className="badge-yellow ml-2">VIP</span>}</td>
                                        <td className="font-mono text-xs text-slate-500">{a.number}</td>
                                        <td className="font-mono font-semibold text-slate-900">{a.room?.number ?? '—'}</td>
                                        <td className="text-slate-600">{a.room_type?.code}</td>
                                        <td className="text-slate-600">{a.departure.slice(0, 10)}</td>
                                        <td className="text-slate-600">{a.status}</td>
                                    </tr>
                                ))}
                                {board.data && board.data.arrivals.length === 0 && (
                                    <tr><td colSpan={6} className="text-center text-sm text-slate-400 py-8">{t('dashboard.no_arrivals')}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
