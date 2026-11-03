import { activeHotelId, api, ApiError } from '@/lib/api';

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

function headers(accept: string): Record<string, string> {
    const hotel = activeHotelId();
    return { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) };
}

/** Multipart upload (photo proof, discipline document). */
export async function upload(path: string, field: string, file: File, extra: Record<string, string> = {}): Promise<unknown> {
    const fd = new FormData();
    fd.append(field, file);
    Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(`/api/v1/${path}`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { ...headers('application/json'), 'Idempotency-Key': crypto.randomUUID() } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new ApiError({ status: res.status, title: res.statusText, ...body });
    return body;
}

/** Fetch a file (PDF, photo) and open it in a new tab. */
export async function openFile(path: string): Promise<void> {
    const res = await fetch(`/api/v1/${path}`, { credentials: 'same-origin', headers: headers('*/*') });
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new ApiError({ status: res.status, title: res.statusText, ...body });
    }
    const url = URL.createObjectURL(await res.blob());
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export const post = (path: string, body: unknown = {}, method = 'POST') => api(path, { method, body: JSON.stringify(body) });
export const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
export const listOf = <T,>(d: T[] | { data: T[] } | null | undefined): T[] => (Array.isArray(d) ? d : d?.data ?? []);

export interface EmployeeRef { id: number; name: string; number: string; department: string | null }
export interface Employee { id: number; full_name: string; number: string; department_id: number | null }
export interface Department { id: number; name: string }
export const money = (n: number) => new Intl.NumberFormat('fr-FR').format(n) + ' FCFA';
