import { Link } from '@inertiajs/react';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';

const TABS = [
    ['/hr/workforce', 'workforce.nav.roster'], ['/hr/workforce/requests', 'workforce.nav.requests'], ['/hr/workforce/time', 'workforce.nav.time'],
    ['/hr/workforce/leave', 'workforce.nav.leave'], ['/hr/workforce/cost', 'workforce.nav.cost'], ['/hr/workforce/clock', 'workforce.nav.clock'],
] as const;

export function WorkforceTabs({ active }: { active: string }) {
    const t = useT();
    return (
        <div className="mb-4 flex flex-wrap gap-2 text-sm">
            {TABS.map(([href, key]) => (
                <Link key={href} href={href} className={`inline-flex min-h-11 items-center rounded-lg border px-3 py-1.5 ${active === href ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'}`}>{t(key)}</Link>
            ))}
        </div>
    );
}

export const wpost = (path: string, body: unknown = {}, method = 'POST') => api(path, { method, body: JSON.stringify(body) });
export const monday = (d = new Date()) => { const x = new Date(d); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x.toISOString().slice(0, 10); };
export const addDays = (iso: string, n: number) => { const x = new Date(iso + 'T00:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
export const msg = (e: unknown) => (e instanceof Error ? e.message : String(e));
