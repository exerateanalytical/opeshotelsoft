/** Fetch helper for the member portal (session cookie + XSRF, JSON in and out). */
export class MemberApiError extends Error {
    status: number;
    fields: Record<string, string>;
    constructor(message: string, status: number, fields: Record<string, string> = {}) {
        super(message);
        this.status = status;
        this.fields = fields;
    }
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function mapi<T = Record<string, unknown>>(method: 'GET' | 'POST', url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        const fields: Record<string, string> = {};
        if (json.errors) Object.entries(json.errors).forEach(([k, v]) => (fields[k] = (v as string[])[0]));
        const first = Object.values(fields)[0];
        throw new MemberApiError(first ?? json.detail ?? json.message ?? json.title ?? 'Error', res.status, fields);
    }
    return json as T;
}

export const money = (n: number, currency = 'XAF') => `${Math.round(n).toLocaleString('fr-FR')} ${currency}`;

export const fmtDate = (d: string, locale: string) => new Date(d + (d.length === 10 ? 'T00:00:00' : '')).toLocaleDateString(locale === 'en' ? 'en-GB' : 'fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
