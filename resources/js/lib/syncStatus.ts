import { useSyncExternalStore } from 'react';

/**
 * Tiny shared store behind the connection and sync indicator in the app shell.
 * Feature code reports what it is holding back: `reportSync('pos', { pending: 2, failed: 0, conflict: 0 })`.
 * Sources today: 'pos' (offline restaurant checks), 'edge' (hotel server changes) and 'app' (anything else).
 */
export type SyncSource = 'pos' | 'edge' | 'app';
export type SyncState = 'online' | 'offline' | 'syncing' | 'synced' | 'failed' | 'conflict';

export interface SyncItem { id: string; label: string; detail?: string; kind: 'pending' | 'failed' | 'conflict' }

export interface SourceStatus {
    pending: number;
    failed: number;
    conflict: number;
    items: SyncItem[];
    lastError?: string | null;
    lastSyncedAt?: number | null;
    /** Where to resolve it (for example the POS terminal or the edge status page). */
    href?: string;
}

export interface SyncSnapshot {
    /** navigator.onLine and no recent network failure. */
    online: boolean;
    sources: Record<SyncSource, SourceStatus>;
    pending: number;
    failed: number;
    conflict: number;
    lastSyncedAt: number | null;
    state: SyncState;
    retrying: boolean;
}

const empty = (): SourceStatus => ({ pending: 0, failed: 0, conflict: 0, items: [] });
const POS_QUEUE_KEY = 'pos.offlineQueue';

let browserOnline = typeof navigator === 'undefined' ? true : navigator.onLine;
let reachable = true;
let retrying = false;
let sources: Record<SyncSource, SourceStatus> = { pos: empty(), edge: empty(), app: empty() };
const retryHandlers = new Map<SyncSource, () => Promise<unknown> | void>();
const subs = new Set<() => void>();
let cache: SyncSnapshot | null = null;

function compute(): SyncSnapshot {
    const all = Object.values(sources);
    const sum = (k: 'pending' | 'failed' | 'conflict') => all.reduce((n, s) => n + s[k], 0);
    const pending = sum('pending');
    const failed = sum('failed');
    const conflict = sum('conflict');
    const lastSyncedAt = all.reduce<number | null>((m, s) => (s.lastSyncedAt && (!m || s.lastSyncedAt > m) ? s.lastSyncedAt : m), null);
    const online = browserOnline && reachable;
    let state: SyncState = 'online';
    if (!online) state = 'offline';
    else if (conflict > 0) state = 'conflict';
    else if (failed > 0) state = 'failed';
    else if (pending > 0 || retrying) state = 'syncing';
    else if (lastSyncedAt) state = 'synced';
    return { online, sources, pending, failed, conflict, lastSyncedAt, state, retrying };
}

function emit() {
    cache = compute();
    subs.forEach((f) => f());
}

function subscribe(f: () => void) {
    subs.add(f);
    return () => { subs.delete(f); };
}

const snapshot = () => (cache ??= compute());

/** Report one source's counts. Missing fields keep their previous value. */
export function reportSync(source: SyncSource, patch: Partial<SourceStatus>) {
    sources = { ...sources, [source]: { ...sources[source], ...patch } };
    emit();
}

/** Register what "Retry" does for a source (for example the POS queue sync). Returns an unregister function. */
export function registerSyncRetry(source: SyncSource, fn: () => Promise<unknown> | void) {
    retryHandlers.set(source, fn);
    return () => { if (retryHandlers.get(source) === fn) retryHandlers.delete(source); };
}

/** Run every registered retry (or ask listeners to retry, see below). */
export async function retrySync() {
    retrying = true;
    emit();
    try {
        // Screens that own a queue also listen for the browser 'online' event, so signal them when no handler is mounted.
        if (!retryHandlers.size) window.dispatchEvent(new Event('online'));
        await Promise.allSettled([...retryHandlers.values()].map((f) => f()));
    } finally {
        retrying = false;
        emit();
    }
}

/** api() calls this: a fetch that failed at network level means the server is unreachable, whatever navigator.onLine says. */
export function setReachable(ok: boolean) {
    if (reachable === ok) return;
    reachable = ok;
    emit();
}

/** Read the POS offline queue straight from storage so the count is right on every page, not only the terminal. */
export function refreshPosQueueFromStorage() {
    try {
        const q = JSON.parse(localStorage.getItem(POS_QUEUE_KEY) ?? '[]') as { client_uuid: string; table_number?: string | null }[];
        reportSync('pos', {
            pending: Math.max(q.length - sources.pos.failed, 0),
            items: q.map((c) => ({ id: c.client_uuid, label: c.table_number ? `#${c.table_number}` : c.client_uuid.slice(0, 8), kind: 'pending' as const })),
            href: '/pos',
        });
    } catch { /* storage unavailable */ }
}

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => { browserOnline = true; reachable = true; emit(); });
    window.addEventListener('offline', () => { browserOnline = false; emit(); });
    window.addEventListener('storage', (e) => { if (e.key === POS_QUEUE_KEY) refreshPosQueueFromStorage(); });
    refreshPosQueueFromStorage();
}

export function useSyncStatus(): SyncSnapshot {
    return useSyncExternalStore(subscribe, snapshot, snapshot);
}

/** Convenience for screens that only need to know whether the browser is online. */
export function useOnline(): boolean {
    return useSyncStatus().online;
}
