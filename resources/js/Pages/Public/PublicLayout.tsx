import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { t, type Lang } from './i18n';

export interface PublicProps {
    group: { slug: string; name: string };
    lang: Lang;
    query: Record<string, string>;
}

/** URL inside the public site, keeping the language. */
export function url(p: PublicProps, path: string, params: Record<string, string | number | undefined> = {}): string {
    const q = new URLSearchParams();
    Object.entries({ ...params, lang: p.lang }).forEach(([k, v]) => v !== undefined && v !== '' && q.set(k, String(v)));
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
        throw err;
    }
    return json as T;
}

export function Notice({ kind, children }: { kind: 'error' | 'ok'; children: ReactNode }) {
    return (
        <div role="status" className={`rounded-md border px-4 py-3 text-sm ${kind === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}>
            {children}
        </div>
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
        <div className="min-h-screen bg-[#f7f5f0] text-[#0f1f3d]" lang={p.lang}>
            <Head title={`${title} · ${p.group.name}`} />
            <header className="bg-[#0f1f3d] text-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-4">
                    <Link href={url(p, '')} className="font-serif text-xl tracking-wide text-[#d4af6a]">{p.group.name}</Link>
                    <nav className="flex items-center gap-4 text-sm">
                        <Link href={url(p, '/manage')} className="hover:text-[#d4af6a]">{d.manage}</Link>
                        <a href={switchHref()} className="rounded border border-[#d4af6a] px-2 py-1 text-xs font-semibold uppercase text-[#d4af6a]" aria-label={d.language}>
                            {other}
                        </a>
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8 text-base">
                <h1 className="mb-6 font-serif text-3xl text-[#0f1f3d]">{title}</h1>
                {children}
            </main>
            <footer className="border-t border-[#e5dfd0] py-6 text-center text-sm text-slate-500">© {p.group.name}</footer>
        </div>
    );
}

export const gold = 'bg-[#b8923a] hover:bg-[#a07e2f] text-white';
