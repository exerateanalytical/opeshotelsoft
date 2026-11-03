import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
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
        <select className="input w-48" value={outletId ?? ''} onChange={(e) => setOutletId(Number(e.target.value))}>
            {posOutlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </select>
    );

    return (
        <AppLayout title={t('pos.index.title')} subtitle={t('pos.index.subtitle')} actions={actions}>
            <Head title={t('pos.index.title')} />
            <div className="flex gap-2 mb-4 overflow-x-auto">
                {([['terminal', t('pos.index.tab_terminal')], ['menu', t('pos.index.tab_menu')], ['report', t('pos.index.tab_report')], ['setup', t('pos.index.tab_setup')]] as [Tab, string][]).map(([k, label]) => (
                    <button key={k} onClick={() => setTab(k)} className={`px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap ${tab === k ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700'}`}>{label}</button>
                ))}
            </div>
            {outlets.error && <div className="mb-4 rounded-lg px-4 py-2 text-sm bg-red-50 text-red-700 border border-red-200">{outlets.error.message}</div>}
            {tab === 'terminal' && (outletId ? <Terminal key={outletId} outletId={outletId} /> : outlets.loading ? <Loader2 className="w-5 h-5 animate-spin text-slate-400" /> : <p className="text-sm text-amber-600">{t('pos.index.no_outlet')}</p>)}
            {tab === 'menu' && <MenuTab outlets={posOutlets} />}
            {tab === 'report' && <DayReport outlets={posOutlets} />}
            {tab === 'setup' && outletId && <Setup outlets={posOutlets} outletId={outletId} />}
        </AppLayout>
    );
}
