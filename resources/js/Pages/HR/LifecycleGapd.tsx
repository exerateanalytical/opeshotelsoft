import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatDateTime } from '@/lib/utils';
import { useCan } from '@/lib/useCan';
import Modal from '@/Components/ui/Modal';
import Field from '@/Components/ui/Field';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Badge, Banner, errMsg } from './shared';

interface Tpl { kind: string; locale: string; custom: boolean; active: boolean; title: string; body: string }
interface Audit { id: number; event: string; ip: string | null; created_at: string }
interface Detail { id: number; number: string; kind: string; locale: string; title: string; body: string; status: string; employee?: { number: string; first_name: string; last_name: string; email?: string | null; phone?: string | null } | null; audit: Audit[]; has_employee_signature: boolean }

/** GAPD: contract template editor (GET/PUT hr/contract-templates). */
export function TemplatesTab() {
    const t = useT();
    const can = useCan();
    const q = useApi<{ templates: Tpl[]; placeholders: string[] }>('hr/contract-templates');
    const [sel, setSel] = useState('');
    const [form, setForm] = useState<Tpl | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const manage = can('hr.lifecycle.manage', 'hr.manage');
    useEffect(() => {
        const tp = q.data?.templates.find((x) => `${x.kind}|${x.locale}` === sel) ?? q.data?.templates[0];
        if (tp) { setForm({ ...tp }); if (!sel) setSel(`${tp.kind}|${tp.locale}`); }
    }, [q.data, sel]);
    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!form) return;
        try {
            await api('hr/contract-templates', { method: 'PUT', body: JSON.stringify({ kind: form.kind, locale: form.locale, title: form.title, body: form.body, active: form.active }) });
            setOk(t('gapd.tpl.saved')); setErr(null); q.reload();
        } catch (x) { setErr(errMsg(x)); setOk(null); }
    }
    return (
        <div>
            <Banner ok={ok} err={err} />
            <QueryStatus query={q} variant="list" rows={3} />
            {q.data && form && (
                <form onSubmit={save} className="card space-y-3">
                    <h2 className="font-semibold">{t('gapd.tpl.title')}</h2>
                    <Field label={t('gapd.tpl.pick')}>
                        <select className="select w-full" value={sel} onChange={(e) => setSel(e.target.value)}>
                            {q.data.templates.map((x) => <option key={`${x.kind}|${x.locale}`} value={`${x.kind}|${x.locale}`}>{x.kind} · {x.locale.toUpperCase()}{x.custom ? ` (${t('gapd.tpl.custom')})` : ''}</option>)}
                        </select>
                    </Field>
                    <Field label={t('gapd.tpl.doc_title')}><input className="input w-full" required maxLength={200} value={form.title} disabled={!manage} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                    <Field label={t('gapd.tpl.body')} hint={`${t('gapd.tpl.placeholders')}: ${q.data.placeholders.join(', ')}`}>
                        <textarea className="textarea w-full font-mono" rows={16} required value={form.body} disabled={!manage} onChange={(e) => setForm({ ...form, body: e.target.value })} />
                    </Field>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.active} disabled={!manage} onChange={(e) => setForm({ ...form, active: e.target.checked })} />{t('gapd.tpl.active')}</label>
                    {manage && <button className="btn-primary">{t('gapd.save')}</button>}
                </form>
            )}
        </div>
    );
}

/** GAPD: e-contract detail with audit trail; drafts can be edited (PATCH hr/econtracts/{id}). */
export function EContractModal({ id, onClose, onSaved }: { id: number | null; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    const can = useCan();
    const q = useApi<Detail>(id ? `hr/econtracts/${id}` : null, [id]);
    const [form, setForm] = useState<{ title: string; body: string } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    useEffect(() => { setForm(q.data ? { title: q.data.title, body: q.data.body } : null); setErr(null); }, [q.data]);
    const editable = q.data?.status === 'draft' && can('hr.lifecycle.manage', 'hr.manage');
    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!id || !form) return;
        try { await api(`hr/econtracts/${id}`, { method: 'PATCH', body: JSON.stringify(form) }); onSaved(); onClose(); } catch (x) { setErr(errMsg(x)); }
    }
    return (
        <Modal open={id !== null} onClose={onClose} size="lg" title={q.data ? `${t('gapd.ec.title')} ${q.data.number}` : t('gapd.ec.title')} formId="ec-form"
            footer={<>{editable && <button type="submit" className="btn-primary">{t('gapd.save')}</button>}<button type="button" className="btn-secondary" onClick={onClose}>{t('gapd.close')}</button></>}>
            <QueryStatus query={q} variant="list" rows={3} />
            {err && <Banner err={err} />}
            {q.data && form && (
                <form id="ec-form" onSubmit={save} className="space-y-3 text-sm">
                    <dl className="grid grid-cols-2 gap-2">
                        <dt className="text-slate-500">{t('gapd.employee')}</dt><dd>{q.data.employee ? `${q.data.employee.number} · ${q.data.employee.first_name} ${q.data.employee.last_name}` : ''}</dd>
                        <dt className="text-slate-500">{t('gapd.status')}</dt><dd><Badge s={q.data.status} /></dd>
                        <dt className="text-slate-500">{t('gapd.ec.kind')}</dt><dd>{q.data.kind} · {q.data.locale.toUpperCase()}</dd>
                        <dt className="text-slate-500">{t('gapd.ec.signed')}</dt><dd>{q.data.has_employee_signature ? t('gapd.yes') : t('gapd.no')}</dd>
                    </dl>
                    <Field label={t('gapd.tpl.doc_title')}><input className="input w-full" required maxLength={200} disabled={!editable} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                    <Field label={t('gapd.tpl.body')} hint={editable ? undefined : t('gapd.ec.draft_only')}><textarea className="textarea w-full font-mono" rows={12} disabled={!editable} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} /></Field>
                    <h3 className="font-semibold">{t('gapd.ec.audit')}</h3>
                    <ul className="divide-y divide-slate-100">
                        {q.data.audit.map((a) => <li key={a.id} className="flex justify-between gap-2 py-1"><span>{a.event}</span><span className="text-slate-500">{formatDateTime(a.created_at)}{a.ip ? ` · ${a.ip}` : ''}</span></li>)}
                        {!q.data.audit.length && <li className="py-1 text-slate-500">{t('gapd.none')}</li>}
                    </ul>
                </form>
            )}
        </Modal>
    );
}

/** GAPD: run the scheduled compensation changes and transfers that are due (POST hr/lifecycle/apply-due). */
export function ApplyDueButton({ onDone }: { onDone?: () => void }) {
    const t = useT();
    const can = useCan();
    const [msg, setMsg] = useState<{ ok?: string; err?: string } | null>(null);
    const [busy, setBusy] = useState(false);
    if (!can('hr.lifecycle.approve')) return null;
    async function run() {
        setBusy(true);
        try { const r = await api<{ applied: unknown }>('hr/lifecycle/apply-due', { method: 'POST' }); setMsg({ ok: `${t('gapd.apply_due.done')}: ${typeof r.applied === 'number' ? r.applied : JSON.stringify(r.applied)}` }); onDone?.(); }
        catch (x) { setMsg({ err: errMsg(x) }); } finally { setBusy(false); }
    }
    return (
        <div className="mb-4">
            {msg && <Banner ok={msg.ok} err={msg.err} />}
            <button type="button" className="btn-secondary" disabled={busy} onClick={run}>{t('gapd.apply_due.button')}</button>
        </div>
    );
}
