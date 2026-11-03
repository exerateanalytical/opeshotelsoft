import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Conflict { id: number; side: string; table_name: string; row_id: number; rule: string; detail: string | null; created_at: string }
interface Node {
    id: number; hotel_id: number; name: string; status: string; push_cursor: number; pull_cursor: number;
    last_push_at: string | null; last_pull_at: string | null; lag: number;
}
interface EdgeInfo {
    node_id: number | null; hotel_id: number | null; cloud_url: string; push_cursor: number; pull_cursor: number; pending: number;
    last_sync_at: string | null; last_attempt_at: string | null; last_error: string | null; number_range_start: number;
}
interface Status { mode: 'edge' | 'cloud'; hotel_id: number | null; edge?: EdgeInfo; nodes?: Node[]; conflicts: Conflict[] }

const when = (d: string | null) => (d ? new Date(d).toLocaleString() : '—');

export default function EdgeStatus() {
    const t = useT();
    const status = useApi<Status>('edge/status');
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [secret, setSecret] = useState<{ id: number; secret: string } | null>(null);

    async function run<T>(fn: () => Promise<T>, ok: (r: T) => string) {
        setBusy(true);
        setMessage(null);
        try {
            const r = await fn();
            setMessage({ ok: true, text: ok(r) });
            status.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : t('edge.error') });
        } finally {
            setBusy(false);
        }
    }

    const s = status.data;

    return (
        <AppLayout title={t('edge.title')} subtitle={t('edge.subtitle')}>
            <Head title={t('edge.title')} />
            {message && <p className={`mb-3 text-sm ${message.ok ? 'text-emerald-700' : 'text-red-700'}`}>{message.text}</p>}
            {status.error && <p className="text-sm text-red-700">{status.error.message}</p>}
            {status.loading && !s && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}

            {s?.mode === 'edge' && s.edge && (
                <div className="card p-4 mb-4">
                    <div className="flex items-center justify-between mb-3">
                        <h2 className="font-semibold text-slate-900">{t('edge.this_node')} #{s.edge.node_id}</h2>
                        <button type="button" className="btn-primary" disabled={busy} onClick={() => run(() => api('edge/sync', { method: 'POST' }), () => t('edge.synced'))}>
                            {busy ? t('edge.syncing') : t('edge.sync_now')}
                        </button>
                    </div>
                    <dl className="grid gap-3 sm:grid-cols-3 text-sm">
                        <div><dt className="text-slate-500">{t('edge.cloud_url')}</dt><dd className="break-all">{s.edge.cloud_url || '—'}</dd></div>
                        <div><dt className="text-slate-500">{t('edge.last_sync')}</dt><dd>{when(s.edge.last_sync_at)}</dd></div>
                        <div><dt className="text-slate-500">{t('edge.pending')}</dt><dd><span className={s.edge.pending ? 'badge-yellow' : 'badge-green'}>{s.edge.pending}</span></dd></div>
                        <div><dt className="text-slate-500">{t('edge.push_cursor')}</dt><dd>{s.edge.push_cursor}</dd></div>
                        <div><dt className="text-slate-500">{t('edge.pull_cursor')}</dt><dd>{s.edge.pull_cursor}</dd></div>
                        <div><dt className="text-slate-500">{t('edge.number_range')}</dt><dd>{s.edge.number_range_start + 1}+</dd></div>
                    </dl>
                    {s.edge.last_error && <p className="mt-3 text-sm text-red-700">{t('edge.last_error')}: {s.edge.last_error}</p>}
                </div>
            )}

            {s?.mode === 'cloud' && (
                <div className="card mb-4 overflow-x-auto">
                    <div className="p-4 flex flex-wrap gap-2 items-end">
                        <div>
                            <label className="label" htmlFor="node-name">{t('edge.node_name')}</label>
                            <input id="node-name" className="input" value={name} onChange={(e) => setName(e.target.value)} />
                        </div>
                        <button type="button" className="btn-primary" disabled={busy || !name}
                            onClick={() => run(() => api<{ id: number; secret: string }>('edge/nodes', { method: 'POST', body: JSON.stringify({ name }) }), (r) => { setSecret(r); setName(''); return t('edge.registered'); })}>
                            {t('edge.register')}
                        </button>
                    </div>
                    {secret && (
                        <p className="px-4 pb-4 text-sm">{t('edge.secret_once')} <code className="break-all">EDGE_NODE_ID={secret.id} EDGE_SECRET={secret.secret}</code></p>
                    )}
                    <table className="table-auto w-full text-sm">
                        <thead>
                            <tr>
                                <th>{t('edge.node')}</th><th>{t('edge.status')}</th><th>{t('edge.last_push')}</th><th>{t('edge.last_pull')}</th>
                                <th>{t('edge.lag')}</th><th className="text-right">{t('edge.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {s.nodes?.length === 0 && <tr><td colSpan={6} className="text-slate-500">{t('edge.no_nodes')}</td></tr>}
                            {s.nodes?.map((n) => (
                                <tr key={n.id}>
                                    <td>#{n.id} {n.name}</td>
                                    <td><span className={n.status === 'active' ? 'badge-green' : 'badge-slate'}>{t(`edge.state.${n.status}`)}</span></td>
                                    <td className="whitespace-nowrap">{when(n.last_push_at)}</td>
                                    <td className="whitespace-nowrap">{when(n.last_pull_at)}</td>
                                    <td><span className={n.lag ? 'badge-yellow' : 'badge-green'}>{n.lag}</span></td>
                                    <td className="text-right">
                                        {n.status === 'active' && (
                                            <button type="button" className="btn-secondary" disabled={busy}
                                                onClick={() => run(() => api(`edge/nodes/${n.id}/revoke`, { method: 'POST' }), () => t('edge.revoked'))}>
                                                {t('edge.revoke')}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {s && (
                <div className="card overflow-x-auto">
                    <h2 className="p-4 font-semibold text-slate-900">{t('edge.conflicts')}</h2>
                    <table className="table-auto w-full text-sm">
                        <thead>
                            <tr><th>{t('edge.date')}</th><th>{t('edge.side')}</th><th>{t('edge.table')}</th><th>{t('edge.rule')}</th><th>{t('edge.detail')}</th></tr>
                        </thead>
                        <tbody>
                            {s.conflicts.length === 0 && <tr><td colSpan={5} className="text-slate-500">{t('edge.no_conflicts')}</td></tr>}
                            {s.conflicts.map((c) => (
                                <tr key={c.id}>
                                    <td className="whitespace-nowrap">{when(c.created_at)}</td>
                                    <td>{t(`edge.side.${c.side}`)}</td>
                                    <td>{c.table_name} #{c.row_id}</td>
                                    <td>{t(`edge.rule.${c.rule}`)}</td>
                                    <td className="break-all">{c.detail}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
