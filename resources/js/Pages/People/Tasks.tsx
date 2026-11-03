import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { PeopleTabs } from './Index';
import { Department, Employee, EmployeeRef, errorText, listOf, openFile, post, upload } from './lib';

interface Task {
    id: number; title: string; description: string | null; priority: string; status: string; due_at: string | null; overdue: boolean;
    checklist: { label: string; done: boolean }[]; requires_photo: boolean; photos_count: number; assignee_employee: EmployeeRef | null; department_id: number | null;
}

const EMPTY = { title: '', assignee_employee_id: '', department_id: '', due_at: '', priority: 'normal', checklist: '', requires_photo: false };

export default function PeopleTasks() {
    const t = useT();
    const [view, setView] = useState<'all' | 'mine' | 'overdue'>('all');
    const list = useApi<Task[] | { data: Task[] }>(view === 'all' ? 'people/tasks' : `people/tasks/${view}`);
    const employees = useApi<Employee[] | { data: Employee[] }>('hr/employees?per_page=200');
    const departments = useApi<Department[]>('hr/departments');
    const [form, setForm] = useState<typeof EMPTY | null>(null);
    const [error, setError] = useState<string | null>(null);

    const run = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); list.reload(); } catch (e) { setError(errorText(e)); } };
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        if (!form) return;
        run(async () => {
            await post('people/tasks', {
                title: form.title, priority: form.priority, requires_photo: form.requires_photo, due_at: form.due_at || null,
                assignee_employee_id: form.assignee_employee_id ? Number(form.assignee_employee_id) : null,
                department_id: form.department_id ? Number(form.department_id) : null,
                checklist: form.checklist.split('\n').map((s) => s.trim()).filter(Boolean),
            });
            setForm(null);
        });
    };
    const deptName = (id: number | null) => departments.data?.find((d) => d.id === id)?.name ?? '';

    return (
        <AppLayout title={t('people.tasks.title')} subtitle={t('people.tasks.subtitle')}
            actions={<button className="btn-primary" onClick={() => setForm({ ...EMPTY })}>{t('people.tasks.new')}</button>}>
            <Head title={t('people.tasks.title')} />
            <PeopleTabs active="/people/tasks" />
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}

            {form && (
                <form onSubmit={save} className="card mb-4 grid gap-3 p-4 text-sm sm:grid-cols-3">
                    <label className="sm:col-span-3">{t('people.tasks.task')}<input className="input w-full" required maxLength={200} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></label>
                    <label>{t('people.tasks.person')}
                        <select className="input w-full" value={form.assignee_employee_id} onChange={(e) => setForm({ ...form, assignee_employee_id: e.target.value })}>
                            <option value="" />{listOf(employees.data).map((x) => <option key={x.id} value={x.id}>{x.full_name}</option>)}
                        </select>
                    </label>
                    <label>{t('people.tasks.or_team')}
                        <select className="input w-full" value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}>
                            <option value="" />{departments.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                        </select>
                    </label>
                    <label>{t('people.tasks.due')}<input type="datetime-local" className="input w-full" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></label>
                    <label>{t('people.tasks.priority')}
                        <select className="input w-full" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
                            {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{t(`people.priority.${p}`)}</option>)}
                        </select>
                    </label>
                    <label className="sm:col-span-2">{t('people.tasks.checklist_hint')}<textarea className="input w-full" rows={3} value={form.checklist} onChange={(e) => setForm({ ...form, checklist: e.target.value })} /></label>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.requires_photo} onChange={(e) => setForm({ ...form, requires_photo: e.target.checked })} />{t('people.tasks.requires_photo')}</label>
                    <div className="flex items-end gap-2 sm:col-span-3"><button className="btn-primary">{t('people.save')}</button><button type="button" className="btn-secondary" onClick={() => setForm(null)}>{t('people.cancel')}</button></div>
                </form>
            )}

            <div className="mb-3 flex gap-2 text-sm">
                {(['all', 'mine', 'overdue'] as const).map((v) => <button key={v} className={view === v ? 'btn-primary' : 'btn-secondary'} onClick={() => setView(v)}>{t(`people.tasks.view_${v}`)}</button>)}
            </div>
            {list.error && <p className="text-sm text-slate-500">{t('people.no_access')}</p>}
            <div className="space-y-3">
                {listOf(list.data).map((x) => (
                    <div key={x.id} className="card p-4 text-sm">
                        <div className="flex flex-wrap justify-between gap-2">
                            <div>
                                <span className="font-semibold">{x.title}</span>
                                <span className="ml-2 text-slate-500">{x.assignee_employee?.name ?? `${t('people.tasks.team')} ${deptName(x.department_id)}`}</span>
                            </div>
                            <div className="space-x-3">
                                <span>{t(`people.priority.${x.priority}`)}</span>
                                <span>{t(`people.task_status.${x.status}`)}</span>
                                {x.due_at && <span className={x.overdue ? 'text-red-600' : 'text-slate-500'}>{formatDate(x.due_at)}</span>}
                            </div>
                        </div>
                        {x.checklist.length > 0 && (
                            <ul className="mt-2 space-y-1">
                                {x.checklist.map((c, i) => (
                                    <li key={i}><label className="flex items-center gap-2">
                                        <input type="checkbox" checked={c.done} disabled={!['open', 'in_progress'].includes(x.status)} onChange={(e) => run(() => post(`people/tasks/${x.id}/checklist/${i}`, { done: e.target.checked }))} />{c.label}
                                    </label></li>
                                ))}
                            </ul>
                        )}
                        {['open', 'in_progress'].includes(x.status) && (
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <label className="btn-secondary cursor-pointer">{t('people.tasks.add_photo')} ({x.photos_count})
                                    <input type="file" accept="image/*" capture="environment" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) run(() => upload(`people/tasks/${x.id}/photos`, 'photo', f)); }} />
                                </label>
                                <button className="btn-primary" onClick={() => run(() => post(`people/tasks/${x.id}/complete`))}>{t('people.tasks.complete')}</button>
                                <button className="btn-secondary" onClick={() => run(() => post(`people/tasks/${x.id}/cancel`))}>{t('people.cancel')}</button>
                            </div>
                        )}
                        {x.status === 'done' && x.photos_count > 0 && (
                            <button className="mt-2 text-xs underline" onClick={() => run(async () => { const full = await (await import('@/lib/api')).api<{ photos: { id: number }[] }>(`people/tasks/${x.id}`); if (full.photos[0]) await openFile(`people/tasks/${x.id}/photos/${full.photos[0].id}`); })}>{t('people.tasks.see_photo')}</button>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
