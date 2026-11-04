import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, ChevronRight, Eye, Loader2, Plug, Plus, Power, RefreshCw, Save, Send, ShieldAlert, Trash2, X } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Provider { code: string; name: string; kind: string; protocol: string; credential_fields: string[] }
interface RoomMap { room_type_id: number; external_room_id: string; max_allotment: number | null }
interface RateMap { rate_plan_id: number; external_rate_id: string; markup_percent: number }
interface Connection {
    id: number;
    provider: string;
    name: string;
    property_code: string | null;
    environment: 'sandbox' | 'production';
    status: 'draft' | 'testing' | 'active' | 'suspended';
    push_rates: boolean;
    push_availability: boolean;
    receive_reservations: boolean;
    sync_days: number;
    last_push_at: string | null;
    credential_keys: Record<string, string> | [];
    has_credentials: boolean;
    dry_run: boolean;
    inbound_url: string;
    room_mappings: RoomMap[];
    rate_mappings: RateMap[];
}
interface Named { id: number; code: string; name: string }
interface Log { id: number; direction: string; kind: string; status: string; http_status: number | null; request: string | null; response: string | null; error: string | null; created_at: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

type TFn = (k: string, p?: Record<string, string | number>) => string;
/** Translated label for an API value; falls back to the raw value when no key exists. */
const lbl = (t: TFn, key: string, raw: string) => { const v = t(key); return v === key ? raw.replace(/_/g, ' ') : v; };
const SECRET = /password|secret|key|token/;

function errText(e: unknown): string {
    if (e instanceof ApiError) {
        const first = e.problem.errors ? Object.values(e.problem.errors)[0]?.[0] : null;
        return first ?? e.message;
    }
    return String(e);
}
function list<T>(d: T[] | { data: T[] } | null): T[] {
    return !d ? [] : Array.isArray(d) ? d : d.data;
}
function pretty(s: string | null): string {
    if (!s) return '';
    const i = s.indexOf('\n\n');
    const head = i >= 0 ? s.slice(0, i + 2) : '';
    const body = i >= 0 ? s.slice(i + 2) : s;
    try {
        return head + JSON.stringify(JSON.parse(body), null, 2);
    } catch {
        return s;
    }
}

function Banner({ error }: { error: string | null }) {
    return error ? <Alert variant="danger">{error}</Alert> : null;
}

function DryRunBadge() {
    const t = useT();
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
            <ShieldAlert className="h-3 w-3" /> {t('channels.dry_run_badge')}
        </span>
    );
}

export default function ChannelsIndex() {
    const t = useT();
    const providers = useApi<Provider[]>('channels/providers');
    const conns = useApi<Connection[]>('channels/connections');
    const [selected, setSelected] = useState<string | null>(null);

    const byProvider = new Map((conns.data ?? []).map((c) => [c.provider, c]));
    const provider = providers.data?.find((p) => p.code === selected) ?? null;
    const conn = selected ? byProvider.get(selected) ?? null : null;

    return (
        <AppLayout title={t('channels.title')} subtitle={t('channels.subtitle')}>
            <Head title={t('channels.short')} />
            <div className="space-y-6">
                <Banner error={providers.error?.message ?? conns.error?.message ?? null} />
                {(providers.loading || conns.loading) && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {(providers.data ?? []).map((p) => {
                        const c = byProvider.get(p.code);
                        return (
                            <button
                                key={p.code}
                                onClick={() => setSelected(p.code === selected ? null : p.code)}
                                className={cn('card p-4 text-left transition', selected === p.code && 'outline-2 outline-blue-700')}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <div className="font-semibold">{p.name}</div>
                                        <div className="text-xs text-slate-500">{lbl(t, `channels.kind.${p.kind}`, p.kind)} · {p.protocol}</div>
                                    </div>
                                    {c ? <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" /> : <Plug className="h-5 w-5 shrink-0 text-slate-500" />}
                                </div>
                                <div className="mt-3 flex flex-wrap gap-1 text-xs">
                                    {c ? (
                                        <>
                                            <span className={cn('rounded-full px-2 py-0.5', c.status === 'active' ? 'bg-green-100 text-green-800' : c.status === 'suspended' ? 'bg-slate-200 text-slate-700' : 'bg-blue-100 text-blue-800')}>{lbl(t, `channels.status.${c.status}`, c.status)}</span>
                                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{lbl(t, `channels.env.${c.environment}`, c.environment)}</span>
                                            {c.dry_run && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">{t('channels.dry_run')}</span>}
                                        </>
                                    ) : (
                                        <span className="text-slate-500">{t('channels.not_connected')}</span>
                                    )}
                                </div>
                            </button>
                        );
                    })}
                </div>

                {provider && (
                    <ConnectionPanel
                        key={provider.code + (conn?.id ?? 'new')}
                        provider={provider}
                        conn={conn}
                        onChanged={conns.reload}
                        onClose={() => setSelected(null)}
                    />
                )}
            </div>
        </AppLayout>
    );
}

function ConnectionPanel({ provider, conn, onChanged, onClose }: { provider: Provider; conn: Connection | null; onChanged: () => void; onClose: () => void }) {
    const t = useT();
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    async function run(label: string, fn: () => Promise<void>) {
        setBusy(label);
        setError(null);
        setNotice(null);
        try {
            await fn();
        } catch (e) {
            setError(errText(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className="card space-y-5 p-4 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold">{provider.name}</h2>
                    {conn?.dry_run && <DryRunBadge />}
                </div>
                <button className="btn-secondary" onClick={onClose} aria-label={t('channels.close')}><X className="h-4 w-4" /></button>
            </div>
            <Banner error={error} />
            {notice && <Alert variant="success">{notice}</Alert>}

            <ConnectForm provider={provider} conn={conn} busy={busy} run={run} onSaved={(msg) => { setNotice(msg); onChanged(); }} />

            {conn && (
                <>
                    <div className="flex flex-wrap gap-2 border-t pt-4">
                        <button
                            className="btn-secondary"
                            disabled={!!busy}
                            onClick={() => run('toggle', async () => {
                                const next = conn.status === 'suspended' ? 'active' : 'suspended';
                                await api(`channels/connections/${conn.id}`, { method: 'PATCH', body: JSON.stringify({ status: next }) });
                                setNotice(next === 'active' ? t('channels.enabled') : t('channels.disabled'));
                                onChanged();
                            })}
                        >
                            <Power className="h-4 w-4" /> {conn.status === 'suspended' ? t('channels.enable') : t('channels.disable')}
                        </button>
                        <button
                            className="btn-secondary"
                            disabled={!!busy}
                            onClick={() => run('test', async () => {
                                const r = await api<{ status: string; http_status?: number | null; error?: string | null }>(`channels/connections/${conn.id}/test`, { method: 'POST' });
                                if (r.status === 'not_applicable') setNotice(t('channels.test_na'));
                                else if (r.status === 'failed') setError(`${t('channels.test_failed')}${r.http_status ? ` (HTTP ${r.http_status})` : ''} : ${r.error ?? t('channels.unknown_error')}`);
                                else setNotice(r.status === 'dry_run' ? t('channels.test_dry_run') : t('channels.test_ok', { status: r.http_status ?? '' }));
                                onChanged();
                            })}
                        >
                            {busy === 'test' ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />} {t('channels.test')}
                        </button>
                        <button
                            className="btn-primary"
                            disabled={!!busy}
                            onClick={() => run('sync', async () => {
                                await api(`channels/connections/${conn.id}/sync`, { method: 'POST', body: JSON.stringify({}) });
                                setNotice(t('channels.sync_queued', { days: conn.sync_days }));
                            })}
                        >
                            {busy === 'sync' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />} {t('channels.push_now')}
                        </button>
                    </div>
                    <div className="text-xs text-slate-500 break-all">{t('channels.inbound_url')} : <code>{conn.inbound_url}</code>{conn.last_push_at && <> · {t('channels.last_push', { date: formatDateTime(conn.last_push_at) })}</>}</div>
                    <MappingEditor conn={conn} onSaved={() => { setNotice(t('channels.mappings_saved')); onChanged(); }} />
                    <Preview conn={conn} />
                    <SyncLogs conn={conn} />
                </>
            )}
        </div>
    );
}

function ConnectForm({ provider, conn, busy, run, onSaved }: {
    provider: Provider; conn: Connection | null; busy: string | null;
    run: (label: string, fn: () => Promise<void>) => Promise<void>; onSaved: (msg: string) => void;
}) {
    const t = useT();
    const stored = (conn?.credential_keys && !Array.isArray(conn.credential_keys) ? conn.credential_keys : {}) as Record<string, string>;
    const [name, setName] = useState(conn?.name ?? provider.name);
    const [propertyCode, setPropertyCode] = useState(conn?.property_code ?? '');
    const [environment, setEnvironment] = useState(conn?.environment ?? 'sandbox');
    const [status, setStatus] = useState(conn?.status ?? 'testing');
    const [syncDays, setSyncDays] = useState(conn?.sync_days ?? 365);
    const [flags, setFlags] = useState({
        push_rates: conn?.push_rates ?? true, push_availability: conn?.push_availability ?? true, receive_reservations: conn?.receive_reservations ?? true,
    });
    const [creds, setCreds] = useState<Record<string, string>>({});

    function save() {
        return run('save', async () => {
            const credentials = Object.fromEntries(Object.entries(creds).filter(([, v]) => v !== ''));
            const body: Record<string, unknown> = { name, property_code: propertyCode || null, environment, status, sync_days: syncDays, ...flags };
            if (Object.keys(credentials).length) body.credentials = credentials;
            if (conn) {
                await api(`channels/connections/${conn.id}`, { method: 'PATCH', body: JSON.stringify(body) });
            } else {
                await api('channels/connections', { method: 'POST', body: JSON.stringify({ provider: provider.code, ...body }) });
            }
            setCreds({});
            onSaved(conn ? t('channels.updated') : t('channels.connected', { name: provider.name }));
        });
    }

    return (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save(); }}>
            <h3 className="font-medium">{conn ? t('channels.settings') : t('channels.connect')}</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label className="label">{t('channels.display_name')}<input className="input mt-1 w-full" value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} /></label>
                <label className="label">{t('channels.property_code')}<input className="input mt-1 w-full" value={propertyCode} onChange={(e) => setPropertyCode(e.target.value)} maxLength={64} /></label>
                <label className="label">{t('channels.environment')}
                    <select className="select mt-1 w-full" value={environment} onChange={(e) => setEnvironment(e.target.value as Connection['environment'])}>
                        <option value="sandbox">{t('channels.env.sandbox')}</option><option value="production">{t('channels.env.production')}</option>
                    </select>
                </label>
                <label className="label">{t('res.status')}
                    <select className="select mt-1 w-full" value={status} onChange={(e) => setStatus(e.target.value as Connection['status'])}>
                        {['draft', 'testing', 'active', 'suspended'].map((s) => <option key={s} value={s}>{t(`channels.status.${s}`)}</option>)}
                    </select>
                </label>
                <label className="label">{t('channels.sync_days')}<input type="number" min={30} max={730} className="input mt-1 w-full" value={syncDays} onChange={(e) => setSyncDays(Number(e.target.value))} /></label>
            </div>
            <div className="flex flex-wrap gap-4 text-sm">
                {(['push_rates', 'push_availability', 'receive_reservations'] as const).map((k) => (
                    <label key={k} className="flex items-center gap-2">
                        <input type="checkbox" checked={flags[k]} onChange={(e) => setFlags({ ...flags, [k]: e.target.checked })} />
                        {t(`channels.flag.${k}`)}
                    </label>
                ))}
            </div>
            <div>
                <div className="mb-2 flex flex-wrap items-center gap-2">
                    <h4 className="text-sm font-medium">{t('channels.credentials')}</h4>
                    {(!conn || !conn.has_credentials) && <DryRunBadge />}
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {provider.credential_fields.map((f) => (
                        <label key={f} className="text-sm">{lbl(t, `channels.field.${f}`, f)}
                            <input
                                className="input mt-1 w-full"
                                type={SECRET.test(f) ? 'password' : 'text'}
                                autoComplete="new-password"
                                aria-label={stored[f] ? t('channels.stored', { value: stored[f] }) : t('channels.not_set')} placeholder={stored[f] ? t('channels.stored', { value: stored[f] }) : t('channels.not_set')}
                                value={creds[f] ?? ''}
                                onChange={(e) => setCreds({ ...creds, [f]: e.target.value })}
                                maxLength={500}
                            />
                        </label>
                    ))}
                </div>
                <p className="mt-1 text-xs text-slate-500">{t('channels.secrets_hint')}</p>
            </div>
            <button type="submit" className="btn-primary" disabled={!!busy}>
                {busy === 'save' ? <Loader2 className="h-4 w-4 animate-spin" /> : conn ? <Save className="h-4 w-4" /> : <Plug className="h-4 w-4" />} {conn ? t('channels.save_settings') : t('channels.connect')}
            </button>
        </form>
    );
}

function MappingEditor({ conn, onSaved }: { conn: Connection; onSaved: () => void }) {
    const t = useT();
    const roomTypes = useApi<Named[] | { data: Named[] }>('room-types?per_page=200');
    const ratePlans = useApi<Named[] | { data: Named[] }>('rate-plans?per_page=200');
    const [rooms, setRooms] = useState<RoomMap[]>(conn.room_mappings ?? []);
    const [rates, setRates] = useState<RateMap[]>(conn.rate_mappings ?? []);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const types = list(roomTypes.data);
    const plans = list(ratePlans.data);

    async function save() {
        setSaving(true);
        setError(null);
        try {
            await api(`channels/connections/${conn.id}/mappings`, { method: 'PUT', body: JSON.stringify({ rooms, rates }) });
            onSaved();
        } catch (e) {
            setError(errText(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-3 border-t pt-4">
            <h3 className="font-medium">{t('channels.mappings')}</h3>
            <Banner error={error ?? roomTypes.error?.message ?? ratePlans.error?.message ?? null} />
            <div className="overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th className="py-1 pr-2">{t('channels.our_room_type')}</th><th className="pr-2">{t('channels.channel_room')}</th><th className="pr-2">{t('channels.max_allotment')}</th><th /></tr></thead>
                    <tbody>
                        {rooms.map((r, i) => (
                            <tr key={i}>
                                <td className="py-1 pr-2">
                                    <select className="select w-full min-w-[10rem]" value={r.room_type_id || ''} onChange={(e) => setRooms(rooms.map((x, j) => j === i ? { ...x, room_type_id: Number(e.target.value) } : x))} aria-label={t('channels.choose')}>
                                        <option value="">{t('channels.choose')}</option>
                                        {types.map((rt) => <option key={rt.id} value={rt.id}>{rt.name} ({rt.code})</option>)}
                                    </select>
                                </td>
                                <td className="pr-2"><input className="input w-full min-w-[8rem]" value={r.external_room_id} onChange={(e) => setRooms(rooms.map((x, j) => j === i ? { ...x, external_room_id: e.target.value } : x))} /></td>
                                <td className="pr-2"><input type="number" min={0} className="input w-24" value={r.max_allotment ?? ''} onChange={(e) => setRooms(rooms.map((x, j) => j === i ? { ...x, max_allotment: e.target.value === '' ? null : Number(e.target.value) } : x))} /></td>
                                <td><button className="btn-secondary" onClick={() => setRooms(rooms.filter((_, j) => j !== i))} aria-label={t('groups.remove')}><Trash2 className="h-4 w-4" /></button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <button className="btn-secondary" onClick={() => setRooms([...rooms, { room_type_id: 0, external_room_id: '', max_allotment: null }])}><Plus className="h-4 w-4" /> {t('channels.add_room_map')}</button>

            <div className="overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th className="py-1 pr-2">{t('channels.our_rate_plan')}</th><th className="pr-2">{t('channels.channel_rate')}</th><th className="pr-2">{t('channels.markup')}</th><th /></tr></thead>
                    <tbody>
                        {rates.map((r, i) => (
                            <tr key={i}>
                                <td className="py-1 pr-2">
                                    <select className="select w-full min-w-[10rem]" value={r.rate_plan_id || ''} onChange={(e) => setRates(rates.map((x, j) => j === i ? { ...x, rate_plan_id: Number(e.target.value) } : x))} aria-label={t('channels.choose')}>
                                        <option value="">{t('channels.choose')}</option>
                                        {plans.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.code})</option>)}
                                    </select>
                                </td>
                                <td className="pr-2"><input className="input w-full min-w-[8rem]" value={r.external_rate_id} onChange={(e) => setRates(rates.map((x, j) => j === i ? { ...x, external_rate_id: e.target.value } : x))} /></td>
                                <td className="pr-2"><input type="number" step="0.01" min={-50} max={100} className="input w-24" value={r.markup_percent} onChange={(e) => setRates(rates.map((x, j) => j === i ? { ...x, markup_percent: Number(e.target.value) } : x))} /></td>
                                <td><button className="btn-secondary" onClick={() => setRates(rates.filter((_, j) => j !== i))} aria-label={t('groups.remove')}><Trash2 className="h-4 w-4" /></button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap gap-2">
                <button className="btn-secondary" onClick={() => setRates([...rates, { rate_plan_id: 0, external_rate_id: '', markup_percent: 0 }])}><Plus className="h-4 w-4" /> {t('channels.add_rate_map')}</button>
                <button className="btn-primary" onClick={save} disabled={saving}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />} {t('channels.save_mappings')}</button>
            </div>
        </div>
    );
}

function Preview({ conn }: { conn: Connection }) {
    const t = useT();
    const today = new Date().toISOString().slice(0, 10);
    const week = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10);
    const [from, setFrom] = useState(today);
    const [to, setTo] = useState(week);
    const [data, setData] = useState<unknown>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            setData(await api(`channels/connections/${conn.id}/preview?from=${from}&to=${to}`));
        } catch (e) {
            setError(errText(e));
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="space-y-3 border-t pt-4">
            <h3 className="font-medium">{t('channels.preview_title')}</h3>
            <div className="flex flex-wrap items-end gap-2">
                <label className="label">{t('res.seasons.from')}<input type="date" className="input mt-1 block" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                <label className="label">{t('res.agents.to')}<input type="date" className="input mt-1 block" value={to} onChange={(e) => setTo(e.target.value)} /></label>
                <button className="btn-secondary" onClick={load} disabled={loading}>{loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Eye className="h-4 w-4" />} {t('channels.preview')}</button>
                {data !== null && <button className="btn-secondary" onClick={() => setData(null)}><X className="h-4 w-4" /> {t('channels.hide')}</button>}
            </div>
            <Banner error={error} />
            {data !== null && <pre className="max-h-96 overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100">{JSON.stringify(data, null, 2)}</pre>}
        </div>
    );
}

function SyncLogs({ conn }: { conn: Connection }) {
    const t = useT();
    const [page, setPage] = useState(1);
    const logs = useApi<Page<Log>>(`channels/connections/${conn.id}/logs?page=${page}`);
    const [open, setOpen] = useState<number | null>(null);
    useEffect(() => setOpen(null), [page]);
    const d = logs.data;

    return (
        <div className="space-y-3 border-t pt-4">
            <div className="flex items-center justify-between">
                <h3 className="font-medium">{t('channels.sync_log')}</h3>
                <button className="btn-secondary" onClick={logs.reload}><RefreshCw className={cn('h-4 w-4', logs.loading && 'animate-spin')} /> {t('channels.refresh')}</button>
            </div>
            <Banner error={logs.error?.message ?? null} />
            <div className="overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th className="py-1 pr-2" /><th className="pr-2">{t('channels.log.when')}</th><th className="pr-2">{t('channels.log.direction')}</th><th className="pr-2">{t('channels.log.kind')}</th><th className="pr-2">{t('res.status')}</th><th className="pr-2">HTTP</th><th>{t('channels.log.error')}</th></tr></thead>
                    <tbody>
                        {(d?.data ?? []).map((l) => (
                            <Fragment key={l.id}>
                                <tr className="cursor-pointer border-t hover:bg-slate-50" onClick={() => setOpen(open === l.id ? null : l.id)}>
                                    <td className="py-1 pr-2">{open === l.id ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}</td>
                                    <td className="whitespace-nowrap pr-2">{formatDateTime(l.created_at)}</td>
                                    <td className="pr-2">{lbl(t, `channels.direction.${l.direction}`, l.direction)}</td>
                                    <td className="pr-2">{l.kind}</td>
                                    <td className="pr-2">
                                        <span className={cn('rounded-full px-2 py-0.5 text-xs', ['sent', 'processed'].includes(l.status) ? 'bg-green-100 text-green-800' : ['failed', 'rejected'].includes(l.status) ? 'bg-red-100 text-red-800' : l.status === 'dry_run' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700')}>{lbl(t, `channels.log_status.${l.status}`, l.status)}</span>
                                    </td>
                                    <td className="pr-2">{l.http_status ?? '—'}</td>
                                    <td className="max-w-[16rem] truncate text-red-700">{l.error}</td>
                                </tr>
                                {open === l.id && (
                                    <tr key={`${l.id}-x`}>
                                        <td colSpan={7} className="pb-3">
                                            <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">
                                                <div><div className="mb-1 text-xs font-medium text-slate-500">{t('channels.log.request')}</div><pre className="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-md bg-slate-900 p-3 text-xs text-slate-100">{pretty(l.request) || '—'}</pre></div>
                                                <div><div className="mb-1 text-xs font-medium text-slate-500">{t('channels.log.response')}</div><pre className="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-md bg-slate-900 p-3 text-xs text-slate-100">{pretty(l.response) || (l.status === 'dry_run' ? t('channels.log.not_sent') : '—')}</pre></div>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                        {d && d.data.length === 0 && <tr><td colSpan={7} className="py-4 text-center text-slate-500">{t('channels.log.empty')}</td></tr>}
                    </tbody>
                </table>
            </div>
            {d && d.last_page > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <span className="text-slate-500">{t('channels.log.page', { page: d.current_page, last: d.last_page, total: d.total })}</span>
                    <div className="flex gap-2">
                        <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                        <button className="btn-secondary" disabled={page >= d.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                    </div>
                </div>
            )}
        </div>
    );
}
