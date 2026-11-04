import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import { PeopleTabs } from './Index';
import { Employee, EmployeeRef, errorText, listOf, money, openFile, post } from './lib';

interface Settlement { requires_review: boolean; seniority_years: number; notice_months: number; leave_days: number; lines: { code: string; amount: number; basis: string }[]; total: number }
interface Exit { id: number; kind: string; status: string; notice_date: string; last_day: string; settlement: Settlement | null; certificate_number: string | null; employee: EmployeeRef | null }

const EMPTY = { employee_id: '', kind: 'resignation', reason: '', notice_date: '', last_day: '', notice_worked: true, gross_misconduct: false, is_executive: false };

export default function PeopleExits() {
    const t = useT();
    const list = useApi<Exit[]>('people/exits');
    const employees = useApi<Employee[] | { data: Employee[] }>('hr/employees?per_page=200');
    const [form, setForm] = useState(EMPTY);
    const [error, setError] = useState<string | null>(null);
    const run = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); list.reload(); } catch (e) { setError(errorText(e)); } };

    return (
        <AppLayout title={t('people.exits.title')} subtitle={t('people.exits.subtitle')}>
            <Head title={t('people.exits.title')} />
            <QueryStatus query={list} variant="table" />
            <PeopleTabs active="/people/exits" />
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            <form className="card mb-4 grid gap-3 p-4 text-sm sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); run(async () => { await post('people/exits', { ...form, employee_id: Number(form.employee_id), reason: form.reason || null }); setForm(EMPTY); }); }}>
                <label>{t('people.employee')}
                    <select className="input w-full" required value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })}>
                        <option value="" />{listOf(employees.data).map((x) => <option key={x.id} value={x.id}>{x.full_name}</option>)}
                    </select>
                </label>
                <label>{t('people.exits.kind')}
                    <select className="input w-full" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                        {['resignation', 'termination', 'end_of_contract'].map((k) => <option key={k} value={k}>{t(`people.exit_kind.${k}`)}</option>)}
                    </select>
                </label>
                <label>{t('people.exits.reason')}<input className="input w-full" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></label>
                <label>{t('people.exits.notice_date')}<input type="date" required className="input w-full" value={form.notice_date} onChange={(e) => setForm({ ...form, notice_date: e.target.value })} /></label>
                <label>{t('people.exits.last_day')}<input type="date" required className="input w-full" value={form.last_day} onChange={(e) => setForm({ ...form, last_day: e.target.value })} /></label>
                <div className="flex flex-col justify-end gap-1">
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.notice_worked} onChange={(e) => setForm({ ...form, notice_worked: e.target.checked })} />{t('people.exits.notice_worked')}</label>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.gross_misconduct} onChange={(e) => setForm({ ...form, gross_misconduct: e.target.checked })} />{t('people.exits.gross_misconduct')}</label>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_executive} onChange={(e) => setForm({ ...form, is_executive: e.target.checked })} />{t('people.exits.executive')}</label>
                </div>
                <div className="sm:col-span-3"><button className="btn-primary">{t('people.exits.calculate')}</button></div>
            </form>

            <div className="space-y-3">
                {list.data?.length === 0 && <div className="card"><EmptyFor kind="staff" /></div>}
                {list.data?.map((x) => (
                    <div key={x.id} className="card p-4 text-sm">
                        <div className="flex flex-wrap justify-between gap-2">
                            <span className="font-semibold">{x.employee?.name} — {t(`people.exit_kind.${x.kind}`)} — {formatDate(x.last_day)}</span>
                            <span>{t(`people.exit_status.${x.status}`)}</span>
                        </div>
                        {x.settlement && (<>
                            {x.settlement.requires_review && <p className="my-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-amber-800">{t('people.exits.review_warning')}</p>}
                            <p className="text-slate-500">{t('people.exits.seniority')}: {x.settlement.seniority_years} · {t('people.exits.notice')}: {x.settlement.notice_months} · {t('people.exits.leave_days')}: {x.settlement.leave_days}</p>
                            <div className="overflow-x-auto"><table className="mt-2 w-full">
                                <tbody>
                                    {x.settlement.lines.map((l) => (
                                        <tr key={l.code} className="border-t border-slate-100"><td className="py-1">{t(`people.settlement.${l.code}`)}</td><td className="text-xs text-slate-500">{l.basis}</td><td className="text-right">{money(l.amount)}</td></tr>
                                    ))}
                                    <tr className="border-t border-slate-300 font-semibold"><td className="py-1">{t('people.exits.total')}</td><td /><td className="text-right">{money(x.settlement.total)}</td></tr>
                                </tbody>
                            </table></div>
                        </>)}
                        <div className="mt-3 space-x-2">
                            <OpsPrintButton path={`termination-letter/${x.id}`} label={t('documents4.termination')} />
                            {x.settlement && <OpsPrintButton path={`final-settlement/${x.id}`} label={t('documents4.settlement')} />}
                            {x.status === 'draft' && <button className="btn-primary" onClick={() => { if (window.confirm(t('people.exits.approve_confirm'))) run(() => post(`people/exits/${x.id}/approve`)); }}>{t('people.exits.approve')}</button>}
                            {x.status === 'approved' && ['fr', 'en'].map((l) => <button key={l} className="btn-secondary" onClick={() => run(() => openFile(`people/exits/${x.id}/work-certificate?lang=${l}`))}>{t('people.exits.certificate')} ({l.toUpperCase()})</button>)}
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
