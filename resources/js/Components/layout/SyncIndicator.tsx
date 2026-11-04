import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, GitMerge, RefreshCw, Wifi, WifiOff, XCircle, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { registerSyncRetry, reportSync, retrySync, useSyncStatus, type SyncItem, type SyncSource, type SyncState } from '@/lib/syncStatus';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface EdgeStatus {
    mode: 'edge' | 'cloud';
    edge?: { pending: number; last_sync_at: string | null; last_error: string | null };
    conflicts: { id: number; table_name: string; row_id: number; rule: string; detail: string | null }[];
}

const VIEW: Record<SyncState, { icon: LucideIcon; tone: string }> = {
    online: { icon: Wifi, tone: 'text-emerald-800 border-slate-300' },
    offline: { icon: WifiOff, tone: 'text-amber-900 border-amber-700 bg-amber-50' },
    syncing: { icon: RefreshCw, tone: 'text-navy-500 border-slate-300' },
    synced: { icon: CheckCircle2, tone: 'text-emerald-800 border-slate-300' },
    failed: { icon: XCircle, tone: 'text-red-800 border-red-700 bg-red-50' },
    conflict: { icon: GitMerge, tone: 'text-amber-900 border-amber-700 bg-amber-50' },
};

const hhmm = (ms: number) => new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

/** Polls the edge node status when this server runs as an edge node (config edge.mode) and feeds the shared sync store. */
function useEdgeFeed(enabled: boolean) {
    useEffect(() => {
        if (!enabled) return;
        let alive = true;
        let timer: number | undefined;
        let edgeMode = false;
        const poll = async () => {
            if (!navigator.onLine) { timer = window.setTimeout(poll, 30000); return; }
            try {
                const s = await api<EdgeStatus>('edge/status');
                if (!alive) return;
                if (s.mode !== 'edge') return; // cloud servers have no local queue to show
                edgeMode = true;
                reportSync('edge', {
                    pending: s.edge?.pending ?? 0,
                    failed: s.edge?.last_error ? 1 : 0,
                    conflict: s.conflicts.length,
                    lastError: s.edge?.last_error ?? null,
                    lastSyncedAt: s.edge?.last_sync_at ? Date.parse(s.edge.last_sync_at) : null,
                    href: '/edge',
                    items: s.conflicts.slice(0, 20).map((c): SyncItem => ({ id: `c${c.id}`, label: `${c.table_name} #${c.row_id}`, detail: c.detail ?? c.rule, kind: 'conflict' })),
                });
            } catch { /* no permission or no edge module: nothing to show */ }
            if (alive && edgeMode) timer = window.setTimeout(poll, 30000);
        };
        poll();
        const off = registerSyncRetry('edge', async () => { await api('edge/sync', { method: 'POST' }).catch(() => undefined); await poll(); });
        return () => { alive = false; window.clearTimeout(timer); off(); };
    }, [enabled]);
}

function ItemList({ source, items, title }: { source: SyncSource; items: SyncItem[]; title: string }) {
    const t = useT();
    if (!items.length) return null;
    return (
        <div className="mt-3">
            <p className="text-sm font-semibold text-slate-900">{title}</p>
            <ul className="mt-1 divide-y divide-slate-200 rounded-lg border border-slate-300 text-sm">
                {items.map((i) => (
                    <li key={`${source}-${i.id}`} className="flex flex-col px-3 py-2">
                        <span className="text-slate-900">{t(`states.sync.source.${source}`)}: {i.label}</span>
                        {i.detail && <span className="text-slate-600">{i.detail}</span>}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Compact connection and sync indicator for the top bar: exactly one of Online, Offline, Syncing (n pending), Synced (time),
 * Failed (n) and Conflict (n), always with an icon and text. Opens a details sheet with the queued items and a Retry button.
 */
export default function SyncIndicator() {
    const t = useT();
    const s = useSyncStatus();
    const props = usePage<PageProps>().props;
    const canEdge = !!(props.auth?.user as { permissions?: string[] } | undefined)?.permissions?.includes('edge.manage');
    useEdgeFeed(canEdge);
    const [open, setOpen] = useState(false);
    const v = VIEW[s.state];
    const Icon = v.icon;
    const time = s.lastSyncedAt ? hhmm(s.lastSyncedAt) : '';
    const full = ({
        online: t('states.sync.online'),
        offline: t('states.sync.offline'),
        syncing: t('states.sync.syncing', { count: s.pending }),
        synced: t('states.sync.synced', { time }),
        failed: t('states.sync.failed', { count: s.failed }),
        conflict: t('states.sync.conflict', { count: s.conflict }),
    } satisfies Record<SyncState, string>)[s.state];
    // Phones: icon plus a one-word label (with the count), so the bar never overlaps the voice chip.
    const short = ({
        online: t('states.sync.short.online'),
        offline: t('states.sync.short.offline'),
        syncing: t('states.sync.short.syncing', { count: s.pending }),
        synced: t('states.sync.short.synced'),
        failed: t('states.sync.short.failed', { count: s.failed }),
        conflict: t('states.sync.short.conflict', { count: s.conflict }),
    } satisfies Record<SyncState, string>)[s.state];

    const group = (kind: SyncItem['kind'], source: SyncSource) => s.sources[source].items.filter((i) => i.kind === kind);
    const lastError = s.sources.edge.lastError;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-haspopup="dialog"
                aria-label={`${t('states.sync.label')}: ${full}`}
                data-sync-state={s.state}
                className={cn('inline-flex min-h-[44px] min-w-0 max-w-[8rem] flex-shrink items-center gap-1.5 rounded-lg border px-2 text-sm font-medium md:max-w-[15rem] md:px-3', v.tone)}
            >
                <Icon className={cn('h-4 w-4 flex-shrink-0', s.state === 'syncing' && 'motion-safe:animate-spin')} aria-hidden="true" />
                <span className="hidden truncate md:inline">{full}</span>
                <span className="truncate md:hidden">{short}</span>
            </button>

            <Modal open={open} onClose={() => setOpen(false)} title={t('states.sync.details')} variant="sheet"
                footer={(
                    <>
                        <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>{t('states.sync.close')}</button>
                        <button type="button" className="btn-primary" disabled={s.retrying || !s.online} onClick={() => { void retrySync(); }}>
                            <RefreshCw className={cn('h-4 w-4', s.retrying && 'motion-safe:animate-spin')} aria-hidden="true" /> {s.retrying ? t('states.sync.retrying') : t('states.sync.retry')}
                        </button>
                    </>
                )}>
                <p className={cn('flex items-center gap-2 text-base font-semibold', s.state === 'failed' ? 'text-red-800' : 'text-slate-900')} role="status">
                    <Icon className="h-5 w-5" aria-hidden="true" /> {full}
                </p>
                {!s.online && <p className="mt-2 text-sm text-slate-700">{t('states.sync.offline_note')}</p>}
                {s.sources.edge.pending + s.sources.edge.conflict > 0 || s.sources.edge.href ? <p className="mt-2 text-sm text-slate-700">{t('states.sync.edge_note')}</p> : null}
                {s.pending + s.failed + s.conflict === 0 && s.online && <p className="mt-2 text-sm text-slate-700">{t('states.sync.nothing')}</p>}
                {s.pending > 0 && (
                    <>
                        <ItemList source="pos" items={group('pending', 'pos')} title={t('states.sync.pending_title')} />
                        {s.sources.edge.pending > 0 && <p className="mt-3 text-sm text-slate-900">{t('states.sync.source.edge')}: {s.sources.edge.pending}</p>}
                    </>
                )}
                <ItemList source="pos" items={group('failed', 'pos')} title={t('states.sync.failed_title')} />
                <ItemList source="app" items={group('failed', 'app')} title={t('states.sync.failed_title')} />
                <ItemList source="edge" items={group('conflict', 'edge')} title={t('states.sync.conflict_title')} />
                {lastError && <p className="mt-3 flex items-start gap-2 text-sm text-red-800"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{t('states.sync.last_error', { error: lastError.slice(0, 200) })}</span></p>}
                {(s.sources.edge.href || s.sources.pos.pending > 0) && (
                    <p className="mt-3 flex flex-wrap gap-3 text-sm">
                        {s.sources.pos.pending > 0 && <Link href="/pos" className="text-blue-800 underline" onClick={() => setOpen(false)}>{t('states.sync.source.pos')}</Link>}
                        {s.sources.edge.href && <Link href="/edge" className="text-blue-800 underline" onClick={() => setOpen(false)}>{t('states.sync.open_edge')}</Link>}
                    </p>
                )}
            </Modal>
        </>
    );
}
