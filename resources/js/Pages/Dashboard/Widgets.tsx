import { Link } from '@inertiajs/react';
import { AlertTriangle, BedDouble, Banknote, Boxes, CircleDollarSign, ClipboardCheck, Landmark, ShoppingCart, Tag, UsersRound, Wallet, Wrench, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

/* Data shapes returned by GET /api/v1/reports/dashboard/compose (app/Modules/Reports/Dashboard). */
export interface ComposeData {
    alerts?: { open: number; critical: number; latest: { id: number; severity: string; title: string }[] };
    cash_by_method?: { code: string; total: number }[];
    open_folios?: { count: number; balance: number };
    shift_cash?: { open: boolean; station: string | null; opening_float: number; opened_at: string | null };
    rate_suggestions?: { pending: number };
    housekeeping?: { to_clean: number; to_inspect: number; failed: number; mine_open: number; mine_done: number };
    work_orders?: { by_priority: Record<string, number>; total: number };
    pos?: { open_checks: number; sales_by_outlet: { outlet: string; total: number }[]; low_stock: number };
    stock?: { low: number; pending_receipts: number; open_counts: number };
    finance?: { unreconciled_lines: number; payables_due: number; payables_due_amount: number; period: { year: number; month: number; status: string } | null };
    hr?: { leave_pending?: number; documents_expiring?: number; payroll?: { period: string; status: string } | null };
}

function Card({ icon: Icon, title, href, children }: { icon: LucideIcon; title: string; href?: string; children: ReactNode }) {
    const t = useT();
    return (
        <section className="card p-5" aria-label={title}>
            <div className="mb-3 flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900"><Icon className="h-4 w-4 text-slate-600" aria-hidden="true" /> {title}</h3>
                {href && <Link href={href} className="inline-flex min-h-[44px] min-w-[44px] items-center justify-end text-sm font-semibold text-yellow-700 hover:underline">{t('tasks.dash.see')}</Link>}
            </div>
            {children}
        </section>
    );
}

function Stat({ label, value, warn }: { label: string; value: ReactNode; warn?: boolean }) {
    return (
        <div className="flex items-baseline justify-between gap-3 border-t border-slate-100 py-2 first:border-0 first:pt-0">
            <span className="text-sm text-slate-600">{label}</span>
            <span className="flex items-center gap-1 text-base font-bold text-slate-900">
                {warn && <AlertTriangle className="h-4 w-4 text-amber-600" aria-hidden="true" />}{value}
            </span>
        </div>
    );
}

export function Widget({ id, data, currency, can }: { id: string; data: ComposeData; currency: string; can: (...p: string[]) => boolean }) {
    const t = useT();
    const money = (n: number) => formatCurrency(n, currency);
    switch (id) {
        case 'alerts': {
            const d = data.alerts; if (!d) return null;
            return (
                <Card icon={AlertTriangle} title={t('tasks.dash.alerts')} href="/insights/alerts">
                    <Stat label={t('tasks.dash.alerts_open', { count: d.open })} value={d.open} warn={d.critical > 0} />
                    <Stat label={t('tasks.dash.alerts_critical', { count: d.critical })} value={d.critical} warn={d.critical > 0} />
                    {d.latest.map((a) => <p key={a.id} className="truncate border-t border-slate-100 py-1.5 text-sm text-slate-700">{a.title}</p>)}
                </Card>
            );
        }
        case 'cash_by_method': {
            const d = data.cash_by_method; if (!d) return null;
            return (
                <Card icon={Wallet} title={t('tasks.dash.cash_by_method')} href={can('cashier.view') ? '/billing/cashier' : undefined}>
                    {d.length === 0 ? <p className="text-sm text-slate-500">{t('tasks.dash.no_sales')}</p> : d.map((m) => <Stat key={m.code} label={m.code} value={money(m.total)} />)}
                </Card>
            );
        }
        case 'open_folios': {
            const d = data.open_folios; if (!d) return null;
            return (
                <Card icon={CircleDollarSign} title={t('tasks.dash.open_folios')} href="/billing/folios">
                    <Stat label={t('tasks.dash.open_folios')} value={d.count} />
                    <Stat label={t('tasks.dash.open_folios_balance')} value={money(d.balance)} />
                </Card>
            );
        }
        case 'shift_cash': {
            const d = data.shift_cash; if (!d) return null;
            return (
                <Card icon={Banknote} title={t('tasks.dash.shift_cash')} href="/billing/cashier">
                    <p className="text-sm text-slate-700">{d.open ? t('tasks.dash.shift_open', { time: d.opened_at?.slice(11, 16) ?? '', float: money(d.opening_float) }) : t('tasks.dash.shift_closed')}</p>
                </Card>
            );
        }
        case 'rate_suggestions': {
            const d = data.rate_suggestions; if (!d) return null;
            return <Card icon={Tag} title={t('tasks.dash.rate_suggestions')} href="/revenue/suggestions"><Stat label={t('tasks.dash.rate_suggestions')} value={d.pending} warn={d.pending > 0} /></Card>;
        }
        case 'housekeeping': {
            const d = data.housekeeping; if (!d) return null;
            return (
                <Card icon={BedDouble} title={t('tasks.dash.housekeeping')} href="/housekeeping">
                    <Stat label={t('tasks.dash.mine_open')} value={d.mine_open} />
                    <Stat label={t('tasks.dash.mine_done')} value={d.mine_done} />
                    {can('housekeeping.manage') && <>
                        <Stat label={t('tasks.dash.to_clean')} value={d.to_clean} />
                        <Stat label={t('tasks.dash.to_inspect')} value={d.to_inspect} />
                        <Stat label={t('tasks.dash.failed')} value={d.failed} warn={d.failed > 0} />
                    </>}
                </Card>
            );
        }
        case 'work_orders': {
            const d = data.work_orders; if (!d) return null;
            return (
                <Card icon={Wrench} title={t('tasks.dash.work_orders')} href="/maintenance">
                    {Object.entries(d.by_priority).map(([p, n]) => <Stat key={p} label={t(`tasks.priority.${p}`)} value={n} warn={(p === 'urgent' || p === 'high') && n > 0} />)}
                </Card>
            );
        }
        case 'pos': {
            const d = data.pos; if (!d) return null;
            return (
                <Card icon={ShoppingCart} title={t('tasks.dash.pos')} href="/pos">
                    <Stat label={t('tasks.dash.open_checks')} value={d.open_checks} />
                    <Stat label={t('tasks.dash.low_stock')} value={d.low_stock} warn={d.low_stock > 0} />
                    <p className="mt-2 text-sm font-semibold text-slate-600">{t('tasks.dash.sales_by_outlet')}</p>
                    {d.sales_by_outlet.length === 0 ? <p className="text-sm text-slate-500">{t('tasks.dash.no_sales')}</p> : d.sales_by_outlet.map((o) => <Stat key={o.outlet} label={o.outlet} value={money(o.total)} />)}
                </Card>
            );
        }
        case 'stock': {
            const d = data.stock; if (!d) return null;
            return (
                <Card icon={Boxes} title={t('tasks.dash.stock')} href="/stock">
                    <Stat label={t('tasks.dash.low_stock')} value={d.low} warn={d.low > 0} />
                    <Stat label={t('tasks.dash.pending_receipts')} value={d.pending_receipts} />
                    <Stat label={t('tasks.dash.open_counts')} value={d.open_counts} />
                </Card>
            );
        }
        case 'finance': {
            const d = data.finance; if (!d) return null;
            return (
                <Card icon={Landmark} title={t('tasks.dash.finance')} href="/finance">
                    <Stat label={t('tasks.dash.unreconciled')} value={d.unreconciled_lines} warn={d.unreconciled_lines > 0} />
                    <Stat label={t('tasks.dash.payables_due')} value={`${d.payables_due} · ${money(d.payables_due_amount)}`} />
                    <Stat label={t('tasks.dash.period')} value={d.period ? `${d.period.year}-${String(d.period.month).padStart(2, '0')} · ${d.period.status}` : t('tasks.dash.period_none')} />
                </Card>
            );
        }
        case 'hr': {
            const d = data.hr; if (!d) return null;
            return (
                <Card icon={UsersRound} title={t('tasks.dash.hr')} href="/hr">
                    {d.leave_pending !== undefined && <Stat label={t('tasks.dash.leave_pending')} value={d.leave_pending} warn={d.leave_pending > 0} />}
                    {d.documents_expiring !== undefined && <Stat label={t('tasks.dash.documents_expiring')} value={d.documents_expiring} warn={d.documents_expiring > 0} />}
                    {'payroll' in d && <Stat label={t('tasks.dash.payroll')} value={d.payroll ? `${d.payroll.period} · ${d.payroll.status}` : t('tasks.dash.payroll_none')} />}
                </Card>
            );
        }
        default:
            return null;
    }
}

export const MyTasksLink = () => {
    const t = useT();
    return (
        <Link href="/tasks" className="btn-secondary btn-lg mb-4 w-full justify-start md:hidden">
            <ClipboardCheck className="h-5 w-5" aria-hidden="true" /> {t('tasks.dash.my_tasks_link')}
        </Link>
    );
};
