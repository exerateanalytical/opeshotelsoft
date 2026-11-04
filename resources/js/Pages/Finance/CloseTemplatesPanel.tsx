import { FormEvent, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { errMsg, Field } from './shared';
import { C, CardTable, SectionTitle, yesNo } from './gapbShared';

interface Tpl { id: number; title: string; owner_role: string | null; due_offset_days: number; position: number; required: boolean; auto_check: string | null; active: boolean }
type Form = { title: string; owner_role: string; due_offset_days: string; position: string; required: boolean; auto_check: string; active: boolean };
interface Staff { id: number; name: string }

const ROLES = ['accountant', 'finance-director', 'payroll-officer', 'storekeeper'];

/** Month-end close checklist template editor (GET/POST/PUT accounting3/close/templates). */
export default function CloseTemplatesPanel({ onSaved }: { onSaved?: () => void }) {
    const t = useT();
    const can = useCan();
    const data = useApi<{ templates: Tpl[]; checks: string[] }>('accounting3/close/templates');
    const [edit, setEdit] = useState<{ id: number | null; f: Form } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const manage = can('accounting.close_manage');
    const role = (r: string | null) => (r ? t(`acc3.cl.role.${r}`) : '-');

    const open = (x?: Tpl) => {
        setErr(null);
        const next = ((data.data?.templates.reduce((m, r) => Math.max(m, r.position), 0) ?? 0) + 10);
        setEdit(x ? { id: x.id, f: { title: x.title, owner_role: x.owner_role ?? '', due_offset_days: String(x.due_offset_days), position: String(x.position), required: yesNo(x.required), auto_check: x.auto_check ?? '', active: yesNo(x.active) } }
            : { id: null, f: { title: '', owner_role: 'accountant', due_offset_days: '3', position: String(next), required: true, auto_check: '', active: true } });
    };
    const set = (p: Partial<Form>) => edit && setEdit({ ...edit, f: { ...edit.f, ...p } });

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!edit) return;
        const f = edit.f;
        setBusy(true); setErr(null);
        try {
            await api(edit.id ? `accounting3/close/templates/${edit.id}` : 'accounting3/close/templates', {
                method: edit.id ? 'PUT' : 'POST',
                body: JSON.stringify({ title: f.title, owner_role: f.owner_role || null, due_offset_days: Number(f.due_offset_days) || 0, position: Number(f.position) || 0, required: f.required, auto_check: f.auto_check || null, active: f.active }),
            });
            toast.success(t('gapb.saved'));
            setEdit(null);
            data.reload();
            onSaved?.();
        } catch (x) { setErr(errMsg(x)); } finally { setBusy(false); }
    };

    const rows = [...(data.data?.templates ?? [])].sort((a, b) => a.position - b.position);

    return (
        <div className="card mt-4 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <SectionTitle hint={t('gapb.tpl.hint')}>{t('gapb.tpl.title')}</SectionTitle>
                {manage && <button type="button" className="btn-primary" onClick={() => open()}>{t('gapb.tpl.new')}</button>}
            </div>
            {data.error && <p className="text-sm text-red-700" role="alert">{data.error.message}</p>}
            {data.data && rows.length === 0 && <EmptyState title={t('gapb.tpl.empty')} />}
            {rows.length > 0 && (
                <CardTable caption={t('gapb.tpl.title')} head={[t('gapb.position'), t('acc3.cl.task'), t('acc3.cl.owner'), t('gapb.tpl.offset'), t('acc3.cl.check'), t('gapb.tpl.required'), t('gapb.active'), '']}>
                    {rows.map((r) => (
                        <tr key={r.id}>
                            <C label={t('gapb.position')} r>{r.position}</C>
                            <C label={t('acc3.cl.task')} b>{r.title}</C>
                            <C label={t('acc3.cl.owner')}>{role(r.owner_role)}</C>
                            <C label={t('gapb.tpl.offset')} r>{r.due_offset_days}</C>
                            <C label={t('acc3.cl.check')}>{r.auto_check ? t(`gapb.check.${r.auto_check}`) : '-'}</C>
                            <C label={t('gapb.tpl.required')}>{yesNo(r.required) ? t('acc3.yes') : t('acc3.no')}</C>
                            <C label={t('gapb.active')}>{yesNo(r.active) ? t('acc3.yes') : t('acc3.no')}</C>
                            <C label="" r>{manage && <button type="button" className="btn-secondary btn-sm" aria-label={`${t('gapb.edit')} ${r.title}`} onClick={() => open(r)}>{t('gapb.edit')}</button>}</C>
                        </tr>
                    ))}
                </CardTable>
            )}

            <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? t('gapb.tpl.edit_title') : t('gapb.tpl.new')} formId="close-tpl-form"
                footer={<><button type="button" className="btn-secondary" onClick={() => setEdit(null)}>{t('gapb.cancel')}</button><button type="submit" form="close-tpl-form" className="btn-primary" disabled={busy}>{t('common.save')}</button></>}>
                {edit && (
                    <form id="close-tpl-form" onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                        {err && <p className="text-sm text-red-700 sm:col-span-2" role="alert">{err}</p>}
                        <div className="sm:col-span-2"><Field label={t('acc3.cl.task')}><input required maxLength={200} className="input w-full" value={edit.f.title} onChange={(e) => set({ title: e.target.value })} /></Field></div>
                        <Field label={t('acc3.cl.owner')}><select className="select w-full" value={edit.f.owner_role} onChange={(e) => set({ owner_role: e.target.value })}><option value="">-</option>{Array.from(new Set([...ROLES, edit.f.owner_role].filter(Boolean))).map((r) => <option key={r} value={r}>{role(r)}</option>)}</select></Field>
                        <Field label={t('gapb.tpl.offset')}><input type="number" min={0} max={60} className="input w-full" value={edit.f.due_offset_days} onChange={(e) => set({ due_offset_days: e.target.value })} /></Field>
                        <Field label={t('gapb.position')}><input type="number" min={0} className="input w-full" value={edit.f.position} onChange={(e) => set({ position: e.target.value })} /></Field>
                        <Field label={t('acc3.cl.check')}><select className="select w-full" value={edit.f.auto_check} onChange={(e) => set({ auto_check: e.target.value })}><option value="">{t('gapb.tpl.no_check')}</option>{data.data?.checks.map((c) => <option key={c} value={c}>{t(`gapb.check.${c}`)}</option>)}</select></Field>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={edit.f.required} onChange={(e) => set({ required: e.target.checked })} />{t('gapb.tpl.required')}</label>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={edit.f.active} onChange={(e) => set({ active: e.target.checked })} />{t('gapb.active')}</label>
                    </form>
                )}
            </Modal>
        </div>
    );
}

/** Assign a close task to a person (POST accounting3/close/tasks/{id}/assign). */
export function AssignTaskModal({ task, onClose, onDone }: { task: { id: number; title: string } | null; onClose: () => void; onDone: () => void }) {
    const t = useT();
    const staff = useApi<Staff[]>(task ? 'staff' : null, [task?.id]);
    const [owner, setOwner] = useState('');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!task) return;
        setBusy(true); setErr(null);
        try {
            await api(`accounting3/close/tasks/${task.id}/assign`, { method: 'POST', body: JSON.stringify({ owner_id: owner ? Number(owner) : null }) });
            toast.success(t('gapb.assign.done'));
            setOwner('');
            onDone();
        } catch (x) { setErr(errMsg(x)); } finally { setBusy(false); }
    };

    return (
        <Modal open={!!task} onClose={onClose} title={t('gapb.assign.title', { task: task?.title ?? '' })} formId="close-assign-form"
            footer={<><button type="button" className="btn-secondary" onClick={onClose}>{t('gapb.cancel')}</button><button type="submit" form="close-assign-form" className="btn-primary" disabled={busy}>{t('gapb.assign.action')}</button></>}>
            <form id="close-assign-form" onSubmit={save} className="grid gap-3">
                {err && <p className="text-sm text-red-700" role="alert">{err}</p>}
                <Field label={t('acc3.cl.owner')}>
                    <select className="select w-full" value={owner} onChange={(e) => setOwner(e.target.value)}>
                        <option value="">{t('gapb.assign.role_default')}</option>
                        {staff.data?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                </Field>
            </form>
        </Modal>
    );
}
