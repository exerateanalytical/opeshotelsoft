import { FormEvent, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { errMsg, Field } from './shared';
import { C, CardTable, SectionTitle, yesNo } from './gapbShared';

interface Rule { id: number; name: string; priority: number; direction: string; match_mode: string; pattern: string; account_code: string; amount_min: number | null; amount_max: number | null; label: string | null; auto_post: boolean; active: boolean }
type Form = { name: string; priority: string; direction: string; match_mode: string; pattern: string; account_code: string; amount_min: string; amount_max: string; label: string; auto_post: boolean; active: boolean };

const blank: Form = { name: '', priority: '100', direction: 'any', match_mode: 'contains', pattern: '', account_code: '', amount_min: '', amount_max: '', label: '', auto_post: false, active: true };

/** Bank rules: list, create and edit (PUT accounting3/bank-rules/{id}). */
export default function BankRulesPanel() {
    const t = useT();
    const can = useCan();
    const rules = useApi<Rule[]>('accounting3/bank-rules');
    const [edit, setEdit] = useState<{ id: number | null; f: Form } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const manage = can('accounting.bank_feed');

    const open = (r?: Rule) => {
        setErr(null);
        setEdit(r ? { id: r.id, f: { name: r.name, priority: String(r.priority ?? 100), direction: r.direction, match_mode: r.match_mode, pattern: r.pattern, account_code: r.account_code, amount_min: r.amount_min == null ? '' : String(r.amount_min), amount_max: r.amount_max == null ? '' : String(r.amount_max), label: r.label ?? '', auto_post: yesNo(r.auto_post), active: yesNo(r.active) } } : { id: null, f: { ...blank } });
    };
    const set = (p: Partial<Form>) => edit && setEdit({ ...edit, f: { ...edit.f, ...p } });

    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (!edit) return;
        const f = edit.f;
        setBusy(true); setErr(null);
        try {
            await api(edit.id ? `accounting3/bank-rules/${edit.id}` : 'accounting3/bank-rules', {
                method: edit.id ? 'PUT' : 'POST',
                body: JSON.stringify({ name: f.name, priority: Number(f.priority) || 100, direction: f.direction, match_mode: f.match_mode, pattern: f.pattern, account_code: f.account_code,
                    amount_min: f.amount_min === '' ? null : Number(f.amount_min), amount_max: f.amount_max === '' ? null : Number(f.amount_max), label: f.label || null, auto_post: f.auto_post, active: f.active }),
            });
            toast.success(t('gapb.saved'));
            setEdit(null);
            rules.reload();
        } catch (x) { setErr(errMsg(x)); } finally { setBusy(false); }
    };

    const dir = (d: string) => (d === 'in' ? t('acc3.feed.in') : d === 'out' ? t('acc3.feed.out') : t('acc3.feed.any'));

    return (
        <div className="card mt-4 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <SectionTitle hint={t('gapb.rules.hint')}>{t('acc3.feed.rules')}</SectionTitle>
                {manage && <button type="button" className="btn-primary" onClick={() => open()}>{t('gapb.rules.new')}</button>}
            </div>
            {rules.error && <p className="text-sm text-red-700" role="alert">{rules.error.message}</p>}
            {rules.data && rules.data.length === 0 && <EmptyState title={t('gapb.rules.empty')} description={t('gapb.rules.empty_hint')} />}
            {!!rules.data?.length && (
                <CardTable caption={t('acc3.feed.rules')} head={[t('gapb.priority'), t('acc3.name'), t('acc3.feed.pattern'), t('acc3.feed.direction'), t('acc3.feed.account_code'), t('acc3.feed.auto_post'), t('gapb.active'), '']}>
                    {rules.data.map((r) => (
                        <tr key={r.id}>
                            <C label={t('gapb.priority')} r>{r.priority}</C>
                            <C label={t('acc3.name')} b>{r.name}</C>
                            <C label={t('acc3.feed.pattern')} mono>{r.pattern}</C>
                            <C label={t('acc3.feed.direction')}>{dir(r.direction)}</C>
                            <C label={t('acc3.feed.account_code')} mono>{r.account_code}</C>
                            <C label={t('acc3.feed.auto_post')}>{yesNo(r.auto_post) ? t('acc3.yes') : t('acc3.no')}</C>
                            <C label={t('gapb.active')}>{yesNo(r.active) ? t('acc3.yes') : t('acc3.no')}</C>
                            <C label="" r>{manage && <button type="button" className="btn-secondary btn-sm" aria-label={`${t('gapb.edit')} ${r.name}`} onClick={() => open(r)}>{t('gapb.edit')}</button>}</C>
                        </tr>
                    ))}
                </CardTable>
            )}

            <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? t('gapb.rules.edit_title') : t('gapb.rules.new')} formId="bank-rule-form"
                footer={<><button type="button" className="btn-secondary" onClick={() => setEdit(null)}>{t('gapb.cancel')}</button><button type="submit" form="bank-rule-form" className="btn-primary" disabled={busy}>{t('common.save')}</button></>}>
                {edit && (
                    <form id="bank-rule-form" onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                        {err && <p className="text-sm text-red-700 sm:col-span-2" role="alert">{err}</p>}
                        <Field label={t('acc3.name')}><input required maxLength={120} className="input w-full" value={edit.f.name} onChange={(e) => set({ name: e.target.value })} /></Field>
                        <Field label={t('gapb.priority')}><input type="number" min={1} max={9999} className="input w-full" value={edit.f.priority} onChange={(e) => set({ priority: e.target.value })} /></Field>
                        <Field label={t('acc3.feed.pattern')}><input required maxLength={200} className="input w-full font-mono" value={edit.f.pattern} onChange={(e) => set({ pattern: e.target.value })} /></Field>
                        <Field label={t('acc3.feed.mode')}><select className="select w-full" value={edit.f.match_mode} onChange={(e) => set({ match_mode: e.target.value })}><option value="contains">{t('acc3.feed.contains')}</option><option value="regex">Regex</option></select></Field>
                        <Field label={t('acc3.feed.direction')}><select className="select w-full" value={edit.f.direction} onChange={(e) => set({ direction: e.target.value })}><option value="any">{t('acc3.feed.any')}</option><option value="in">{t('acc3.feed.in')}</option><option value="out">{t('acc3.feed.out')}</option></select></Field>
                        <Field label={t('acc3.feed.account_code')}><input required className="input w-full font-mono" value={edit.f.account_code} onChange={(e) => set({ account_code: e.target.value })} /></Field>
                        <Field label={t('gapb.amount_min')}><input type="number" min={0} className="input w-full" value={edit.f.amount_min} onChange={(e) => set({ amount_min: e.target.value })} /></Field>
                        <Field label={t('gapb.amount_max')}><input type="number" min={0} className="input w-full" value={edit.f.amount_max} onChange={(e) => set({ amount_max: e.target.value })} /></Field>
                        <div className="sm:col-span-2"><Field label={t('gapb.label')}><input maxLength={200} className="input w-full" value={edit.f.label} onChange={(e) => set({ label: e.target.value })} /></Field></div>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={edit.f.auto_post} onChange={(e) => set({ auto_post: e.target.checked })} />{t('acc3.feed.auto_post')}</label>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={edit.f.active} onChange={(e) => set({ active: e.target.checked })} />{t('gapb.active')}</label>
                    </form>
                )}
            </Modal>
        </div>
    );
}
