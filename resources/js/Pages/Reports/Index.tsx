import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BarChart3, Building2, CalendarDays, Download, RefreshCw, Radio, ShieldCheck, TrendingUp, Wallet, BedDouble } from 'lucide-react';
import { AreaChart, Area, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { activeHotelId, api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';

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
    if (error) return <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>;
    if (success) return <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{success}</div>;
    return null;
}
function Loading() { const t = useT(); return <div className="py-10 text-center text-sm text-slate-500">{t('common.loading')}</div>; }
function Kpi({ label, value, icon: Icon, hint }: { label: string; value: string; icon: typeof Wallet; hint?: string }) {
    return (
        <div className="card min-w-0 p-4">
            <div className="flex items-center justify-between gap-2 text-sm font-medium text-slate-600">{label}<Icon className="h-4 w-4 text-navy-700" /></div>
            <div className="mt-2 break-words text-xl font-semibold text-slate-800 tabular-nums sm:text-2xl">{value}</div>
            {hint && <div className="mt-1 text-sm text-slate-600">{hint}</div>}
        </div>
    );
}
const short = (n: number) => (n >= 1e6 ? `${(n / 1e6).toFixed(1)}M` : n >= 1e3 ? `${Math.round(n / 1e3)}k` : String(n));

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
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
                <Kpi label={t('reports.occupancy')} value={`${k.occupancy_percent}%`} icon={BedDouble} hint={t('reports.rooms_hint', { sold: k.rooms_sold, available: k.rooms_available, ooo: k.out_of_order })} />
                <Kpi label={t('reports.adr')} value={formatCurrency(k.adr)} icon={TrendingUp} />
                <Kpi label={t('reports.revpar')} value={formatCurrency(k.revpar)} icon={TrendingUp} />
                <Kpi label={t('reports.total_revenue')} value={formatCurrency(k.total_revenue)} icon={Wallet} hint={t('reports.rooms_revenue_hint', { amount: formatCurrency(k.room_revenue) })} />
            </div>
            <div className="card overflow-x-auto p-4">
                <table className="table-auto w-full text-sm">
                    <tbody>
                        {[
                            [t('reports.rooms_total'), k.rooms_total], [t('reports.arrivals'), k.arrivals], [t('reports.departures'), k.departures], [t('reports.dirty_rooms'), k.dirty_rooms],
                            [t('reports.open_work_orders'), k.open_work_orders], [t('reports.guest_ledger'), formatCurrency(k.guest_ledger)], [t('reports.city_ledger'), formatCurrency(k.city_ledger)],
                        ].map(([k, v]) => <tr key={k as string} className="border-b last:border-0"><th className="py-1.5 text-left font-medium text-slate-600">{k}</th><td className="text-right">{v}</td></tr>)}
                    </tbody>
                </table>
            </div>
            <div className="card p-4">
                <h3 className="mb-3 text-sm font-semibold text-slate-700">{t('reports.last_30')}</h3>
                {data.last_30_days.length === 0 ? <p className="text-sm text-slate-500">{t('reports.no_closed_days')}</p> : (
                    <div className="h-64">
                        <ResponsiveContainer>
                            <AreaChart data={data.last_30_days}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                <XAxis dataKey="date" tick={{ fontSize: 13 }} />
                                <YAxis yAxisId="r" tickFormatter={short} tick={{ fontSize: 13 }} />
                                <YAxis yAxisId="o" orientation="right" unit="%" tick={{ fontSize: 13 }} />
                                <Tooltip formatter={(v, n) => (n === t('reports.occupancy') ? `${v}%` : formatCurrency(Number(v)))} />
                                <Legend formatter={(v) => <span className="text-slate-700">{v}</span>} />
                                <Area yAxisId="r" dataKey="room_revenue" name={t('reports.room_revenue')} stroke="#1a3a6b" fill="#e8edf5" />
                                <Area yAxisId="o" dataKey="occupancy_percent" name={t('reports.occupancy')} stroke="#b45309" fill="none" />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
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
                        <Kpi label={t('reports.avg_occupancy')} value={`${avgOcc}%`} icon={BedDouble} />
                    </div>
                    <div className="card h-72 p-4">
                        <ResponsiveContainer>
                            <AreaChart data={data}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                <XAxis dataKey="date" tick={{ fontSize: 13 }} />
                                <YAxis yAxisId="o" unit="%" tick={{ fontSize: 13 }} />
                                <YAxis yAxisId="r" orientation="right" tickFormatter={short} tick={{ fontSize: 13 }} />
                                <Tooltip formatter={(v, n) => (n === t('reports.occupancy') ? `${v}%` : formatCurrency(Number(v)))} />
                                <Legend formatter={(v) => <span className="text-slate-700">{v}</span>} />
                                <Area yAxisId="o" dataKey="occupancy_percent" name={t('reports.occupancy')} stroke="#1a3a6b" fill="#e8edf5" />
                                <Area yAxisId="r" dataKey="room_revenue" name={t('reports.room_revenue')} stroke="#b45309" fill="none" />
                            </AreaChart>
                        </ResponsiveContainer>
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
            <div className="card h-64 p-4">
                <ResponsiveContainer>
                    <BarChart data={chart}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                        <XAxis dataKey="name" tick={{ fontSize: 13 }} />
                        <YAxis yAxisId="o" unit="%" tick={{ fontSize: 13 }} />
                        <YAxis yAxisId="r" orientation="right" tickFormatter={short} tick={{ fontSize: 13 }} />
                        <Tooltip formatter={(v, n) => (n === t('reports.occupancy') ? `${v}%` : formatCurrency(Number(v)))} />
                        <Legend formatter={(v) => <span className="text-slate-700">{v}</span>} />
                        <Bar yAxisId="o" dataKey="occupancy" name={t('reports.occupancy')} fill="#1a3a6b" />
                        <Bar yAxisId="r" dataKey="revpar" name={t('reports.revpar')} fill="#b45309" />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <div className="card overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.hotel')}</th><th>{t('reports.date')}</th><th>{t('reports.occ')}</th><th>{t('reports.sold')}</th><th>{t('reports.adr')}</th><th>{t('reports.revpar')}</th><th>{t('reports.room_rev')}</th><th>{t('reports.total_rev')}</th><th>{t('reports.arr_dep')}</th></tr></thead>
                    <tbody>
                        {data.hotels.map((h) => (
                            <tr key={h.hotel.id} className="border-t">
                                <td className="p-2 font-medium">{h.hotel.name}<div className="text-xs text-slate-500">{h.hotel.city}</div></td>
                                <td>{h.business_date ? formatDate(h.business_date) : '—'}</td>
                                <td>{h.occupancy_percent}%</td><td>{h.rooms_sold}/{h.rooms_available}</td>
                                <td>{formatCurrency(h.adr ?? 0)}</td><td>{formatCurrency(h.revpar ?? 0)}</td>
                                <td>{formatCurrency(h.room_revenue ?? 0)}</td><td>{formatCurrency(h.total_revenue ?? 0)}</td>
                                <td>{h.arrivals}/{h.departures}</td>
                            </tr>
                        ))}
                        <tr className="border-t bg-slate-50 font-semibold">
                            <td className="p-2">{t('reports.network_total')}</td><td />
                            <td>{data.total.occupancy_percent}%</td><td>{data.total.rooms_sold}/{data.total.rooms_available}</td>
                            <td>{formatCurrency(data.total.adr)}</td><td>{formatCurrency(data.total.revpar)}</td>
                            <td>{formatCurrency(data.total.room_revenue)}</td><td>{formatCurrency(data.total.total_revenue)}</td>
                            <td>{data.total.arrivals}/{data.total.departures}</td>
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
                    <div className="card h-64 p-4">
                        <ResponsiveContainer>
                            <BarChart data={rows}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                <XAxis dataKey="channel" tick={{ fontSize: 13 }} />
                                <YAxis tickFormatter={short} tick={{ fontSize: 13 }} />
                                <Tooltip formatter={(v) => formatCurrency(Number(v))} />
                                <Bar dataKey="revenue" name={t('reports.revenue')} fill="#1a3a6b" />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="card overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.channel')}</th><th>{t('reports.bookings')}</th><th>{t('reports.room_nights')}</th><th>{t('reports.revenue')}</th><th>{t('reports.adr')}</th><th>{t('reports.share')}</th></tr></thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.channel} className="border-t">
                                        <td className="p-2 font-medium capitalize">{r.channel.replace(/_/g, ' ')}</td><td>{r.bookings}</td><td>{r.room_nights}</td>
                                        <td>{formatCurrency(r.revenue)}</td><td>{formatCurrency(r.room_nights ? Math.round(r.revenue / r.room_nights) : 0)}</td>
                                        <td>{total ? ((r.revenue / total) * 100).toFixed(1) : 0}%</td>
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
                <thead><tr className="text-left text-slate-500"><th className="p-2">{t('reports.date')}</th><th>{t('reports.status')}</th><th>{t('reports.closed_at')}</th><th>{t('reports.occ')}</th><th>{t('reports.adr')}</th><th>{t('reports.revpar')}</th><th>{t('reports.room_rev')}</th><th>{t('reports.total_rev')}</th></tr></thead>
                <tbody>
                    {(data?.data ?? []).map((d) => {
                        const s = parse(d.summary);
                        return (
                            <tr key={d.id} className="border-t">
                                <td className="p-2">{formatDate(d.date)}</td>
                                <td><span className={d.status === 'closed' ? 'badge-slate' : 'badge-green'}>{d.status}</span></td>
                                <td>{d.closed_at ? formatDateTime(d.closed_at) : '—'}</td>
                                <td>{s.occupancy_percent != null ? `${s.occupancy_percent}%` : '—'}</td>
                                <td>{s.adr != null ? formatCurrency(num(s.adr)) : '—'}</td>
                                <td>{s.revpar != null ? formatCurrency(num(s.revpar)) : '—'}</td>
                                <td>{s.room_revenue != null ? formatCurrency(num(s.room_revenue)) : '—'}</td>
                                <td>{s.total_revenue != null ? formatCurrency(num(s.total_revenue)) : '—'}</td>
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
