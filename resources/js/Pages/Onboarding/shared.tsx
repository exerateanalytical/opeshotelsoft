import { Link } from '@inertiajs/react';
import { activeHotelId } from '@/lib/api';
import { useT } from '@/lib/i18n';

const TABS = [
    { href: '/onboarding', key: 'onboarding.tab.wizard' },
    { href: '/onboarding/import', key: 'onboarding.tab.import' },
    { href: '/onboarding/branding', key: 'onboarding.tab.branding' },
    { href: '/onboarding/templates', key: 'onboarding.tab.templates' },
];

export function OnboardingTabs({ active }: { active: string }) {
    const t = useT();
    return (
        <nav className="mb-4 flex flex-wrap gap-2 border-b border-slate-200 pb-2 text-sm">
            {TABS.map((tab) => (
                <Link key={tab.href} href={tab.href} className={`inline-flex min-h-11 items-center ${tab.href === active ? 'font-semibold text-slate-900' : 'text-slate-500 hover:text-slate-800'}`}>
                    {t(tab.key)}
                </Link>
            ))}
        </nav>
    );
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Multipart upload to /api/v1 (the api() helper always sends JSON). */
export async function upload<T>(path: string, form: FormData): Promise<{ ok: boolean; status: number; body: T }> {
    const hotelId = activeHotelId();
    const res = await fetch(`/api/v1/${path}`, {
        method: 'POST',
        credentials: 'same-origin',
        body: form,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
            ...(hotelId ? { 'X-Hotel-Id': hotelId } : {}),
        },
    });
    const body = (await res.json().catch(() => ({}))) as T;
    return { ok: res.ok, status: res.status, body };
}

/** Fetch a PDF from /api/v1 and open it in a new tab. */
export async function openPdf(path: string, init: RequestInit = {}) {
    const hotelId = activeHotelId();
    const res = await fetch(`/api/v1/${path}`, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/pdf',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
            ...(hotelId ? { 'X-Hotel-Id': hotelId } : {}),
        },
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    window.open(URL.createObjectURL(await res.blob()), '_blank');
}
