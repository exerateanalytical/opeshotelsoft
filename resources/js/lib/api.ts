import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from '@/Components/ui/Toast';
import { describeError } from '@/lib/apiErrors';
import { setReachable } from '@/lib/syncStatus';

/**
 * Client for the OpesHotelSoft public API (/api/v1). In the staff web app the
 * signed-in session carries a Passport `laravel_token` cookie, so requests are
 * authenticated without handling tokens in JavaScript. The active hotel is
 * sent as X-Hotel-Id.
 */
export interface Problem {
    type: string;
    title: string;
    status: number;
    detail?: string;
    errors?: Record<string, string[]>;
    request_id?: string;
    /** Seconds to wait (429), from the Retry-After header. */
    retry_after?: number;
}

export class ApiError extends Error {
    constructor(public problem: Problem) {
        super(problem.detail ?? problem.title);
    }

    get status() { return this.problem.status; }
    get requestId() { return this.problem.request_id ?? null; }
}

/** Laravel refreshes the XSRF-TOKEN cookie on every response, so it never goes stale after login. */
function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Hotel chosen in the switcher (cookie), else the active property Inertia shares with every page. */
export function activeHotelId(): string | null {
    const m = document.cookie.match(/(?:^|; )hotel_id=(\d+)/);
    if (m) return m[1];
    const id = window.history.state?.page?.props?.property?.id;
    return id ? String(id) : null;
}

/**
 * Network failures (fetch rejects) are rethrown as they are, not as ApiError: the POS terminal relies on that to queue a
 * settlement offline. They also tell the sync indicator that the server is unreachable.
 */
export async function api<T = unknown>(path: string, init: RequestInit & { hotelId?: number | string | null } = {}): Promise<T> {
    const hotelId = init.hotelId ?? activeHotelId();
    let res: Response;
    try {
        res = await fetch(`/api/v1/${path.replace(/^\//, '')}`, {
            ...init,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(hotelId ? { 'X-Hotel-Id': String(hotelId) } : {}),
                ...(init.method && init.method !== 'GET' ? { 'Idempotency-Key': crypto.randomUUID() } : {}),
                ...init.headers,
            },
        });
    } catch (e) {
        if (!(e instanceof DOMException && e.name === 'AbortError')) setReachable(false);
        throw e;
    }
    setReachable(true);
    if (res.status === 204) return undefined as T;
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        const retryAfter = Number(res.headers.get('Retry-After'));
        throw new ApiError({
            status: res.status,
            title: res.statusText,
            request_id: res.headers.get('X-Request-Id') ?? undefined,
            ...(retryAfter > 0 ? { retry_after: retryAfter } : {}),
            ...body,
        });
    }
    return body as T;
}

/** What a failed read looks like to a page: ApiError for server answers, a typed ApiError for timeouts and network failures. */
function toApiError(e: unknown): ApiError {
    if (e instanceof ApiError) return e;
    const timeout = e instanceof DOMException && e.name === 'AbortError';
    return new ApiError({ type: timeout ? 'timeout' : 'network', title: timeout ? 'Timeout' : 'Network error', status: 0 });
}

const READ_TIMEOUT_MS = 30000;
let lastToast = { text: '', at: 0 };

/**
 * Fetch on mount (and when deps change). Always returns { data, error, loading, reload, updatedAt }.
 * `error` is an ApiError (status 0 for timeout and network failures); render it with <ErrorState error={q.error} onRetry={q.reload} />
 * or let <ApiState query={q}> do loading, error and empty. A page that never reads `error` still tells the user through a toast.
 */
export function useApi<T>(path: string | null, deps: unknown[] = []) {
    const hotelId = usePage<{ property?: { id: number } | null }>().props.property?.id ?? null;
    const translations = usePage<{ translations?: Record<string, string> }>().props.translations;
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(!!path);
    const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
    const [tick, setTick] = useState(0);
    const errorRead = useRef(false);

    useEffect(() => {
        if (!path) return;
        let cancelled = false;
        const ctl = new AbortController();
        const timer = window.setTimeout(() => ctl.abort(), READ_TIMEOUT_MS);
        setLoading(true);
        api<T>(path, { hotelId: activeHotelId() ?? hotelId, signal: ctl.signal })
            .then((d) => { if (!cancelled) { setData(d); setError(null); setUpdatedAt(new Date()); } })
            .catch((e) => {
                if (cancelled) return;
                const err = toApiError(e);
                setError(err);
                // Safety net: a page that does not render `error` must not fail silently.
                if (!errorRead.current && navigator.onLine) {
                    const t = (k: string, p?: Record<string, string | number>) => Object.entries(p ?? {}).reduce((s, [a, b]) => s.split(`:${a}`).join(String(b)), translations?.[k] ?? k);
                    const d = describeError(err, t);
                    const text = `${d.title}. ${d.message}${d.requestId ? ` (${t('states.request_id', { id: d.requestId })})` : ''}`;
                    // One toast per distinct message every few seconds, even when several reads fail together.
                    if (text !== lastToast.text || Date.now() - lastToast.at > 5000) toast.error(text);
                    lastToast = { text, at: Date.now() };
                }
            })
            .finally(() => { window.clearTimeout(timer); if (!cancelled) setLoading(false); });
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
            ctl.abort();
        };
    }, [path, tick, hotelId, ...deps]);

    return {
        data,
        get error() { errorRead.current = true; return error; },
        loading,
        updatedAt,
        reload: () => setTick((t) => t + 1),
    };
}

export type ApiQuery<T = unknown> = ReturnType<typeof useApi<T>>;
