import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment } from 'react';
import { useT } from '@/lib/i18n';
import type { NavEntry } from '@/Components/layout/navModel';

export interface Crumb { label: string; href?: string }

const humanize = (seg: string) => decodeURIComponent(seg).replace(/[-_]+/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** Crumbs for the current URL from the permitted menu: section, each menu entry on the way, then the remaining segments. */
export function useAutoCrumbs(): Crumb[] {
    const t = useT();
    const { url, props } = usePage<{ nav?: NavEntry[] }>();
    const nav = props.nav ?? [];
    const segs = url.split('?')[0].split('#')[0].split('/').filter(Boolean);
    const crumbs: Crumb[] = [];
    let path = '';
    let section: string | null = null;
    const exact = nav.some((n) => n.path === `/${segs.join('/')}`);
    segs.forEach((seg, i) => {
        path += `/${seg}`;
        const entry = nav.find((n) => n.path === path);
        if (exact && !entry) return; // a menu page itself: trail of menu entries only
        if (entry && !section && entry.section) section = t(entry.section);
        const last = i === segs.length - 1;
        const numeric = /^\d+$/.test(seg);
        const own = t(`desktop.crumb.${seg}`);
        const label = entry ? t(entry.label) : numeric ? `#${seg}` : own !== `desktop.crumb.${seg}` ? own : humanize(seg);
        crumbs.push({ label, href: entry && !last ? path : undefined });
    });
    // Only nested pages get a trail: a top-level menu page has nothing above it.
    const nested = crumbs.length >= 2;
    return nested ? [...(section ? [{ label: section }] : []), ...crumbs] : [];
}

/** Breadcrumb trail (nav landmark, last item is aria-current). Pass `items` or leave empty to build it from the menu. */
export default function Breadcrumbs({ items, className }: { items?: Crumb[]; className?: string }) {
    const t = useT();
    const auto = useAutoCrumbs();
    const list = items ?? auto;
    if (list.length < 2) return null;
    return (
        <nav aria-label={t('desktop.crumbs.label')} className={className}>
            <ol className="n1-crumbs">
                {list.map((c, i) => {
                    const last = i === list.length - 1;
                    return (
                        <Fragment key={`${c.label}-${i}`}>
                            <li>
                                {c.href && !last ? <Link href={c.href} className="n1-crumb-link">{c.label}</Link> : <span aria-current={last ? 'page' : undefined} className={last ? 'font-semibold text-slate-900' : undefined}>{c.label}</span>}
                            </li>
                            {!last && <li aria-hidden="true" className="n1-crumb-sep"><ChevronRight className="h-3.5 w-3.5" /></li>}
                        </Fragment>
                    );
                })}
            </ol>
        </nav>
    );
}
