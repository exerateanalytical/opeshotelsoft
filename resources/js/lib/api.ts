import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
}

export class ApiError extends Error {
    constructor(public problem: Problem) {
        super(problem.detail ?? problem.title);
    }
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

export async function api<T = unknown>(path: string, init: RequestInit & { hotelId?: number | string | null } = {}): Promise<T> {
    const hotelId = init.hotelId ?? activeHotelId();
    const res = await fetch(`/api/v1/${path.replace(/^\//, '')}`, {
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
    if (res.status === 204) return undefined as T;
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new ApiError({ status: res.status, title: res.statusText, ...body });
    return body as T;
}

/** Fetch on mount (and when deps change); returns data, error and a reload function. */
export function useApi<T>(path: string | null, deps: unknown[] = []) {
    const hotelId = usePage<{ property?: { id: number } | null }>().props.property?.id ?? null;
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(!!path);
    const [tick, setTick] = useState(0);

    useEffect(() => {
        if (!path) return;
        let cancelled = false;
        setLoading(true);
        api<T>(path, { hotelId: activeHotelId() ?? hotelId })
            .then((d) => !cancelled && (setData(d), setError(null)))
            .catch((e) => !cancelled && setError(e instanceof ApiError ? e : new ApiError({ type: 'network', title: 'Network error', status: 0, detail: String(e) })))
            .finally(() => !cancelled && setLoading(false));
        return () => {
            cancelled = true;
        };
    }, [path, tick, hotelId, ...deps]);

    return { data, error, loading, reload: () => setTick((t) => t + 1) };
}
