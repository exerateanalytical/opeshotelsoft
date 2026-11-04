import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { useApi } from '@/lib/api';
import DayReport from './DayReport';
import MenuTab from './MenuTab';
import { Outlet, Paginated } from './shared';
import Setup from './Setup';
import Terminal from './Terminal';
import { useT } from '@/lib/i18n';

type Tab = 'terminal' | 'menu' | 'report' | 'setup';

export default function Page() {
    const t = useT();
    const outlets = useApi<Paginated<Outlet>>('outlets?filter[active]=1&per_page=100&sort=name');
    const posOutlets = (outlets.data?.data ?? []).filter((o) => o.kind !== 'laundry');
    const [outletId, setOutletId] = useState<number | null>(null);
    const [tab, setTab] = useState<Tab>('terminal');

    useEffect(() => {
        if (!outletId && posOutlets.length) setOutletId((posOutlets.find((o) => o.kind === 'restaurant') ?? posOutlets[0]).id);
    }, [outlets.data]);

    const actions = (
        <select className="select w-full sm:w-48" aria-label={t('pos.menu.outlet')} value={outletId ?? ''} onChange={(e) => setOutletId(Number(e.target.value))}>
            {posOutlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </select>
    );

    return (
        <AppLayout title={t('pos.index.title')} subtitle={t('pos.index.subtitle')} actions={actions}>
            <Head title={t('pos.index.title')} />
            <div className="flex gap-2 mb-4 overflow-x-auto">
                {([['terminal', t('pos.index.tab_terminal')], ['menu', t('pos.index.tab_menu')], ['report', t('pos.index.tab_report')], ['setup', t('pos.index.tab_setup')]] as [Tab, string][]).map(([k, label]) => (
                    <button key={k} onClick={() => setTab(k)} className={`shrink-0 whitespace-nowrap ${tab === k ? 'btn-primary' : 'btn-secondary'}`}>{label}</button>
                ))}
            </div>
            <QueryStatus query={outlets} variant="cards" rows={3} />
            {tab === 'terminal' && (outletId ? <Terminal key={outletId} outletId={outletId} /> : outlets.data && <div className="card"><EmptyState title={t('states3.empty.outlets.title')} description={t('pos.index.no_outlet')} action={<a href="/properties" className="btn-primary">{t('states3.empty.outlets.action')}</a>} /></div>)}
            {tab === 'menu' && <MenuTab outlets={posOutlets} />}
            {tab === 'report' && <DayReport outlets={posOutlets} />}
            {tab === 'setup' && outletId && <Setup outlets={posOutlets} outletId={outletId} />}
        </AppLayout>
    );
}
