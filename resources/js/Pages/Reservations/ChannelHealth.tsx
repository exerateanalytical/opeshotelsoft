import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Row {
    id: number; name: string; provider: string; health: 'ok' | 'warning' | 'error' | 'idle' | 'inactive'; last_push_at: string | null; last_pull_at: string | null;
    push_lag_minutes: number | null; pull_lag_minutes: number | null; failures: number; queued: number; last_error: { message: string | null; kind: string; direction: string } | null;
}
const BADGE = { ok: 'badge-green', warning: 'badge-yellow', error: 'badge-red', idle: 'badge-slate', inactive: 'badge-slate' } as const;
const when = (s: string | null) => (s ? new Date(s).toLocaleString() : '-');
const lag = (m: number | null) => (m === null ? '-' : m < 90 ? `${m} min` : `${Math.round(m / 60)} h`);

export default function ChannelHealth() {
    const t = useT();
    const board = useApi<{ data: Row[] }>('experience/channels/health');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

    const retry = async (r: Row) => {
        try { await api(`experience/channels/${r.id}/retry`, { method: 'POST' }); setMsg({ ok: true, text: t('experience.channels.queued', { name: r.name }) }); board.reload(); }
        catch (e) { setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) }); }
    };

    return (
        <AppLayout title={t('experience.channels.title')}>
            <Head title={t('experience.channels.title')} />
            <QueryStatus query={board} variant="table" />
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <button type="button" className="btn-secondary" onClick={board.reload}>{t('experience.channels.refresh')}</button>
                </div>
                {msg && <div className={msg.ok ? 'rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800' : 'rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800'}>{msg.text}</div>}
                <div className="card overflow-x-auto">
                    <table className="table-auto">
                        <thead><tr>{['connection', 'health', 'push', 'pull', 'failures', 'queued', 'error'].map((k) => <th key={k} className="p-2">{t(`experience.channels.col.${k}`)}</th>)}<th /></tr></thead>
                        <tbody>
                            {(board.data?.data ?? []).length === 0 && <tr><td colSpan={8} className="p-4 text-slate-500">{t('experience.channels.none')}</td></tr>}
                            {(board.data?.data ?? []).map((r) => (
                                <tr key={r.id} className="align-top">
                                    <td className="font-medium">{r.name}<div className="text-xs text-slate-500">{r.provider}</div></td>
                                    <td><span className={BADGE[r.health]}>{t(`experience.channels.health.${r.health}`)}</span></td>
                                    <td>{when(r.last_push_at)}<div className="text-xs text-slate-500">{t('experience.channels.lag')}: {lag(r.push_lag_minutes)}</div></td>
                                    <td>{when(r.last_pull_at)}<div className="text-xs text-slate-500">{t('experience.channels.lag')}: {lag(r.pull_lag_minutes)}</div></td>
                                    <td>{r.failures}</td><td>{r.queued}</td>
                                    <td className="text-xs text-red-700">{r.last_error?.message}</td>
                                    <td><button type="button" className="btn-secondary" onClick={() => retry(r)}>{t('experience.channels.retry')}</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
