import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { WorkforceTabs, wpost, msg } from './WorkforceTabs';
import Alert from '@/Components/ui/Alert';

interface Shift { id: number; date: string; starts_at: string; ends_at: string; role: string | null }
interface Req { id: number; kind: string; status: string; requester: string | null; counterpart: string | null; shift: Shift | null }

export default function WorkforceRequests() {
    const t = useT();
    const open = useApi<Shift[]>('workforce/open-shifts');
    const reqs = useApi<Req[]>('workforce/requests');
    const [error, setError] = useState<string | null>(null);
    const act = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); open.reload(); reqs.reload(); } catch (e) { setError(msg(e)); } };
    return (
        <AppLayout title={t('workforce.requests.title')} subtitle={t('workforce.requests.subtitle')}>
            <Head title={t('workforce.requests.title')} />
            <QueryStatus query={open} variant="list" rows={3} />
            <QueryStatus query={reqs} variant="list" />
            <WorkforceTabs active="/hr/workforce/requests" />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            <div className="card mb-4 p-4 text-sm"><h2 className="mb-2 font-semibold">{t('workforce.requests.open_shifts')}</h2>
                {open.data?.length === 0 && <p className="text-slate-500">{t('workforce.none')}</p>}
                <ul className="divide-y divide-slate-100">{open.data?.map((s) => (
                    <li key={s.id} className="flex items-center justify-between py-2"><span>{s.date.slice(0, 10)} {s.starts_at.slice(0, 5)}-{s.ends_at.slice(0, 5)} {s.role}</span>
                        <button className="btn-primary" onClick={() => act(() => wpost(`workforce/open-shifts/${s.id}/claim`))}>{t('workforce.requests.claim')}</button></li>))}</ul></div>
            <div className="card p-4 text-sm"><h2 className="mb-2 font-semibold">{t('workforce.requests.list')}</h2>
                {reqs.data?.length === 0 && <EmptyFor kind="generic" />}
                <ul className="divide-y divide-slate-100">{reqs.data?.map((r) => (
                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{t(`workforce.kind.${r.kind}`)} - {r.requester}{r.counterpart ? ` / ${r.counterpart}` : ''} - {r.shift?.date.slice(0, 10)} {r.shift?.starts_at.slice(0, 5)} - {t(`workforce.status.${r.status}`)}</span>
                        <span className="flex gap-2">
                            {r.kind === 'swap' && r.status === 'pending' && <button className="btn-secondary" onClick={() => act(() => wpost(`workforce/requests/${r.id}/respond`, { accept: true }))}>{t('workforce.requests.accept')}</button>}
                            {['pending', 'accepted'].includes(r.status) && <button className="btn-primary" onClick={() => act(() => wpost(`workforce/requests/${r.id}/decide`, { approve: true }))}>{t('workforce.requests.approve')}</button>}
                            {['pending', 'accepted'].includes(r.status) && <button className="btn-secondary" onClick={() => act(() => wpost(`workforce/requests/${r.id}/decide`, { approve: false }))}>{t('workforce.requests.reject')}</button>}
                        </span></li>))}</ul></div>
        </AppLayout>
    );
}
