import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Banknote, Building2, CalendarClock, CalendarDays, FileText, Network, Timer, Users } from 'lucide-react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';
import EmployeesTab from './Employees';
import { LeaveTab, OrgTab, ShiftsTab } from './Organisation';
import { OvertimeTab } from './Overtime';
import { PayrollTab } from './Payroll';
import { ExpiryAlerts, OrgChartTab, RecordsTab } from './Records';

const TABS = [
    { key: 'employees', label: 'hr.tabs.employees', icon: Users },
    { key: 'records', label: 'hr.tabs.records', icon: FileText },
    { key: 'org', label: 'hr.tabs.org', icon: Building2 },
    { key: 'chart', label: 'hr.tabs.chart', icon: Network },
    { key: 'shifts', label: 'hr.tabs.shifts', icon: CalendarClock },
    { key: 'overtime', label: 'hr.tabs.overtime', icon: Timer },
    { key: 'leave', label: 'hr.tabs.leave', icon: CalendarDays },
    { key: 'payroll', label: 'hr.tabs.payroll', icon: Banknote },
] as const;
type Tab = (typeof TABS)[number]['key'];

export default function HrIndex() {
    const t = useT();
    const [tab, setTab] = useState<Tab>(() => {
        const tq = new URLSearchParams(window.location.search).get('tab');
        return (TABS.some((x) => x.key === tq) ? tq : 'employees') as Tab;
    });
    const select = (t: Tab) => {
        setTab(t);
        const u = new URL(window.location.href);
        u.searchParams.set('tab', t);
        window.history.replaceState(window.history.state, '', u);
    };

    return (
        <AppLayout title={t('hr.index.title')} subtitle={t('hr.index.subtitle')}>
            <Head title={t('hr.index.title')} />
            <div className="mb-5 flex gap-1 overflow-x-auto border-b">
                {TABS.map(({ key, label, icon: Icon }) => (
                    <button key={key} onClick={() => select(key)}
                        className={`flex items-center gap-2 whitespace-nowrap min-h-11 border-b-2 px-3 py-2 text-sm font-medium ${tab === key ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
                        <Icon className="h-4 w-4" /> {t(label)}
                    </button>
                ))}
            </div>
            {tab === 'employees' && <><ExpiryAlerts /><EmployeesTab /></>}
            {tab === 'records' && <RecordsTab />}
            {tab === 'chart' && <OrgChartTab />}
            {tab === 'overtime' && <OvertimeTab />}
            {tab === 'org' && <OrgTab />}
            {tab === 'shifts' && <ShiftsTab />}
            {tab === 'leave' && <LeaveTab />}
            {tab === 'payroll' && <PayrollTab />}
        </AppLayout>
    );
}
