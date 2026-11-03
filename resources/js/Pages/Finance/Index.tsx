import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BookOpen, Building2, CalendarCheck, FileText, Landmark, ListTree, Lock, Receipt } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Accounts from './Accounts';
import Journal from './Journal';
import Reports from './Reports';
import Periods from './Periods';
import Receivables from './Receivables';
import Reconciliation from './Reconciliation';
import Assets from './Assets';
import Closing from './Closing';

const TABS = [
    { key: 'accounts', label: 'fin.tab.accounts', icon: ListTree },
    { key: 'journal', label: 'fin.tab.journal', icon: BookOpen },
    { key: 'reports', label: 'fin.tab.reports', icon: FileText },
    { key: 'periods', label: 'fin.tab.periods', icon: CalendarCheck },
    { key: 'receivables', label: 'fin.tab.receivables', icon: Receipt },
    { key: 'reconciliation', label: 'fin.tab.reconciliation', icon: Landmark },
    { key: 'assets', label: 'fin.tab.assets', icon: Building2 },
    { key: 'closing', label: 'fin.tab.closing', icon: Lock },
] as const;
type Tab = (typeof TABS)[number]['key'];

export default function FinanceIndex() {
    const t = useT();
    const [tab, setTab] = useState<Tab>('journal');
    return (
        <AppLayout title={t('fin.title')} subtitle={t('fin.subtitle')}>
            <Head title={t('fin.title')} />
            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
                {TABS.map((tb) => (
                    <button key={tb.key} onClick={() => setTab(tb.key)}
                        className={cn('flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', tab === tb.key ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700')}>
                        <tb.icon className="h-4 w-4" /> {t(tb.label)}
                    </button>
                ))}
            </div>
            {tab === 'accounts' && <Accounts />}
            {tab === 'journal' && <Journal />}
            {tab === 'reports' && <Reports />}
            {tab === 'periods' && <Periods />}
            {tab === 'receivables' && <Receivables />}
            {tab === 'reconciliation' && <Reconciliation />}
            {tab === 'assets' && <Assets />}
            {tab === 'closing' && <Closing />}
        </AppLayout>
    );
}
