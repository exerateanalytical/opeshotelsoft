import type { ApiError } from '@/lib/api';

/**
 * Turns any thrown value into something safe and friendly to show: a title and a message in the user's language,
 * the request reference when the server sent one, and whether retrying makes sense.
 * Server domain messages (the `detail` of 400, 409 and 422 problems) are kept when they look like prose; for 5xx,
 * 403 and 404 the generic text is always used, so no exception text can reach the screen.
 */
export type ErrorKind =
    | 'validation' | 'unprocessable' | 'unauthenticated' | 'forbidden' | 'not_found' | 'conflict' | 'expired'
    | 'throttle' | 'server' | 'unavailable' | 'timeout' | 'network' | 'unknown';

export interface ErrorDescription {
    kind: ErrorKind;
    status: number;
    title: string;
    message: string;
    requestId: string | null;
    retryable: boolean;
    /** Seconds to wait before retrying (429). */
    retryAfter: number | null;
    fieldErrors: Record<string, string[]> | null;
}

type Tr = (key: string, params?: Record<string, string | number>) => string;

const TECHNICAL = /(SQLSTATE|Exception|Stack trace|vendor[\\/]|app[\\/]|\bat line\b|\.php|Undefined |Call to |Trying to |Class "|TypeError|<\/?\w+>|\{\s*"|\bnull\b given)/i;

/** True when a server message reads like a sentence a person can act on (short, no code or paths). */
export function isSafeMessage(m: unknown): m is string {
    return typeof m === 'string' && m.trim().length > 0 && m.length <= 300 && !TECHNICAL.test(m);
}

export function errorKind(e: unknown): ErrorKind {
    const x = e as Partial<ApiError> | null;
    const p = x?.problem;
    if (!p) return typeof navigator !== 'undefined' && !navigator.onLine ? 'network' : 'unknown';
    if (p.type === 'timeout') return 'timeout';
    if (p.type === 'network' || p.status === 0) return 'network';
    switch (p.status) {
        case 401: return 'unauthenticated';
        case 403: return 'forbidden';
        case 404: return 'not_found';
        case 409: return 'conflict';
        case 419: return 'expired';
        case 422: return p.errors && Object.keys(p.errors).length ? 'validation' : 'unprocessable';
        case 429: return 'throttle';
        case 502: case 503: case 504: return 'unavailable';
    }
    if (p.status >= 500) return 'server';
    if (p.status === 400) return 'unprocessable';
    return 'unknown';
}

const DOMAIN_KINDS: ErrorKind[] = ['conflict', 'unprocessable', 'validation'];

export function describeError(e: unknown, t: Tr): ErrorDescription {
    const kind = errorKind(e);
    const p = (e as Partial<ApiError> | null)?.problem;
    const retryAfter = p?.retry_after ?? null;
    let message = t(`states.err.${kind}.text`);
    if (kind === 'throttle' && retryAfter) message += ` ${t('states.err.throttle.wait', { seconds: retryAfter })}`;
    if (DOMAIN_KINDS.includes(kind) && isSafeMessage(p?.detail)) message = p!.detail!;
    return {
        kind,
        status: p?.status ?? 0,
        title: t(`states.err.${kind}.title`),
        message,
        requestId: p?.request_id ?? null,
        retryable: !['forbidden', 'not_found', 'validation'].includes(kind),
        retryAfter,
        fieldErrors: p?.errors ?? null,
    };
}

/** First validation message for a field, or undefined: `fieldError(err, 'email')`. */
export function fieldError(e: unknown, field: string): string | undefined {
    return (e as Partial<ApiError> | null)?.problem?.errors?.[field]?.[0];
}
