import { useState } from 'react';
import { ApiError, useApi } from '@/lib/api';
import type { Paged } from './ControlShared';

/** A list endpoint that answers either an array or a paginator. */
export function useList<T>(path: string | null) {
    const r = useApi<Paged<T> | T[]>(path);
    const rows: T[] = Array.isArray(r.data) ? r.data : r.data?.data ?? [];
    return { rows, reload: r.reload, loading: r.loading, error: r.error, empty: !r.loading && !r.error && rows.length === 0 };
}

/** Field errors from a 422 (first message per dotted field name) plus a general message. */
export function useFormErrors() {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [general, setGeneral] = useState<string | null>(null);
    return {
        errors, general, setErrors,
        clear: () => { setErrors({}); setGeneral(null); },
        fail(e: unknown) {
            if (e instanceof ApiError && e.problem.errors) {
                const out: Record<string, string> = {};
                for (const [k, v] of Object.entries(e.problem.errors)) out[k] = v[0];
                setErrors(out);
                setGeneral(null);
            } else {
                setErrors({});
                setGeneral(e instanceof ApiError ? e.message : String(e));
            }
        },
        /** First error whose key equals or starts with any of the given prefixes. */
        under(...keys: string[]) { const k = Object.keys(errors).find((x) => keys.some((p) => x === p || x.startsWith(p + '.'))); return k ? errors[k] : null; },
    };
}
