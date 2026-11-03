import { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { activeHotelId } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';

/** Shared bits for the A-B pages (payables workflow, dunning, tax, statements, bank feed, forecast, close). */
export const AB_TABS = [
    { href: '/finance/payables-workflow', label: 'acc3.nav.payables' },
    { href: '/finance/dunning', label: 'acc3.nav.dunning' },
    { href: '/finance/bank-feed', label: 'acc3.nav.bank_feed' },
    { href: '/finance/tax', label: 'acc3.nav.tax' },
    { href: '/finance/payroll-filings', label: 'acc3.nav.payroll_filings' },
    { href: '/finance/statements-pack', label: 'acc3.nav.statements' },
    { href: '/finance/cash-forecast', label: 'acc3.nav.forecast' },
    { href: '/finance/month-close', label: 'acc3.nav.close' },
] as const;

export function AbNav({ active }: { active: string }) {
    const t = useT();
    return (
        <div className="tabs mb-4">
            <Link href="/finance" className="tab">{t('acc2.nav.finance')}</Link>
            {AB_TABS.map((tb) => (
                <Link key={tb.href} href={tb.href}
                    className={cn('tab', active === tb.href && 'active')} aria-current={active === tb.href ? 'page' : undefined}>
                    {t(tb.label)}
                </Link>
            ))}
        </div>
    );
}

export function Gate({ children }: { children: ReactNode }) {
    return <p className="mb-4 border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">{children}</p>;
}

/** Authenticated file download (the API needs the hotel header, so a plain link would not do). */
export async function downloadFile(path: string, filename: string) {
    const hotel = activeHotelId();
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    const res = await fetch(`/api/v1/${path.replace(/^\//, '')}`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
    });
    if (!res.ok) throw new Error(String(res.status));
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

/** Multipart upload through the same session. */
export async function uploadFile<T>(path: string, form: FormData): Promise<T> {
    const hotel = activeHotelId();
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    const res = await fetch(`/api/v1/${path.replace(/^\//, '')}`, {
        method: 'POST', body: form, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Idempotency-Key': crypto.randomUUID(), ...(m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body.detail ?? body.title ?? String(res.status));
    return body as T;
}
