import { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { cn, formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

/** Shared bits for the A-A core ledger pages (budgets, dimensions, accruals, consolidation). */
export const CORE_TABS = [
    { href: '/finance/budgets', label: 'acc2.nav.budgets' },
    { href: '/finance/dimensions', label: 'acc2.nav.dimensions' },
    { href: '/finance/accruals', label: 'acc2.nav.accruals' },
    { href: '/finance/consolidation', label: 'acc2.nav.consolidation' },
] as const;

export function CoreNav({ active }: { active: string }) {
    const t = useT();
    return (
        <div className="tabs mb-4">
            <Link href="/finance" className="tab">{t('acc2.nav.finance')}</Link>
            {CORE_TABS.map((tb) => (
                <Link key={tb.href} href={tb.href}
                    className={cn('tab', active === tb.href && 'active')} aria-current={active === tb.href ? 'page' : undefined}>
                    {t(tb.label)}
                </Link>
            ))}
        </div>
    );
}

export const M = (n: number | string | null | undefined, currency = 'XAF') => formatCurrency(Number(n ?? 0), currency);

export function Th({ children, r }: { children?: ReactNode; r?: boolean }) {
    return <th className={cn('p-2 text-slate-500', r ? 'text-right' : 'text-left')}>{children}</th>;
}

export function Td({ children, r, b, mono }: { children?: ReactNode; r?: boolean; b?: boolean; mono?: boolean }) {
    return <td className={cn('p-2', r && 'text-right whitespace-nowrap', b && 'font-semibold', mono && 'font-mono')}>{children}</td>;
}

export function Table({ head, children }: { head: ReactNode; children: ReactNode }) {
    return (
        <div className="overflow-x-auto">
            <table className="table-auto w-full text-sm">
                <thead><tr>{head}</tr></thead>
                <tbody className="[&>tr]:border-t [&>tr]:border-slate-100">{children}</tbody>
            </table>
        </div>
    );
}

export const yearStart = () => `${new Date().getFullYear()}-01-01`;
