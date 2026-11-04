import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import BrandLogo from '@/Components/ui/BrandLogo';
import { t, type Lang } from './i18n';
import Alert from '@/Components/ui/Alert';

export interface PublicProps {
    group: { slug: string; name: string };
    lang: Lang;
    query: Record<string, string>;
}

/** URL inside the public site, keeping the language. */
export function url(p: PublicProps, path: string, params: Record<string, string | number | undefined> = {}): string {
    const q = new URLSearchParams();
    Object.entries({ ...params, lang: p.lang }).forEach(([k, v]) => v !== undefined && String(v) !== '' && q.set(k, String(v)));
    return `/book/${p.group.slug}${path}?${q.toString()}`;
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Calls the public Booking API; throws Error with the API's message. */
export async function publicApi<T>(slug: string, path: string, body?: unknown): Promise<T> {
    const res = await fetch(`/api/v1/booking/${slug}${path}`, {
        method: body === undefined ? 'GET' : 'POST',
        headers: {
            Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(),
            ...(body === undefined ? {} : { 'Idempotency-Key': crypto.randomUUID() }),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = json.errors ? (Object.values(json.errors)[0] as string[])[0] : undefined;
        const err = new Error(first ?? json.detail ?? json.message ?? json.title ?? 'Error') as Error & { status: number };
        err.status = res.status;
        (err as Error & { code?: string }).code = json.code;
        throw err;
    }
    return json as T;
}

export function Notice({ kind, children }: { kind: 'error' | 'ok'; children: ReactNode }) {
    return (
        <Alert variant={kind === 'error' ? 'danger' : 'success'}>
            {children}
        </Alert>
    );
}

/** Flat navy and gold layout for the guest-facing site. */
export default function PublicLayout({ p, title, children }: { p: PublicProps; title: string; children: ReactNode }) {
    const d = t(p.lang);
    const other: Lang = p.lang === 'fr' ? 'en' : 'fr';
    const switchHref = () => {
        const q = new URLSearchParams(p.query);
        q.set('lang', other);
        return `${typeof window !== 'undefined' ? window.location.pathname : ''}?${q.toString()}`;
    };

    return (
        <div className="min-h-dvh bg-slate-50 text-navy-800" lang={p.lang}>
            <Head title={`${title} · ${p.group.name}`} />
            <header className="bg-navy-800 text-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-3 px-4 py-2">
                    <Link href={url(p, '')} className="flex items-center gap-3 min-h-11 font-serif text-lg sm:text-xl tracking-wide text-brand-300"><BrandLogo variant="emblem" size={36} />{p.group.name}</Link>
                    <nav className="flex items-center gap-4 text-sm">
                        <Link href={url(p, '/manage')} className="inline-flex items-center min-h-11 hover:text-brand-300">{d.manage}</Link>
                        <a href={switchHref()} className="inline-flex items-center justify-center min-h-11 min-w-11 rounded-lg border border-brand-300 px-2 text-sm font-semibold uppercase text-brand-300" aria-label={d.language}>
                            {other}
                        </a>
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8 text-base">
                <h1 className="mb-6 font-serif text-2xl sm:text-3xl text-navy-800">{title}</h1>
                {children}
            </main>
            <footer className="border-t border-slate-200 py-6 text-center text-sm text-slate-500">© {p.group.name} · <span className="inline-flex items-center gap-1.5 align-middle"><BrandLogo variant="emblem" size={16} alt="" />OPES Hôtels Soft</span></footer>
        </div>
    );
}

export const gold = 'btn-accent';
