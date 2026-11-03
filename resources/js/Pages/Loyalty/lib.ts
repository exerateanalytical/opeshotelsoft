import { activeHotelId, ApiError } from '@/lib/api';

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Fetch a PDF from the API (session cookie auth) and open it in a new tab. */
export async function openPdf(path: string): Promise<void> {
    const hotel = activeHotelId();
    const res = await fetch(`/api/v1/${path}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
    });
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new ApiError({ status: res.status, title: res.statusText, ...body });
    }
    const url = URL.createObjectURL(await res.blob());
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
