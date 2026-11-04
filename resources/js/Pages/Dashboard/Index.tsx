import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    DoorOpen, DoorClosed, BedDouble, TrendingUp, Wallet, Wrench, Sparkles, Building2, Plus, AlertTriangle, LayoutDashboard,
} from 'lucide-react';
import { LineChart, Line, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts';
import { cn, formatCurrency, formatDate, formatPercent } from '@/lib/utils';
import { useApi } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';
import ChartFrame, { axisFormatter, dayTick, makeTooltip, seriesColor, useAxisProps, type ChartSeries } from '@/Components/ui/ChartFrame';
import ErrorState from '@/Components/ui/ErrorState';
import LoadingState from '@/Components/ui/LoadingState';
import { MyTasksLink, Widget, type ComposeData } from './Widgets';

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

    const hotelKey = property?.id ?? 0;
    // The server composes the widgets and their order for the user's role (app/Modules/Reports/Dashboard).
    // A widget is shown only when the role's composition lists it AND the user holds the permission its data needs.
    const compose = useApi<{ role: string | null; order: string[]; data: ComposeData; business_date: string }>(property ? 'reports/dashboard/compose' : null, [hotelKey]);
    const order = compose.data?.order ?? [];
    const shows = (id: string) => order.includes(id);
    const seeKpis = shows('kpis') && can('reports.view', 'frontdesk.view');
    const seeRevenue = shows('revenue') && can('reports.view');
    const seeBoard = shows('arrivals') && can('frontdesk.view');
    const seeForecast = shows('forecast') && can('reports.view');
    const seeRoomStatus = shows('room_status') && can('reports.view', 'frontdesk.view');
    const canBook = can('reservations.manage');

    const dash = useApi<{ today: Kpis }>(property && (seeKpis || seeRoomStatus) ? 'reports/dashboard' : null, [hotelKey, seeKpis, seeRoomStatus]);
    const forecast = useApi<ForecastDay[]>(property && seeForecast ? 'reports/forecast?days=14' : null, [hotelKey]);
    const locale = useLocale();
    const ax = useAxisProps();
    const occSeries: ChartSeries[] = [{ key: 'occupancy_percent', label: t('dashboard.occupancy'), format: 'percent' }];
    const OccTip = makeTooltip(occSeries, (d) => formatDate(d, locale), locale);
    const board = useApi<Board>(property && seeBoard ? 'front-desk' : null, [hotelKey]);
    const k = dash.data?.today;
    const currency = property?.currency ?? 'XAF';

    const stats = k ? [
        { label: t('dashboard.occupancy'), value: formatPercent(k.occupancy_percent), icon: BedDouble, color: 'text-yellow-700', bg: 'bg-yellow-50', show: true },
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

    const kpiBlock = (
        <div key="kpis" className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {(dash.loading && !k ? Array.from({ length: 8 }) : stats).map((s, i) => {
                if (!s) return <div key={i} className="stat-tile h-24 bg-slate-100" />;
                const st = s as (typeof stats)[number];
                const Icon = st.icon;
                return (
                    <div key={st.label} className="stat-tile">
                        <div className={cn('w-9 h-9 rounded-lg flex items-center justify-center mb-3', st.bg)}>
                            <Icon className={cn('w-4.5 h-4.5', st.color)} aria-hidden="true" />
                        </div>
                        <div className="l1-kpi mb-1">{st.value}</div>
                        <div className="l1-kpi-label">{st.label}</div>
                    </div>
                );
            })}
        </div>
    );

    const forecastBlock = (
        <div key="forecast" className="card p-5">
            <ChartFrame title={t('dashboard.forecast')} description={t('dashboard.forecast_text')} data={(forecast.data ?? []) as unknown as Record<string, unknown>[]}
                series={occSeries} xKey="date" xLabel={t('ui2.chart_date')} xFormat={(d) => formatDate(d, locale)}
                loading={forecast.loading} error={forecast.error?.message} height={{ phone: 200, desktop: 240 }}>
                <LineChart data={forecast.data ?? []} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid {...ax.grid} />
                    <XAxis dataKey="date" {...ax.x} tickFormatter={dayTick(locale)} />
                    <YAxis {...ax.y} domain={[0, 100]} tickFormatter={axisFormatter('percent', locale)} />
                    <Tooltip content={<OccTip />} />
                    <Line type="monotone" dataKey="occupancy_percent" stroke={seriesColor(0)} strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} isAnimationActive={false} />
                </LineChart>
            </ChartFrame>
        </div>
    );

    const roomStatusBlock = (
        <div key="room_status" className="card p-5">
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
                {can('housekeeping.view') && <Link href="/housekeeping" className="inline-flex min-h-[44px] items-center text-yellow-700 font-semibold hover:underline">{t('dashboard.see_all')}</Link>}
            </div>
        </div>
    );

    const arrivalsBlock = (
        <div key="arrivals" className="card">
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
                            <tr><td colSpan={6} className="text-center text-sm text-slate-500 py-8">{t('dashboard.no_arrivals')}</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );

    // Blocks the page renders itself; every other id in the role's order is a small server-fed widget.
    const blocks: Record<string, React.ReactNode> = {
        kpis: seeKpis ? kpiBlock : null,
        revenue: null, // revenue tiles live inside the KPI grid (hidden unless the role may see revenue)
        forecast: seeForecast ? forecastBlock : null,
        room_status: seeRoomStatus ? roomStatusBlock : null,
        arrivals: seeBoard ? arrivalsBlock : null,
    };

    return (
        <AppLayout
            title={t('dashboard.title')}
            subtitle={property ? t('dashboard.business_date', { hotel: property.name, date: k?.business_date ?? compose.data?.business_date ?? '…' }) : t('dashboard.no_hotel')}
            actions={canBook ? <Link href="/reservations/create" className="btn-primary btn-sm"><Plus className="w-3.5 h-3.5" /> <span className="hidden sm:inline">{t('dashboard.new_reservation')}</span></Link> : undefined}
        >
            <Head title={t('dashboard.title')} />

            <MyTasksLink />

            {!property && (
                <div className="card p-6 text-sm text-slate-600">{t('dashboard.no_hotel_text')}</div>
            )}
            {property && compose.data && order.length === 0 && (
                <div className="card p-6 flex items-start gap-3">
                    <LayoutDashboard className="w-5 h-5 text-slate-500 mt-0.5" aria-hidden="true" />
                    <div>
                        <h3 className="text-sm font-semibold text-slate-900">{t('dashboard.welcome', { name: auth.user?.name ?? '' })}</h3>
                        <p className="text-sm text-slate-600 mt-1">{t('dashboard.welcome_text')}</p>
                    </div>
                </div>
            )}
            {property && compose.loading && !compose.data && !compose.error && <LoadingState variant="cards" rows={4} className="mb-4" />}
            {compose.error && !compose.data && <ErrorState error={compose.error} onRetry={compose.reload} compact className="mb-4" />}
            {dash.error && (
                <div className="card p-4 mb-4 flex items-center gap-2 text-sm text-red-700 bg-red-50">
                    <AlertTriangle className="w-4 h-4" aria-hidden="true" /> {dash.error.message}
                </div>
            )}

            {/* Role-ordered: the role's own work first. Big report blocks and small widgets keep the order the server gave. */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {order.map((id) => {
                    if (id in blocks) return blocks[id] ? <div key={id} className="lg:col-span-2">{blocks[id]}</div> : null;
                    return <div key={id}><Widget id={id} data={compose.data?.data ?? {}} currency={currency} can={can} /></div>;
                })}
            </div>
        </AppLayout>
    );
}
