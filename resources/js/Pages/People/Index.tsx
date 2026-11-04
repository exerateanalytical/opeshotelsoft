import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { useApi } from '@/lib/api';
import { errorKind } from '@/lib/apiErrors';
import ErrorState from '@/Components/ui/ErrorState';
import LoadingState from '@/Components/ui/LoadingState';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { EmployeeRef } from './lib';

interface Task { id: number; title: string; priority: string; status: string; due_at: string | null; overdue: boolean; assignee_employee: EmployeeRef | null }

export const PEOPLE_TABS = [
    ['/people/reviews', 'people.nav.reviews'], ['/people/feedback', 'people.nav.feedback'], ['/people/tasks', 'people.nav.tasks'],
    ['/people/discipline', 'people.nav.discipline'], ['/people/exits', 'people.nav.exits'],
] as const;

export function PeopleTabs({ active }: { active: string }) {
    const t = useT();
    return (
        <div className="mb-4 flex flex-wrap gap-2 text-sm">
            {PEOPLE_TABS.map(([href, key]) => (
                <Link key={href} href={href} className={`inline-flex min-h-11 items-center rounded-lg border px-3 py-1.5 ${active === href ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700'}`}>{t(key)}</Link>
            ))}
        </div>
    );
}

export default function PeopleIndex() {
    const t = useT();
    const overdue = useApi<Task[]>('people/tasks/overdue');
    return (
        <AppLayout title={t('people.title')} subtitle={t('people.subtitle')}>
            <Head title={t('people.title')} />
            <PeopleTabs active="/people" />
            <div className="card p-4">
                <h2 className="mb-3 font-semibold">{t('people.tasks.overdue')}</h2>
                {overdue.loading && !overdue.data && <LoadingState variant="list" rows={3} />}
                {overdue.error && !overdue.data && !overdue.loading && (errorKind(overdue.error) === 'forbidden'
                    ? <p className="text-sm text-slate-500">{t('people.no_access')}</p>
                    : <ErrorState error={overdue.error} onRetry={overdue.reload} compact />)}
                {overdue.data && overdue.data.length === 0 && <EmptyFor kind="tasks" />}
                <ul className="divide-y divide-slate-100 text-sm">
                    {overdue.data?.map((x) => (
                        <li key={x.id} className="flex justify-between py-2">
                            <span>{x.title} <span className="text-slate-500">— {x.assignee_employee?.name ?? t('people.tasks.team')}</span></span>
                            <span className="text-red-600">{x.due_at ? formatDate(x.due_at) : ''}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
