import Avatar from '@/Components/ui/Avatar';
import OpsPrintButton from '@/Components/OpsPrintButton';
import Skeleton from '@/Components/ui/Skeleton';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import Tabs from '@/Components/ui/Tabs';
import AppLayout from '@/Layouts/AppLayout';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Assets, Conduct, Data, Documents, Employment, Growth, Overview, Pay, Time, Timeline } from './EmployeeProfileSections';
import { Badge } from './shared';

interface Action { key: string; href?: string; print?: string; api?: string }
interface Header {
    employee: { id: number; number: string; full_name: string; status: string; photo_url: string | null; hotel: string | null; department: string | null; position: string | null; manager: { id: number; name: string } | null };
    sections: string[];
    actions: Action[];
}

/** One section: skeleton, then data, or its own error state with a retry. Mounted once visited so switching tabs is instant. */
function Section({ id, name, active, children }: { id: number; name: string; active: boolean; children: (d: Data, reload: () => void) => React.ReactNode }) {
    const t = useT();
    const r = useApi<Data>(`hr/employees/${id}/profile/${name}`);
    return (
        <div role="tabpanel" id={`panel-${name}`} aria-labelledby={`tab-${name}`} hidden={!active} className="mt-4">
            {r.loading && !r.data && (
                <div role="status" aria-busy="true" className="grid gap-3">
                    <span className="sr-only">{t('profile.loading')}</span>
                    <Skeleton className="h-24" /><Skeleton className="h-24" /><Skeleton className="h-16" />
                </div>
            )}
            {r.error && (
                <div role="alert" className="card flex flex-wrap items-center justify-between gap-3 border-red-300 p-4">
                    <p className="text-sm text-red-800">{t('profile.section_error')} {r.error.message}</p>
                    <button className="btn-secondary min-h-[44px]" onClick={r.reload}>{t('profile.retry')}</button>
                </div>
            )}
            {r.data && !r.error && children(r.data, r.reload)}
        </div>
    );
}

export default function EmployeeProfile({ employeeId }: { employeeId: number }) {
    const t = useT();
    const head = useApi<Header>(`hr/employees/${employeeId}/profile`);
    const [tab, setTab] = useState('overview');
    const [visited, setVisited] = useState<string[]>(['overview']);
    const h = head.data;
    const e = h?.employee;
    const sections = h?.sections ?? [];
    const canPay = sections.includes('pay');
    const pick = (k: string) => (setTab(k), setVisited((v) => (v.includes(k) ? v : [...v, k])));

    const labelOf = (a: Action) => t(`profile.action.${a.key}`);

    return (
        <AppLayout title={e?.full_name ?? t('profile.title')} subtitle={e ? `${e.number}` : ''}>
            <Head title={e?.full_name ?? t('profile.title')} />
            <Link href="/hr" className="mb-3 inline-flex min-h-[44px] items-center gap-1 text-sm text-slate-700 underline"><ArrowLeft className="h-4 w-4" /> {t('profile.back')}</Link>

            {head.error && (
                <div role="alert" className="card p-4 text-sm text-red-800">{head.error.problem.status === 404 ? t('profile.not_found') : head.error.message}</div>
            )}
            {head.loading && !h && <div role="status" aria-busy="true" className="grid gap-3"><Skeleton className="h-28" /><Skeleton className="h-12" /></div>}

            {h && e && (
                <>
                    <div className="card p-4">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <Avatar src={e.photo_url} name={e.full_name} size={72} />
                            <div className="min-w-0 flex-1">
                                <h2 className="break-words text-xl font-semibold text-slate-900">{e.full_name}</h2>
                                <p className="text-sm text-slate-700">{[e.position, e.department, e.hotel].filter(Boolean).join(' · ')}</p>
                                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-700"><span className="font-mono">{e.number}</span><Badge s={e.status} />{e.manager && <span>{t('profile.f.manager')}: {e.manager.name}</span>}</p>
                            </div>
                        </div>
                        {h.actions.length > 0 && (
                            <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label={t('profile.actions')}>
                                {h.actions.map((a) => a.print
                                    ? <OpsPrintButton key={a.key} path={a.print} label={labelOf(a)} className="btn-secondary inline-flex min-h-[44px] items-center gap-1 text-sm" />
                                    : <Link key={a.key} href={a.href ?? '#'} className="btn-secondary inline-flex min-h-[44px] items-center text-sm">{labelOf(a)}</Link>)}
                            </div>
                        )}
                    </div>

                    {sections.length === 0 && <div className="card mt-4"><EmptyFor kind="staff" /></div>}
                    <Tabs className="mt-4" label={t('profile.sections')} value={tab} onChange={pick} tabs={sections.map((k) => ({ key: k, label: t(`profile.tab.${k}`) }))} />

                    {sections.map((k) => visited.includes(k) && (
                        <Section key={k} id={employeeId} name={k} active={tab === k}>
                            {(d, reload) => {
                                switch (k) {
                                    case 'overview': return <Overview d={d} t={t} />;
                                    case 'employment': return <Employment d={d} t={t} canPay={canPay} />;
                                    case 'pay': return <Pay d={d} t={t} />;
                                    case 'time': return <Time d={d} t={t} />;
                                    case 'documents': return <Documents d={d} t={t} onUploaded={reload} />;
                                    case 'growth': return <Growth d={d} t={t} />;
                                    case 'conduct': return <Conduct d={d} t={t} />;
                                    case 'assets': return <Assets d={d} t={t} />;
                                    default: return <Timeline d={d} t={t} />;
                                }
                            }}
                        </Section>
                    ))}
                </>
            )}
        </AppLayout>
    );
}
