import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

const TABS = [
    ['/hr/talent', 'talent.nav.recruitment'], ['/hr/talent/skills', 'talent.nav.skills'], ['/hr/talent/training', 'talent.nav.training'],
    ['/hr/talent/planning', 'talent.nav.planning'], ['/hr/talent/compliance', 'talent.nav.compliance'],
] as const;

export function TalentTabs({ active }: { active: string }) {
    const t = useT();
    return (
        <div className="mb-4 flex flex-wrap gap-2 text-sm">
            {TABS.map(([href, key]) => (
                <Link key={href} href={href} className={`inline-flex min-h-11 items-center rounded-lg border px-3 py-1.5 ${active === href ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'}`}>{t(key)}</Link>
            ))}
        </div>
    );
}

export const tget = <T,>(path: string) => api<T>(`hr/talent/${path}`);
export const tsend = (path: string, body: unknown = {}, method = 'POST') => api(`hr/talent/${path}`, { method, body: JSON.stringify(body) });
export const msg = (e: unknown) => (e instanceof Error ? e.message : String(e));
export const money = (n: number | null | undefined) => (n == null ? '' : new Intl.NumberFormat('fr-FR').format(n));
export const unwrap = <T,>(r: T[] | { data: T[] }): T[] => (Array.isArray(r) ? r : r.data);

export function Banner({ error, ok }: { error?: string | null; ok?: string | null }) {
    if (!error && !ok) return null;
    return <Alert variant={error ? 'danger' : 'info'} className="mb-4">{error ?? ok}</Alert>;
}

export function Section({ title, children, right }: { title: string; children: ReactNode; right?: ReactNode }) {
    return (
        <section className="card mb-4 p-4">
            <div className="mb-3 flex items-center justify-between gap-2"><h2 className="text-base font-semibold">{title}</h2>{right}</div>
            {children}
        </section>
    );
}

export function Pill({ tone, children }: { tone: 'ok' | 'warn' | 'bad' | 'muted'; children: ReactNode }) {
    const c = { ok: 'bg-emerald-50 text-emerald-800 border-emerald-200', warn: 'bg-amber-50 text-amber-800 border-amber-200', bad: 'bg-red-50 text-red-700 border-red-200', muted: 'bg-slate-50 text-slate-700 border-slate-200' }[tone];
    return <span className={`inline-block rounded border px-2 py-0.5 text-sm ${c}`}>{children}</span>;
}

export const certTone = (s: string) => (s === 'valid' ? 'ok' : s === 'expiring' ? 'warn' : 'bad') as 'ok' | 'warn' | 'bad';
