import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BookOpen, Plus, RotateCcw, Trash2, Webhook, Radio } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { api, useApi, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Subscription {
    id: number;
    url: string;
    event_types: string[];
    hotel_id: number | null;
    active: boolean;
    created_at?: string;
}
interface Delivery {
    id: number;
    status: string;
    attempts: number;
    event_id?: string | number;
    response_code?: number | null;
    created_at?: string;
}
interface StoredEvent {
    id?: string | number;
    type: string;
    occurred_at?: string;
    hotel_id?: number | null;
    data?: unknown;
    [k: string]: unknown;
}

function errText(e: unknown) {
    if (e instanceof ApiError) {
        const fields = e.problem.errors ? Object.values(e.problem.errors).flat().join(' ') : '';
        return fields || e.message;
    }
    return String(e);
}

function Banner({ msg }: { msg: string | null }) {
    return msg ? <Alert variant="danger">{msg}</Alert> : null;
}

function Deliveries({ sub }: { sub: Subscription }) {
    const t = useT();
    const { data, error, loading, reload } = useApi<{ data: Delivery[] }>(`webhooks/${sub.id}/deliveries`);
    const [err, setErr] = useState<string | null>(null);
    const replay = async (d: Delivery) => {
        setErr(null);
        try {
            await api(`webhooks/${sub.id}/deliveries/${d.id}/replay`, { method: 'POST' });
            reload();
        } catch (e) {
            setErr(errText(e));
        }
    };
    return (
        <div className="mt-3 border-t border-slate-100 pt-3 space-y-2">
            <Banner msg={err ?? (error ? errText(error) : null)} />
            {loading && <p className="text-xs text-slate-500">{t('dev.loading_deliveries')}</p>}
            {data && data.data.length === 0 && <p className="text-xs text-slate-500">{t('dev.no_deliveries')}</p>}
            {data && data.data.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="table-auto w-full text-xs">
                        <thead><tr className="text-left text-slate-500"><th className="py-1 pr-3">#</th><th className="pr-3">{t('dev.status')}</th><th className="pr-3">{t('dev.attempts')}</th><th className="pr-3">HTTP</th><th className="pr-3">{t('dev.created')}</th><th /></tr></thead>
                        <tbody>
                            {data.data.map((d) => (
                                <tr key={d.id} className="border-t border-slate-100">
                                    <td className="py-1 pr-3">{d.id}</td>
                                    <td className="pr-3">{d.status}</td>
                                    <td className="pr-3">{d.attempts}</td>
                                    <td className="pr-3">{d.response_code ?? '—'}</td>
                                    <td className="pr-3">{d.created_at ? formatDate(d.created_at) : '—'}</td>
                                    <td className="text-right">
                                        <button className="btn-secondary !py-1 !px-2 inline-flex items-center gap-1" onClick={() => replay(d)}>
                                            <RotateCcw className="w-3 h-3" /> {t('dev.replay')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function Webhooks() {
    const t = useT();
    const { data, error, loading, reload } = useApi<Subscription[]>('webhooks');
    const [url, setUrl] = useState('');
    const [types, setTypes] = useState('*');
    const [err, setErr] = useState<string | null>(null);
    const [secret, setSecret] = useState<string | null>(null);
    const [open, setOpen] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const create = async (e: React.FormEvent) => {
        e.preventDefault();
        setErr(null);
        setSaving(true);
        try {
            const res = await api<Subscription & { secret: string }>('webhooks', {
                method: 'POST',
                body: JSON.stringify({ url, event_types: types.split(',').map((x) => x.trim()).filter(Boolean) }),
            });
            setSecret(res.secret);
            setUrl('');
            reload();
        } catch (e) {
            setErr(errText(e));
        } finally {
            setSaving(false);
        }
    };
    const remove = async (s: Subscription) => {
        if (!confirm(t('dev.confirm_disable', { url: s.url }))) return;
        setErr(null);
        try {
            await api(`webhooks/${s.id}`, { method: 'DELETE' });
            reload();
        } catch (e) {
            setErr(errText(e));
        }
    };

    return (
        <section className="card p-5 space-y-4">
            <h2 className="flex items-center gap-2 font-semibold text-slate-900"><Webhook className="w-4 h-4" /> {t('dev.webhooks')}</h2>
            <Banner msg={err ?? (error ? errText(error) : null)} />
            {secret && (
                <Alert variant="warning" className="break-all">
                    {t('dev.signing_secret')} <code className="font-mono">{secret}</code>
                    <button className="ml-3 underline text-xs" onClick={() => setSecret(null)}>{t('dev.dismiss')}</button>
                </Alert>
            )}
            <form onSubmit={create} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] items-end">
                <div>
                    <label className="block text-xs font-medium text-slate-600 mb-1">{t('dev.endpoint_url')}</label>
                    <input className="input w-full" type="url" required placeholder="https://example.com/hooks" aria-label={t('dev.endpoint_url')} value={url} onChange={(e) => setUrl(e.target.value)} />
                </div>
                <div>
                    <label className="block text-xs font-medium text-slate-600 mb-1">{t('dev.event_types')}</label>
                    <input className="input w-full" required aria-label={t('dev.event_types')} value={types} onChange={(e) => setTypes(e.target.value)} />
                </div>
                <button className="btn-primary inline-flex items-center gap-1" disabled={saving}><Plus className="w-4 h-4" /> {t('common.add')}</button>
            </form>
            {loading && <p className="text-sm text-slate-500">{t('common.loading')}</p>}
            {data && data.length === 0 && <p className="text-sm text-slate-500">{t('dev.no_webhooks')}</p>}
            <ul className="space-y-3">
                {data?.map((s) => (
                    <li key={s.id} className="border border-slate-200 rounded-lg p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-sm break-all flex-1 min-w-0">{s.url}</span>
                            <span className={s.active ? 'text-xs text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full' : 'text-xs text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full'}>
                                {s.active ? t('dev.active') : t('dev.disabled')}
                            </span>
                            <button className="btn-secondary !py-1 !px-2 text-xs" onClick={() => setOpen(open === s.id ? null : s.id)}>
                                {open === s.id ? t('dev.hide_deliveries') : t('dev.deliveries')}
                            </button>
                            {s.active && (
                                <button className="btn-ghost btn-icon btn-sm" aria-label={t('dev.disable')} onClick={() => remove(s)}><Trash2 className="w-4 h-4" /></button>
                            )}
                        </div>
                        <p className="text-xs text-slate-500 mt-1">{(s.event_types ?? []).join(', ')}</p>
                        {open === s.id && <Deliveries sub={s} />}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Events() {
    const t = useT();
    const [type, setType] = useState('');
    const [query, setQuery] = useState('');
    const [after, setAfter] = useState(0);
    const path = `events?limit=50&after=${after}${query ? `&type=${encodeURIComponent(query)}` : ''}`;
    const { data, error, loading } = useApi<{ data: StoredEvent[]; next_cursor: number | null }>(path);
    const [selected, setSelected] = useState<StoredEvent | null>(null);

    return (
        <section className="card p-5 space-y-4">
            <h2 className="flex items-center gap-2 font-semibold text-slate-900"><Radio className="w-4 h-4" /> {t('dev.event_browser')}</h2>
            <form className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); setAfter(0); setQuery(type); }}>
                <input className="input flex-1 min-w-[180px]" placeholder={t('dev.filter_ph')} aria-label={t('dev.filter_ph')} value={type} onChange={(e) => setType(e.target.value)} />
                <button className="btn-secondary">{t('common.filter')}</button>
            </form>
            <Banner msg={error ? errText(error) : null} />
            {loading && <p className="text-sm text-slate-500">{t('common.loading')}</p>}
            {data && data.data.length === 0 && <p className="text-sm text-slate-500">{t('dev.no_events')}</p>}
            {data && data.data.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="table-auto w-full text-sm">
                        <thead><tr className="text-left text-slate-500 text-xs"><th className="py-1 pr-3">{t('dev.type')}</th><th className="pr-3">{t('dev.occurred')}</th><th className="pr-3">{t('dev.hotel')}</th></tr></thead>
                        <tbody>
                            {data.data.map((ev, i) => (
                                <tr key={String(ev.id ?? i)} className="border-t border-slate-100 cursor-pointer hover:bg-slate-50" onClick={() => setSelected(ev)}>
                                    <td className="py-1 pr-3 font-mono text-xs">{ev.type}</td>
                                    <td className="pr-3 text-xs">{ev.occurred_at ? formatDate(ev.occurred_at) : '—'}</td>
                                    <td className="pr-3 text-xs">{ev.hotel_id ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="flex gap-2">
                {after > 0 && <button className="btn-secondary" onClick={() => setAfter(0)}>{t('dev.first_page')}</button>}
                {data?.next_cursor && data.data.length === 50 && <button className="btn-secondary" onClick={() => setAfter(data.next_cursor!)}>{t('common.next')}</button>}
            </div>
            {selected && (
                <div className="border border-slate-200 rounded-lg p-3">
                    <div className="flex justify-between mb-2"><span className="font-mono text-xs">{selected.type}</span><button className="text-xs underline" onClick={() => setSelected(null)}>{t('dev.close')}</button></div>
                    <pre className="text-xs bg-slate-50 p-2 rounded overflow-x-auto">{JSON.stringify(selected, null, 2)}</pre>
                </div>
            )}
        </section>
    );
}

export default function DeveloperIndex() {
    const t = useT();
    return (
        <AppLayout title={t('dev.title')} subtitle={t('dev.subtitle')}>
            <Head title={t('dev.head')} />
            <div className="max-w-5xl space-y-6">
                <section className="card p-5 flex flex-wrap items-center gap-3">
                    <BookOpen className="w-5 h-5 text-slate-500" />
                    <div className="flex-1 min-w-0">
                        <p className="font-semibold text-slate-900">{t('dev.api_ref')}</p>
                        <p className="text-xs text-slate-500">{t('dev.api_ref_hint')}</p>
                    </div>
                    <a href="/docs/api" target="_blank" rel="noreferrer" className="btn-primary">{t('dev.open_docs')}</a>
                    <a href="/docs/api.json" target="_blank" rel="noreferrer" className="btn-secondary">openapi.json</a>
                </section>
                <Webhooks />
                <Events />
            </div>
        </AppLayout>
    );
}
