import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { PeopleTabs } from './Index';
import { Employee, EmployeeRef, errorText, listOf, openFile, post, upload } from './lib';
import Alert from '@/Components/ui/Alert';
import { useCan } from '@/lib/useCan';

interface Sanction { id: number; kind: string; incident_date: string; reason: string; status: string; suspension_start: string | null; suspension_end: string | null; documents_count: number; employee: EmployeeRef | null }

const EMPTY = { employee_id: '', kind: 'verbal_warning', incident_date: '', reason: '', facts: '', suspension_start: '', suspension_end: '' };

export default function PeopleDiscipline() {
    const t = useT();
    const list = useApi<Sanction[]>('people/sanctions');
    const employees = useApi<Employee[] | { data: Employee[] }>('hr/employees?per_page=200');
    const [form, setForm] = useState(EMPTY);
    const [error, setError] = useState<string | null>(null);
    const can = useCan();
    const run = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); list.reload(); } catch (e) { setError(errorText(e)); } };

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        run(async () => {
            await post('people/sanctions', { ...form, employee_id: Number(form.employee_id), facts: form.facts || null,
                suspension_start: form.kind === 'suspension' ? form.suspension_start : null, suspension_end: form.kind === 'suspension' ? form.suspension_end : null });
            setForm(EMPTY);
        });
    };
    const openDoc = (s: Sanction) => run(async () => {
        const full = await api<{ documents: { id: number }[] }>(`people/sanctions/${s.id}`);
        if (full.documents[0]) await openFile(`people/sanctions/${s.id}/documents/${full.documents[0].id}`);
    });

    return (
        <AppLayout title={t('people.discipline.title')} subtitle={t('people.discipline.subtitle')}>
            <Head title={t('people.discipline.title')} />
            <QueryStatus query={list} variant="table" />
            <PeopleTabs active="/people/discipline" />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            <form onSubmit={save} className="card mb-4 grid gap-3 p-4 text-sm sm:grid-cols-3">
                <label>{t('people.employee')}
                    <select className="select w-full" required value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })}>
                        <option value="" />{listOf(employees.data).map((x) => <option key={x.id} value={x.id}>{x.full_name}</option>)}
                    </select>
                </label>
                <label>{t('people.discipline.kind')}
                    <select className="select w-full" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                        {['verbal_warning', 'written_warning', 'suspension'].map((k) => <option key={k} value={k}>{t(`people.sanction.${k}`)}</option>)}
                    </select>
                </label>
                <label>{t('people.discipline.incident_date')}<input type="date" required className="input w-full" value={form.incident_date} onChange={(e) => setForm({ ...form, incident_date: e.target.value })} /></label>
                <label className="sm:col-span-3">{t('people.discipline.reason')}<input required maxLength={200} className="input w-full" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></label>
                <label className="sm:col-span-3">{t('people.discipline.facts')}<textarea rows={2} className="input w-full" value={form.facts} onChange={(e) => setForm({ ...form, facts: e.target.value })} /></label>
                {form.kind === 'suspension' && (<>
                    <label>{t('people.discipline.from')}<input type="date" required className="input w-full" value={form.suspension_start} onChange={(e) => setForm({ ...form, suspension_start: e.target.value })} /></label>
                    <label>{t('people.discipline.to')}<input type="date" required className="input w-full" value={form.suspension_end} onChange={(e) => setForm({ ...form, suspension_end: e.target.value })} /></label>
                    <p className="self-end text-xs text-slate-500">{t('people.discipline.max_8_days')}</p>
                </>)}
                <div className="sm:col-span-3"><button className="btn-primary">{t('people.discipline.issue')}</button></div>
            </form>
            <div className="card table-wrap">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('people.date')}</th><th>{t('people.employee')}</th><th>{t('people.discipline.kind')}</th><th>{t('people.discipline.reason')}</th><th>{t('people.status')}</th><th /></tr></thead>
                    <tbody>
                        {list.data?.length === 0 && <tr><td colSpan={8} className="p-0"><EmptyFor kind="staff" /></td></tr>}
                        {list.data?.map((s) => (
                            <tr key={s.id} className="border-t border-slate-100">
                                <td className="p-2">{formatDate(s.incident_date)}</td><td>{s.employee?.name}</td>
                                <td>{t(`people.sanction.${s.kind}`)}{s.suspension_start && ` (${formatDate(s.suspension_start)} – ${formatDate(s.suspension_end!)})`}</td>
                                <td>{s.reason}</td><td>{t(`people.sanction_status.${s.status}`)}</td>
                                <td className="space-x-2 p-2 text-right whitespace-nowrap">
                                    {s.status !== 'withdrawn' && <OpsPrintButton path={`warning-letter/${s.id}`} label={t('documents4.warning')} />}
                                    <label className="btn-secondary cursor-pointer">{t('people.discipline.attach')}<input type="file" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) run(() => upload(`people/sanctions/${s.id}/documents`, 'file', f)); }} /></label>
                                    {s.documents_count > 0 && <button className="btn-secondary" onClick={() => openDoc(s)}>{t('people.discipline.document')} ({s.documents_count})</button>}
                                    {s.status === 'issued' && <button className="btn-secondary" onClick={() => run(() => post(`people/sanctions/${s.id}/acknowledge`))}>{t('people.discipline.acknowledged')}</button>}
                                    {s.status === 'issued' && <button className="btn-secondary" onClick={() => run(() => post(`people/sanctions/${s.id}/acknowledge`, { refused: true }))}>{t('people.discipline.refused')}</button>}
                                    {s.status !== 'withdrawn' && can('people.discipline') && <button className="btn-danger" onClick={() => { if (window.confirm(t('gapd.sanction.withdraw_confirm'))) run(() => post(`people/sanctions/${s.id}/withdraw`)); }}>{t('gapd.sanction.withdraw')}</button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
