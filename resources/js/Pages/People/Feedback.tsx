import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor, NoResults } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { PeopleTabs } from './Index';
import { Employee, EmployeeRef, errorText, listOf, post } from './lib';
import Alert from '@/Components/ui/Alert';

interface Row { id: number; source: string; rating: number | null; sentiment: string; comment: string | null; received_on: string; employee: EmployeeRef | null }

export default function PeopleFeedback() {
    const t = useT();
    const [sentiment, setSentiment] = useState('');
    const rows = useApi<{ data: Row[] }>(`people/feedback${sentiment ? `?sentiment=${sentiment}` : ''}`);
    const employees = useApi<Employee[] | { data: Employee[] }>('hr/employees?per_page=200');
    const [form, setForm] = useState({ employee_id: '', rating: '5', comment: '' });
    const [error, setError] = useState<string | null>(null);

    async function save(e: React.FormEvent) {
        e.preventDefault(); setError(null);
        try { await post('people/feedback', { employee_id: Number(form.employee_id), rating: Number(form.rating), comment: form.comment || null }); setForm({ ...form, comment: '' }); rows.reload(); } catch (err) { setError(errorText(err)); }
    }

    return (
        <AppLayout title={t('people.feedback.title')} subtitle={t('people.feedback.subtitle')}>
            <Head title={t('people.feedback.title')} />
            <QueryStatus query={rows} variant="list" />
            <PeopleTabs active="/people/feedback" />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            <form onSubmit={save} className="card mb-4 grid gap-3 p-4 text-sm sm:grid-cols-4">
                <label>{t('people.employee')}
                    <select className="select w-full" required value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })}>
                        <option value="" />
                        {listOf(employees.data).map((x) => <option key={x.id} value={x.id}>{x.full_name}</option>)}
                    </select>
                </label>
                <label>{t('people.feedback.rating')}<select className="select w-full" value={form.rating} onChange={(e) => setForm({ ...form, rating: e.target.value })}>{[5, 4, 3, 2, 1].map((n) => <option key={n}>{n}</option>)}</select></label>
                <label>{t('people.feedback.comment')}<input className="input w-full" value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} /></label>
                <div className="flex items-end"><button className="btn-primary">{t('people.feedback.add')}</button></div>
            </form>
            <div className="mb-2 text-sm">
                <select className="select" aria-label={t('ui2.people.filter_sentiment')} value={sentiment} onChange={(e) => setSentiment(e.target.value)}>
                    <option value="">{t('people.all')}</option>
                    {['positive', 'neutral', 'negative'].map((s) => <option key={s} value={s}>{t(`people.sentiment.${s}`)}</option>)}
                </select>
            </div>
            <div className="card table-wrap">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('people.date')}</th><th>{t('people.employee')}</th><th>{t('people.feedback.source')}</th><th>{t('people.feedback.rating')}</th><th>{t('people.feedback.sentiment')}</th><th>{t('people.feedback.comment')}</th></tr></thead>
                    <tbody>
                        {rows.data?.data.length === 0 && <tr><td colSpan={8} className="p-0">{sentiment ? <NoResults onReset={() => setSentiment('')} /> : <EmptyFor kind="messages" />}</td></tr>}
                        {rows.data?.data.map((r) => (
                            <tr key={r.id} className="border-t border-slate-100">
                                <td className="p-2">{formatDate(r.received_on)}</td><td>{r.employee?.name}</td><td>{t(`people.feedback.source_${r.source}`)}</td>
                                <td>{r.rating ?? '-'}</td><td>{t(`people.sentiment.${r.sentiment}`)}</td><td>{r.comment}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
