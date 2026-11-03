import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { OrgChartTab } from './Records';
import { useT } from '@/lib/i18n';

export default function OrgChart() {
    const t = useT();
    return (
        <AppLayout title={t('hr.tabs.chart')} subtitle={t('hr.orgchart.subtitle')}>
            <Head title={t('hr.tabs.chart')} />
            <OrgChartTab />
        </AppLayout>
    );
}
