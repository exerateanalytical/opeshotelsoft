import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BarChart3, Building2, CalendarDays, Download, RefreshCw, Radio, ShieldCheck, TrendingUp, Wallet, BedDouble } from 'lucide-react';
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts';
import { cn, formatCurrency, formatDate, formatDateTime, formatNumber, formatPercent } from '@/lib/utils';
import { activeHotelId, api, ApiError, useApi } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';
import ChartFrame, { axisFormatter, dayTick, makeTooltip, seriesColor, seriesDash, useAxisProps, type ChartSeries } from '@/Components/ui/ChartFrame';
import OpsPrintButton from '@/Components/OpsPrintButton';
import Alert from '@/Components/ui/Alert';

interface Kpis {
    business_date: string; rooms_total: number; out_of_order: number; rooms_available: number; rooms_sold: number;
    occupancy_percent: number; room_revenue: number; total_revenue: number; adr: number; revpar: number;
    arrivals: number; departures: number; dirty_rooms: number; open_work_orders: number; guest_ledger: number; city_ledger: number;
}
interface HistPoint { date: string; occupancy_percent?: number; room_revenue?: number; total_revenue?: number; adr?: number; revpar?: number }
interface Dashboard { today: Kpis; last_30_days: HistPoint[] }
interface ForecastDay { date: string; rooms_sold: number; occupancy_percent: number; room_revenue: number; adr: number }
interface GroupRow extends Partial<Kpis> { hotel: { id: number; code: string; name: string; city: string | null } }
interface Group { hotels: GroupRow[]; total: { rooms_available: number; rooms_sold: number; occupancy_percent: number; room_revenue: number; total_revenue: number; adr: number; revpar: number; arrivals: number; departures: number } }
interface ChannelRow { channel: string | null; room_nights: number; revenue: number; bookings: number }
interface BusinessDay { id: number; date: string; status: string; closed_at: string | null; summary: string | Record<string, unknown> | null }
interface Paginated<T> { data: T[]; total: number; current_page: number; last_page: number }
interface RegisterEntry {
    id: number; room_number: string; full_name: string; nationality: string | null; date_of_birth: string | null; place_of_birth: string | null;
    profession: string | null; id_type: string | null; id_number: string | null; id_expiry: string | null; coming_from: string | null;
    going_to: string | null; purpose: string | null; arrival: string; departure: string; checked_out_at: string | null;
}
interface Connection {
    id: number; provider: string; name: string; environment: string; status: string; push_rates: boolean; push_availability: boolean;
    receive_reservations: boolean; sync_days: number; last_push_at: string | null; last_pull_at: string | null; has_credentials: boolean; dry_run: boolean;
}

const TABS = [
    { key: 'daily', label: 'reports.tab.daily', icon: BarChart3 },
    { key: 'forecast', label: 'reports.tab.forecast', icon: TrendingUp },
    { key: 'group', label: 'reports.tab.group', icon: Building2 },
    { key: 'channels', label: 'reports.tab.channels', icon: Radio },
    { key: 'days', label: 'reports.tab.days', icon: CalendarDays },
    { key: 'police', label: 'reports.tab.police', icon: ShieldCheck },
    { key: 'connections', label: 'reports.tab.connections', icon: RefreshCw },
] as const;
type Tab = (typeof TABS)[number]['key'];

const iso = (d: Date) => d.toISOString().slice(0, 10);
const today = () => iso(new Date());
const daysAgo = (n: number) => iso(new Date(Date.now() - n * 86400000));

function Banner({ error, success }: { error?: string | null; success?: string | null }) {
    if (error) return <Alert variant="danger">{error}</Alert>;
    if (success) return <Alert variant="success">{success}</Alert>;
    return null;
}
function Loading() { const t = useT(); return <div className="py-10 text-center text-sm text-slate-500">{t('common.loading')}</div>; }
function Kpi({ label, value, icon: Icon, hint }: { label: string; value: string; icon: typeof Wallet; hint?: string }) {
    return (
        <div className="card min-w-0 p-4">
            <div className="l1-kpi-label flex items-center justify-between gap-2">{label}<Icon className="h-4 w-4 text-navy-700" aria-hidden="true" /></div>
            <div className="l1-kpi mt-2">{value}</div>
            {hint && <div className="l1-support mt-1">{hint}</div>}
        </div>
    );
}

/** Revenue (currency, left axis) and occupancy (percent, right axis) over dates or categories. */
function RevOccChart<Row extends Record<string, unknown>>({ title, description, data, xKey, xLabel, revKey, occKey, revLabel, occLabel, bars, dates = true }: {
    title: string; description?: string; data: Row[]; xKey: keyof Row & string; xLabel: string; revKey: string; occKey: string; revLabel: string; occLabel: string; bars?: boolean; dates?: boolean;
}) {
    const locale = useLocale();
    const ax = useAxisProps();
    const series: ChartSeries[] = [{ key: occKey, label: occLabel, format: 'percent' }, { key: revKey, label: revLabel, format: 'currency' }];
    const fx = dates ? dayTick(locale) : (v: string) => v;
    const Tip = makeTooltip(series, dates ? (l) => formatDate(l, locale) : fx, locale);
    return (
        <ChartFrame title={title} description={description} data={data} series={series} xKey={xKey} xLabel={xLabel} xFormat={dates ? (l) => formatDate(l, locale) : undefined}>
            {bars ? (
                <BarChart data={data} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                    <CartesianGrid {...ax.grid} />
                    <XAxis dataKey={xKey as string} {...ax.x} tickFormatter={fx} />
                    <YAxis yAxisId="o" {...ax.y} tickFormatter={axisFormatter('percent', locale)} />
                    <YAxis yAxisId="r" orientation="right" {...ax.y} tickFormatter={axisFormatter('currency', locale)} />
                    <Tooltip content={<Tip />} cursor={{ fill: 'var(--c-surface-subtle)' }} />
                    <Bar yAxisId="o" dataKey={occKey} name={occLabel} fill={seriesColor(0)} isAnimationActive={false} />
                    <Bar yAxisId="r" dataKey={revKey} name={revLabel} fill={seriesColor(1)} isAnimationActive={false} />
                </BarChart>
            ) : (
                <LineChart data={data} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                    <CartesianGrid {...ax.grid} />
                    <XAxis dataKey={xKey as string} {...ax.x} tickFormatter={fx} />
                    <YAxis yAxisId="o" {...ax.y} tickFormatter={axisFormatter('percent', locale)} />
                    <YAxis yAxisId="r" orientation="right" {...ax.y} tickFormatter={axisFormatter('currency', locale)} />
                    <Tooltip content={<Tip />} />
                    <Line yAxisId="o" type="monotone" dataKey={occKey} name={occLabel} stroke={seriesColor(0)} strokeWidth={2.5} strokeDasharray={seriesDash(0)} dot={false} activeDot={{ r: 4 }} isAnimationActive={false} />
                    <Line yAxisId="r" type="monotone" dataKey={revKey} name={revLabel} stroke={seriesColor(1)} strokeWidth={2.5} strokeDasharray={seriesDash(1)} dot={false} activeDot={{ r: 4 }} isAnimationActive={false} />
                </LineChart>
            )}
        </ChartFrame>
    );
}

function ChannelChart({ rows }: { rows: { channel: string; revenue: number }[] }) {
    const t = useT();
    const locale = useLocale();
    const ax = useAxisProps();
    const series: ChartSeries[] = [{ key: 'revenue', label: t('reports.revenue'), format: 'currency' }];
    const Tip = makeTooltip(series, (l) => l, locale);
    return (
        <ChartFrame title={t('reports.tab.channels')} data={rows} series={series} xKey="channel" xLabel={t('reports.channel')}>
            <BarChart data={rows} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                <CartesianGrid {...ax.grid} />
                <XAxis dataKey="channel" {...ax.x} />
                <YAxis {...ax.y} tickFormatter={axisFormatter('currency', locale)} />
                <Tooltip content={<Tip />} cursor={{ fill: 'var(--c-surface-subtle)' }} />
                <Bar dataKey="revenue" name={t('reports.revenue')} fill={seriesColor(0)} isAnimationActive={false} />
            </BarChart>
        </ChartFrame>
    );
}

function Daily() {
    const t = useT();
    const { data, error, loading } = useApi<Dashboard>('reports/dashboard');
    if (loading && !data) return <Loading />;
    if (error) return <Banner error={error.message} />;
    if (!data) return null;
    const k = data.today;
    return (
        <div className="space-y-4">
            <p className="text-sm text-slate-500">{t('reports.business_date')} <b>{formatDate(k.business_date)}</b></p>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,13rem),1fr))] gap-3 [&>*]:min-w-0">
                <Kpi label={t('reports.occupancy')} value={formatPercent(k.occupancy_percent)} icon={BedDouble} hint={t('reports.rooms_hint', { sold: k.rooms_sold, available: k.rooms_available, ooo: k.out_of_order })} />
                <Kpi label={t('reports.adr')} value={formatCurrency(k.adr)} icon={TrendingUp} />
                <Kpi label={t('reports.revpar')} value={formatCurrency(k.revpar)} icon={TrendingUp} />
                <Kpi label={t('reports.total_revenue')} value={formatCurrency(k.total_revenue)} icon={Wallet} hint={t('reports.rooms_revenue_hint', { amount: formatCurrency(k.room_revenue) })} />
            </div>
            <div className="card overflow-x-auto p-4">
                <table className="table-auto w-full max-w-3xl text-sm">
                    <tbody>
                        {[
                            [t('reports.rooms_total'), k.rooms_total], [t('reports.arrivals'), k.arrivals], [t('reports.departures'), k.departures], [t('reports.dirty_rooms'), k.dirty_rooms],
                            [t('reports.open_work_orders'), k.open_work_orders], [t('reports.guest_ledger'), formatCurrency(k.guest_ledger)], [t('reports.city_ledger'), formatCurrency(k.city_ledger)],
                        ].map(([k, v]) => <tr key={k as string} className="border-b last:border-0"><th className="py-1.5 text-left font-medium text-slate-600">{k}</th><td className="text-right">{v}</td></tr>)}
                    </tbody>
                </table>
            </div>
            <div className="card p-4">
                {data.last_30_days.length === 0 ? (
                    <><h3 className="l1-section-title mb-2">{t('reports.last_30')}</h3><p className="l1-support">{t('reports.no_closed_days')}</p></>
                ) : (
                    <RevOccChart title={t('reports.last_30')} data={data.last_30_days as unknown as Record<string, unknown>[]} xKey="date" xLabel={t('reports.date')}
                        revKey="room_revenue" occKey="occupancy_percent" revLabel={t('reports.room_revenue')} occLabel={t('reports.occupancy')} />
                )}
            </div>
        </div>
    );
}

function Forecast() {
    const t = useT();
    const [days, setDays] = useState(30);
    const { data, error, loading } = useApi<ForecastDay[]>(`reports/forecast?days=${days}`);
    const rev = (data ?? []).reduce((s, d) => s + d.room_revenue, 0);
    const avgOcc = data?.length ? (data.reduce((s, d) => s + d.occupancy_percent, 0) / data.length).toFixed(1) : '0';
    return (
        <div className="space-y-4">
            <div className="flex gap-2">
                {[30, 60, 90].map((d) => (
                    <button key={d} onClick={() => setDays(d)} className={days === d ? 'btn-primary' : 'btn-secondary'}>{t('reports.n_days', { count: d })}</button>
                ))}
            </div>
            <Banner error={error?.message} />
            {loading && !data ? <Loading /> : data && (
                <>
                    <div className="grid grid-cols-2 gap-3">
                        <Kpi label={t('reports.revenue_otb')} value={formatCurrency(rev)} icon={Wallet} />
                        <Kpi label={t('reports.avg_occupancy')} value={formatPercent(Number(avgOcc), { decimals: 1 })} icon={BedDouble} />
                    </div>
                    <div className="card p-4">
                        <RevOccChart title={t('reports.tab.forecast')} data={data as unknown as Record<string, unknown>[]} xKey="date" xLabel={t('reports.date')}
                            revKey="room_revenue" occKey="occupancy_percent" revLabel={t('reports.room_revenue')} occLabel={t('reports.occupancy')} />
                    </div>
                </>
            )}
        </div>
    );
}

function Group() {
    const t = useT();
    const { data, error, loading } = useApi<Group>('reports/group-dashboard');
    if (loading && !data) return <Loading />;
    if (error) return <Banner error={error.message} />;
    if (!data) return null;
    const chart = data.hotels.map((h) => ({ name: h.hotel.code || h.hotel.name, occupancy: h.occupancy_percent ?? 0, revpar: h.revpar ?? 0 }));
    return (
        <div className="space-y-4">
            <div className="card p-4">
                <RevOccChart bars dates={false} title={t('reports.tab.group')} data={chart} xKey="name" xLabel={t('reports.hotel')}
                    revKey="revpar" occKey="occupancy" revLabel={t('reports.revpar')} occLabel={t('reports.occupancy')} />
            </div>
            <div className="card overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.hotel')}</th><th>{t('reports.date')}</th><th className="text-right">{t('reports.occ')}</th><th className="text-right">{t('reports.sold')}</th><th className="text-right">{t('reports.adr')}</th><th className="text-right">{t('reports.revpar')}</th><th className="text-right">{t('reports.room_rev')}</th><th className="text-right">{t('reports.total_rev')}</th><th className="text-right">{t('reports.arr_dep')}</th></tr></thead>
                    <tbody>
                        {data.hotels.map((h) => (
                            <tr key={h.hotel.id} className="border-t">
                                <td className="p-2 font-medium">{h.hotel.name}<div className="text-xs text-slate-500">{h.hotel.city}</div></td>
                                <td>{h.business_date ? formatDate(h.business_date) : '—'}</td>
                                <td className="num">{formatPercent(h.occupancy_percent ?? 0)}</td><td className="num">{h.rooms_sold}/{h.rooms_available}</td>
                                <td className="num">{formatCurrency(h.adr ?? 0)}</td><td className="num">{formatCurrency(h.revpar ?? 0)}</td>
                                <td className="num">{formatCurrency(h.room_revenue ?? 0)}</td><td className="num">{formatCurrency(h.total_revenue ?? 0)}</td>
                                <td className="num">{h.arrivals}/{h.departures}</td>
                            </tr>
                        ))}
                        <tr className="border-t bg-slate-50 font-semibold">
                            <td className="p-2">{t('reports.network_total')}</td><td />
                            <td className="num">{formatPercent(data.total.occupancy_percent)}</td><td className="num">{data.total.rooms_sold}/{data.total.rooms_available}</td>
                            <td className="num">{formatCurrency(data.total.adr)}</td><td className="num">{formatCurrency(data.total.revpar)}</td>
                            <td className="num">{formatCurrency(data.total.room_revenue)}</td><td className="num">{formatCurrency(data.total.total_revenue)}</td>
                            <td className="num">{data.total.arrivals}/{data.total.departures}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function Range({ from, to, setFrom, setTo, children }: { from: string; to: string; setFrom: (s: string) => void; setTo: (s: string) => void; children?: React.ReactNode }) {
    const t = useT();
    return (
        <div className="flex flex-wrap items-end gap-2 print:hidden">
            <label className="text-xs text-slate-500">{t('reports.from')}<input type="date" className="input block" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
            <label className="text-xs text-slate-500">{t('reports.to')}<input type="date" className="input block" value={to} onChange={(e) => setTo(e.target.value)} /></label>
            {children}
        </div>
    );
}

function Channels() {
    const t = useT();
    const [from, setFrom] = useState(daysAgo(30));
    const [to, setTo] = useState(today());
    const { data, error, loading } = useApi<ChannelRow[]>(from && to ? `reports/channels?from=${from}&to=${to}` : null);
    const rows = (data ?? []).map((r) => ({ ...r, channel: r.channel ?? 'direct', revenue: Number(r.revenue), room_nights: Number(r.room_nights) }));
    const total = rows.reduce((s, r) => s + r.revenue, 0);
    return (
        <div className="space-y-4">
            <Range from={from} to={to} setFrom={setFrom} setTo={setTo} />
            <Banner error={error?.message} />
            {loading && !data ? <Loading /> : rows.length === 0 ? <p className="text-sm text-slate-500">{t('reports.no_production')}</p> : (
                <>
                    <div className="card p-4">
                        <ChannelChart rows={rows} />
                    </div>
                    <div className="card overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.channel')}</th><th className="text-right">{t('reports.bookings')}</th><th className="text-right">{t('reports.room_nights')}</th><th className="text-right">{t('reports.revenue')}</th><th className="text-right">{t('reports.adr')}</th><th className="text-right">{t('reports.share')}</th></tr></thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.channel} className="border-t">
                                        <td className="p-2 font-medium capitalize">{r.channel.replace(/_/g, ' ')}</td><td className="num">{formatNumber(r.bookings)}</td><td className="num">{formatNumber(r.room_nights)}</td>
                                        <td className="num">{formatCurrency(r.revenue)}</td><td className="num">{formatCurrency(r.room_nights ? Math.round(r.revenue / r.room_nights) : 0)}</td>
                                        <td className="num">{formatPercent(total ? (r.revenue / total) * 100 : 0, { decimals: 1 })}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </div>
    );
}

function BusinessDays() {
    const t = useT();
    const [from, setFrom] = useState(daysAgo(30));
    const [to, setTo] = useState(today());
    const [page, setPage] = useState(1);
    const qs = new URLSearchParams({ per_page: '30', page: String(page), ...(from ? { from } : {}), ...(to ? { to } : {}) });
    const { data, error, loading } = useApi<Paginated<BusinessDay>>(`business-days?${qs}`, [from, to, page]);
    const range = (
        <Range from={from} to={to} setFrom={(v: string) => { setFrom(v); setPage(1); }} setTo={(v: string) => { setTo(v); setPage(1); }}>
            {data && data.last_page > 1 && (
                <div className="inline-flex items-center gap-2 text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                    <span>{t('reports.page', { current: data.current_page, last: data.last_page })}</span>
                    <button className="btn-secondary" disabled={page >= data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                </div>
            )}
        </Range>
    );
    if (loading && !data) return <div className="space-y-4">{range}<Loading /></div>;
    if (error) return <div className="space-y-4">{range}<Banner error={error.message} /></div>;
    const parse = (s: BusinessDay['summary']): Record<string, unknown> => (typeof s === 'string' ? (() => { try { return JSON.parse(s); } catch { return {}; } })() : s ?? {});
    const num = (v: unknown) => (typeof v === 'number' ? v : Number(v ?? 0));
    return (
        <div className="space-y-4">
        {range}
        <div className="card overflow-x-auto">
            <table className="table-auto w-full text-sm">
                <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.date')}</th><th>{t('reports.status')}</th><th>{t('reports.closed_at')}</th><th className="text-right">{t('reports.occ')}</th><th className="text-right">{t('reports.adr')}</th><th className="text-right">{t('reports.revpar')}</th><th className="text-right">{t('reports.room_rev')}</th><th className="text-right">{t('reports.total_rev')}</th></tr></thead>
                <tbody>
                    {(data?.data ?? []).map((d) => {
                        const s = parse(d.summary);
                        return (
                            <tr key={d.id} className="border-t">
                                <td className="p-2">{formatDate(d.date)}</td>
                                <td><span className={d.status === 'closed' ? 'badge-slate' : 'badge-green'}>{d.status}</span></td>
                                <td>{d.closed_at ? formatDateTime(d.closed_at) : '—'}</td>
                                <td className="num">{s.occupancy_percent != null ? formatPercent(num(s.occupancy_percent)) : '—'}</td>
                                <td className="num">{s.adr != null ? formatCurrency(num(s.adr)) : '—'}</td>
                                <td className="num">{s.revpar != null ? formatCurrency(num(s.revpar)) : '—'}</td>
                                <td className="num">{s.room_revenue != null ? formatCurrency(num(s.room_revenue)) : '—'}</td>
                                <td className="num">{s.total_revenue != null ? formatCurrency(num(s.total_revenue)) : '—'}</td>
                            </tr>
                        );
                    })}
                    {data?.data.length === 0 && <tr><td colSpan={8} className="p-4 text-center text-slate-500">{t('reports.no_days')}</td></tr>}
                </tbody>
            </table>
        </div>
        </div>
    );
}

function Police() {
    const t = useT();
    const [from, setFrom] = useState(daysAgo(7));
    const [to, setTo] = useState(today());
    const { data, error, loading } = useApi<RegisterEntry[]>(from && to ? `police-register?from=${from}&to=${to}` : null);
    const [csvError, setCsvError] = useState<string | undefined>();
    const d = (s: string | null) => (s ? formatDate(s) : '');
    const downloadCsv = async () => {
        setCsvError(undefined);
        try {
            const hotelId = activeHotelId();
            const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
            const res = await fetch(`/api/v1/police-register?from=${from}&to=${to}&format=csv`, {
                credentials: 'same-origin',
                headers: { Accept: 'text/csv', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf, ...(hotelId ? { 'X-Hotel-Id': hotelId } : {}) },
            });
            if (!res.ok) throw new Error((await res.json().catch(() => ({}))).detail ?? res.statusText);
            const url = URL.createObjectURL(await res.blob());
            const a = document.createElement('a');
            a.href = url; a.download = `police-register-${from}-${to}.csv`; a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            setCsvError(e instanceof Error ? e.message : t('reports.download_failed'));
        }
    };
    return (
        <div className="space-y-4">
            <Range from={from} to={to} setFrom={setFrom} setTo={setTo}>
                <button className="btn-secondary inline-flex items-center gap-1" disabled={!from || !to} onClick={downloadCsv}><Download className="h-4 w-4" /> {t('reports.download_csv')}</button>
                <OpsPrintButton className="btn-secondary inline-flex items-center gap-1" label={t('documents4.police')} path={`police-register?from=${from}&to=${to}`} />
            </Range>
            <Banner error={error?.message ?? csvError} />
            <div className="hidden print:block"><h2 className="text-lg font-semibold">{t('reports.police_title')}</h2><p className="text-sm">{d(from)} – {d(to)}</p></div>
            {loading && !data ? <Loading /> : (
                <div className="card overflow-x-auto">
                    <table className="table-auto w-full text-xs">
                        <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.room')}</th><th>{t('reports.name')}</th><th>{t('reports.nat')}</th><th>{t('reports.born')}</th><th>{t('reports.profession')}</th><th>{t('reports.id')}</th><th>{t('reports.from')}</th><th>{t('reports.to')}</th><th>{t('reports.purpose')}</th><th>{t('reports.arrival')}</th><th>{t('reports.departure')}</th></tr></thead>
                        <tbody>
                            {(data ?? []).map((e) => (
                                <tr key={e.id} className="border-t align-top">
                                    <td className="p-2">{e.room_number}</td><td className="font-medium">{e.full_name}</td><td>{e.nationality}</td>
                                    <td>{d(e.date_of_birth)}{e.place_of_birth ? `, ${e.place_of_birth}` : ''}</td><td>{e.profession}</td>
                                    <td>{e.id_type} {e.id_number}{e.id_expiry ? ` (${t('reports.exp')} ${d(e.id_expiry)})` : ''}</td>
                                    <td>{e.coming_from}</td><td>{e.going_to}</td><td>{e.purpose}</td><td>{d(e.arrival)}</td><td>{d(e.departure)}</td>
                                </tr>
                            ))}
                            {data?.length === 0 && <tr><td colSpan={11} className="p-4 text-center text-slate-500">{t('reports.no_guests')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function Connections() {
    const t = useT();
    const { data, error, loading, reload } = useApi<Connection[]>('channels/connections');
    const [msg, setMsg] = useState<{ error?: string; success?: string }>({});
    const [busy, setBusy] = useState<number | null>(null);
    const act = async (c: Connection, kind: 'sync' | 'test') => {
        setBusy(c.id); setMsg({});
        try {
            const r = await api<{ status: string; error?: string | null }>(`channels/connections/${c.id}/${kind}`, { method: 'POST', body: JSON.stringify({}) });
            if (kind === 'test' && r.status !== 'ok' && r.status !== 'success' && r.error) setMsg({ error: `${c.name}: ${r.error}` });
            else setMsg({ success: kind === 'sync' ? t('reports.ari_queued', { name: c.name }) : t('reports.test_result', { name: c.name, status: r.status }) });
            reload();
        } catch (e) {
            setMsg({ error: e instanceof ApiError ? e.message : String(e) });
        } finally { setBusy(null); }
    };
    if (loading && !data) return <Loading />;
    return (
        <div className="space-y-4">
            <Banner error={error?.message ?? msg.error} success={msg.success} />
            <div className="card overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.channel')}</th><th>{t('reports.status')}</th><th>{t('reports.env')}</th><th>{t('reports.pushes')}</th><th>{t('reports.last_push')}</th><th>{t('reports.last_pull')}</th><th /></tr></thead>
                    <tbody>
                        {(data ?? []).map((c) => (
                            <tr key={c.id} className="border-t">
                                <td className="p-2 font-medium">{c.name}<div className="text-xs text-slate-500">{c.provider}{!c.has_credentials && ` · ${t('reports.no_credentials')}`}{c.dry_run && ` · ${t('reports.dry_run')}`}</div></td>
                                <td><span className={c.status === 'active' ? 'badge-green' : c.status === 'suspended' ? 'badge-red' : 'badge-yellow'}>{c.status}</span></td>
                                <td>{c.environment}</td>
                                <td className="text-xs">{[c.push_availability && t('reports.push.avail'), c.push_rates && t('reports.push.rates'), c.receive_reservations && t('reports.push.res')].filter(Boolean).join(' · ')}</td>
                                <td>{c.last_push_at ? formatDateTime(c.last_push_at) : '—'}</td>
                                <td>{c.last_pull_at ? formatDateTime(c.last_pull_at) : '—'}</td>
                                <td className="whitespace-nowrap p-2 text-right">
                                    <button disabled={busy === c.id} onClick={() => act(c, 'test')} className="btn-secondary mr-2">{t('reports.test')}</button>
                                    <button disabled={busy === c.id} onClick={() => act(c, 'sync')} className="btn-primary">{t('reports.push_now')}</button>
                                </td>
                            </tr>
                        ))}
                        {data?.length === 0 && <tr><td colSpan={7} className="p-4 text-center text-slate-500">{t('reports.no_connections')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function Page() {
    const t = useT();
    const [tab, setTab] = useState<Tab>('daily');
    return (
        <AppLayout title={t('reports.title')} subtitle={t('reports.subtitle')}>
            <Head title={t('reports.title')} />
            <div className="tabs mb-4 print:hidden">
                {TABS.map((tb) => (
                    <button key={tb.key} onClick={() => setTab(tb.key)}
                        className={cn('tab', tab === tb.key && 'active')} aria-current={tab === tb.key ? 'page' : undefined}>
                        <tb.icon className="h-4 w-4" />{t(tb.label)}
                    </button>
                ))}
            </div>
            {tab === 'daily' && <Daily />}
            {tab === 'forecast' && <Forecast />}
            {tab === 'group' && <Group />}
            {tab === 'channels' && <Channels />}
            {tab === 'days' && <BusinessDays />}
            {tab === 'police' && <Police />}
            {tab === 'connections' && <Connections />}
        </AppLayout>
    );
}
