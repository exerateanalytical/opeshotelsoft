import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertOctagon, BellRing, BedDouble, CalendarCheck, CheckCheck, ClipboardCheck, Clock, Hourglass, LogIn, LogOut, Play, RefreshCw, Sun, Wrench,
    type LucideIcon,
} from 'lucide-react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { toast } from '@/Components/ui/Toast';
import EmptyState from '@/Components/ui/EmptyState';
import ErrorState from '@/Components/ui/ErrorState';
import Skeleton from '@/Components/ui/Skeleton';

type Urgency = 'overdue' | 'due_soon' | 'today';

interface Row {
    kind: string; id: number; title: string; subtitle?: string | null; room?: string | null; priority: string; status: string; due_at: string | null; urgency: Urgency;
    mine?: boolean; escalated?: boolean; requires_photo?: boolean; needs_inspection?: boolean; detail_url: string;
    can_start?: boolean; can_complete?: boolean; can_acknowledge?: boolean; can_approve?: boolean;
}

interface Home {
    generated_at: string;
    me: { id: number; name: string } | null;
    shift: { shifts: { starts_at: string; ends_at: string; role: string | null }[]; clocked_in: boolean; since: string | null } | null;
    sections: Record<string, Row[]>;
    overdue_total: number;
}

const SECTION_ICON: Record<string, LucideIcon> = {
    people_tasks: ClipboardCheck, service_requests: BellRing, escalations: AlertOctagon, housekeeping: BedDouble, work_orders: Wrench, approvals: CheckCheck,
};
const SECTION_ORDER = ['escalations', 'service_requests', 'housekeeping', 'work_orders', 'people_tasks', 'approvals'];
const GROUPS: { id: Urgency; icon: LucideIcon; tone: string }[] = [
    { id: 'overdue', icon: AlertOctagon, tone: 'text-red-700' },
    { id: 'due_soon', icon: Hourglass, tone: 'text-amber-700' },
    { id: 'today', icon: Sun, tone: 'text-slate-700' },
];

/** Where each row kind posts its one-tap actions: the owning module's own endpoint, which checks the permission. */
function endpoint(r: Row, action: 'acknowledge' | 'start' | 'complete' | 'approve'): { path: string; body?: unknown } | null {
    switch (r.kind) {
        case 'people_task': return action === 'start' || action === 'complete' ? { path: `people/tasks/${r.id}/${action}`, body: {} } : null;
        case 'service_request': return action === 'approve' ? null : { path: `service-requests/${r.id}/${action}`, body: {} };
        case 'housekeeping': return action === 'start' ? { path: `housekeeping/tasks/${r.id}/start`, body: {} } : action === 'complete' ? { path: `housekeeping/tasks/${r.id}/finish`, body: {} } : null;
        case 'work_order': return action === 'start' ? { path: `maintenance/work-orders/${r.id}/start`, body: {} } : null;
        case 'leave': return action === 'approve' ? { path: `hr/leave-requests/${r.id}/decide`, body: { approve: true } } : null;
        default: return null;
    }
}

export default function TasksHome() {
    const t = useT();
    const { property } = usePage<{ property: { id: number } | null }>().props;
    const q = useApi<Home>(property ? 'tasks/home' : null, [property?.id ?? 0]);
    const [busy, setBusy] = useState<string | null>(null);
    const home = q.data;

    const act = async (r: Row, action: 'acknowledge' | 'start' | 'complete' | 'approve') => {
        const e = endpoint(r, action);
        if (!e) return;
        setBusy(`${r.kind}${r.id}`);
        try {
            await api(e.path, { method: 'POST', body: JSON.stringify(e.body ?? {}) });
            toast.success(t(`tasks.done.${action}`));
            q.reload();
        } catch (err) {
            toast.error((err as Error).message);
        } finally {
            setBusy(null);
        }
    };

    const clock = async (dir: 'in' | 'out') => {
        setBusy('clock');
        try {
            await api(`hr/workforce/clock/${dir}`, { method: 'POST', body: JSON.stringify({}) });
            toast.success(t(`tasks.shift.clock_${dir}_done`));
            q.reload();
        } catch (err) {
            toast.error((err as Error).message);
        } finally {
            setBusy(null);
        }
    };

    const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const sections = home ? SECTION_ORDER.filter((s) => (home.sections[s] ?? []).length > 0) : [];
    const total = home ? sections.reduce((n, s) => n + home.sections[s].length, 0) : 0;

    return (
        <AppLayout
            title={t('tasks.title')}
            subtitle={q.updatedAt ? t('tasks.subtitle', { time: q.updatedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : undefined}
        >
            <Head title={t('tasks.title')} />
            <div className="mx-auto max-w-3xl space-y-5" aria-busy={q.loading}>
                <div className="flex items-center justify-between gap-3">
                    <p className="min-w-0 text-sm text-slate-600" role="status">
                        {home && total > 0 ? t('tasks.summary', { count: total, overdue: home.overdue_total }) : q.updatedAt ? t('tasks.subtitle', { time: q.updatedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) : t('tasks.updating')}
                    </p>
                    <button type="button" className="btn-secondary shrink-0" onClick={q.reload} disabled={q.loading}>
                        <RefreshCw className={cn('h-4 w-4', q.loading && 'animate-spin')} aria-hidden="true" />
                        {t('tasks.refresh')}
                    </button>
                </div>
                {q.error && !home && <ErrorState error={q.error} onRetry={q.reload} />}
                {q.loading && !home && !q.error && (
                    <div className="space-y-3" aria-label={t('tasks.updating')}>
                        <Skeleton className="h-20 w-full" /><Skeleton className="h-28 w-full" /><Skeleton className="h-28 w-full" />
                    </div>
                )}

                {home?.shift && (
                    <section className="card p-4" aria-labelledby="shift-h">
                        <h2 id="shift-h" className="flex items-center gap-2 text-sm font-semibold text-slate-900"><CalendarCheck className="h-4 w-4" aria-hidden="true" /> {t('tasks.shift.title')}</h2>
                        <p className="mt-1 text-sm text-slate-600">
                            {home.shift.shifts.length === 0 ? t('tasks.shift.none') : home.shift.shifts.map((s) => `${s.starts_at.slice(0, 5)}–${s.ends_at.slice(0, 5)}${s.role ? ` (${s.role})` : ''}`).join(', ')}
                        </p>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <span className={cn('badge', home.shift.clocked_in ? 'badge-green' : 'badge-slate')}>
                                <Clock className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
                                {home.shift.clocked_in && home.shift.since ? t('tasks.shift.clocked_in', { time: fmtTime(home.shift.since) }) : t('tasks.shift.clocked_out')}
                            </span>
                            <button type="button" className={cn('btn-lg', home.shift.clocked_in ? 'btn-secondary' : 'btn-primary')} disabled={busy === 'clock'} onClick={() => clock(home.shift!.clocked_in ? 'out' : 'in')}>
                                {home.shift.clocked_in ? <LogOut className="h-5 w-5" aria-hidden="true" /> : <LogIn className="h-5 w-5" aria-hidden="true" />}
                                {home.shift.clocked_in ? t('tasks.shift.clock_out') : t('tasks.shift.clock_in')}
                            </button>
                        </div>
                    </section>
                )}

                {home && total === 0 && <div className="card"><EmptyState icon={CheckCheck} title={t('tasks.all_clear')} description={t('tasks.all_clear_text')} /></div>}

                {home && sections.map((s) => {
                    const Icon = SECTION_ICON[s] ?? ClipboardCheck;
                    const rows = home.sections[s];
                    return (
                        <section key={s} aria-labelledby={`sec-${s}`}>
                            <h2 id={`sec-${s}`} className="mb-2 flex items-center gap-2 text-base font-semibold text-slate-900">
                                <Icon className="h-5 w-5" aria-hidden="true" /> {t(`tasks.section.${s}`)} <span className="badge badge-slate">{rows.length}</span>
                            </h2>
                            {GROUPS.map((g) => {
                                const list = rows.filter((r) => r.urgency === g.id);
                                if (!list.length) return null;
                                const GIcon = g.icon;
                                return (
                                    <div key={g.id} className="mb-3">
                                        <h3 className={cn('mb-1 flex items-center gap-1.5 text-sm font-semibold', g.tone)}>
                                            <GIcon className="h-4 w-4" aria-hidden="true" /> {t(`tasks.group.${g.id}`)}
                                        </h3>
                                        <ul className="space-y-2">
                                            {list.map((r) => <TaskCard key={`${r.kind}${r.id}`} r={r} busy={busy === `${r.kind}${r.id}`} act={act} />)}
                                        </ul>
                                    </div>
                                );
                            })}
                        </section>
                    );
                })}
            </div>
        </AppLayout>
    );
}

function TaskCard({ r, busy, act }: { r: Row; busy: boolean; act: (r: Row, a: 'acknowledge' | 'start' | 'complete' | 'approve') => void }) {
    const t = useT();
    const due = r.due_at ? new Date(r.due_at) : null;
    const dueText = due ? t(r.urgency === 'overdue' ? 'tasks.late' : 'tasks.due', { time: due.toLocaleString([], { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) }) : null;
    const hasAction = (r.can_acknowledge && endpoint(r, 'acknowledge')) || (r.can_start && endpoint(r, 'start')) || (r.can_complete && endpoint(r, 'complete')) || (r.can_approve && endpoint(r, 'approve'));
    const label = r.needs_inspection ? 'tasks.status.done' : `tasks.status.${r.status}`;

    return (
        <li className="card p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-base font-semibold text-slate-900 break-words">{r.title}</p>
                    {r.subtitle && <p className="mt-0.5 text-sm text-slate-600 break-words">{r.subtitle}</p>}
                </div>
                <span className="badge badge-slate shrink-0">{t(`tasks.kind.${r.kind}`)}</span>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span className={cn('badge', r.urgency === 'overdue' ? 'badge-red' : r.urgency === 'due_soon' ? 'badge-yellow' : 'badge-blue')}>
                    {r.urgency === 'overdue' ? <AlertOctagon className="mr-1 h-3.5 w-3.5" aria-hidden="true" /> : <Clock className="mr-1 h-3.5 w-3.5" aria-hidden="true" />}
                    {t(label)}
                </span>
                {(r.priority === 'urgent' || r.priority === 'high') && <span className="badge badge-red">{t(`tasks.priority.${r.priority}`)}</span>}
                {r.escalated && <span className="badge badge-red">{t('tasks.escalated')}</span>}
                {dueText && <span className="text-slate-600">{dueText}</span>}
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                {r.can_acknowledge && endpoint(r, 'acknowledge') && <button type="button" className="btn-secondary btn-lg flex-1" disabled={busy} onClick={() => act(r, 'acknowledge')}><CheckCheck className="h-5 w-5" aria-hidden="true" /> {t('tasks.action.acknowledge')}</button>}
                {r.can_start && endpoint(r, 'start') && <button type="button" className="btn-primary btn-lg flex-1" disabled={busy} onClick={() => act(r, 'start')}><Play className="h-5 w-5" aria-hidden="true" /> {t('tasks.action.start')}</button>}
                {r.can_complete && endpoint(r, 'complete') && <button type="button" className="btn-primary btn-lg flex-1" disabled={busy} onClick={() => act(r, 'complete')}><CheckCheck className="h-5 w-5" aria-hidden="true" /> {t('tasks.action.complete')}</button>}
                {r.can_approve && endpoint(r, 'approve') && <button type="button" className="btn-primary btn-lg flex-1" disabled={busy} onClick={() => act(r, 'approve')}><CheckCheck className="h-5 w-5" aria-hidden="true" /> {t('tasks.action.approve')}</button>}
                {(!hasAction || r.needs_inspection || r.kind === 'work_order' || r.kind === 'price_suggestion') && (
                    <Link href={r.detail_url} className="btn-secondary btn-lg flex-1">{t('tasks.action.open')}</Link>
                )}
            </div>
        </li>
    );
}
