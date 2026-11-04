import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatNumber } from '@/lib/utils';
import { useCan } from '@/lib/useCan';
import Modal from '@/Components/ui/Modal';
import Field from '@/Components/ui/Field';
import Alert from '@/Components/ui/Alert';
import QueryStatus from '@/Components/ui/QueryStatus';

interface Summary { count: number; avg_rating: number | null; positive: number; neutral: number; negative: number }

/** GAPD: guest and peer feedback summary for one employee (GET people/employees/{e}/feedback-summary). */
export function FeedbackSummaryCard({ employeeId }: { employeeId: number }) {
    const t = useT();
    const can = useCan();
    const allowed = can('people.view', 'people.manage');
    const q = useApi<Summary>(allowed ? `people/employees/${employeeId}/feedback-summary` : null, [employeeId, allowed]);
    if (!allowed) return null;
    const s = q.data;
    return (
        <section className="card p-4 text-sm" aria-labelledby="fb-sum">
            <h2 id="fb-sum" className="mb-2 font-semibold">{t('gapd.fb.title')}</h2>
            <QueryStatus query={q} variant="list" rows={1} />
            {s && (s.count === 0 ? <p className="text-slate-600">{t('gapd.fb.none')}</p> : (
                <dl className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    <div><dt className="text-slate-500">{t('gapd.fb.count')}</dt><dd className="num text-left text-base font-semibold">{s.count}</dd></div>
                    <div><dt className="text-slate-500">{t('gapd.fb.avg')}</dt><dd className="num text-left text-base font-semibold">{s.avg_rating === null ? '-' : `${formatNumber(s.avg_rating)} / 5`}</dd></div>
                    <div><dt className="text-slate-500">{t('gapd.fb.positive')}</dt><dd className="num text-left">{s.positive}</dd></div>
                    <div><dt className="text-slate-500">{t('gapd.fb.neutral')}</dt><dd className="num text-left">{s.neutral}</dd></div>
                    <div><dt className="text-slate-500">{t('gapd.fb.negative')}</dt><dd className="num text-left">{s.negative}</dd></div>
                </dl>
            ))}
        </section>
    );
}


interface ExitForm { kind: string; reason: string; notice_date: string; last_day: string; notice_worked: boolean; gross_misconduct: boolean; is_executive: boolean }
interface ExitRow extends Partial<ExitForm> { id: number; status: string; employee?: { name?: string } | null; certificate_number?: string | null; settlement?: { total: number } | null; reason?: string }

/** GAPD: exit detail and update of a draft exit (GET/PATCH people/exits/{exit}); the server recalculates the settlement. */
export function ExitDetailModal({ id, onClose, onSaved }: { id: number | null; onClose: () => void; onSaved: () => void }) {
    const t = useT();
    const can = useCan();
    const q = useApi<ExitRow>(id ? `people/exits/${id}` : null, [id]);
    const [f, setF] = useState<ExitForm | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const editable = can('people.exit') && q.data?.status === 'draft';
    useEffect(() => {
        const d = q.data;
        setErr(null);
        setF(d ? { kind: d.kind ?? 'resignation', reason: d.reason ?? '', notice_date: String(d.notice_date ?? '').slice(0, 10), last_day: String(d.last_day ?? '').slice(0, 10), notice_worked: !!d.notice_worked, gross_misconduct: !!d.gross_misconduct, is_executive: !!d.is_executive } : null);
    }, [q.data]);
    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!id || !f) return;
        try { await api(`people/exits/${id}`, { method: 'PATCH', body: JSON.stringify({ ...f, reason: f.reason || null }) }); onSaved(); onClose(); } catch (x) { setErr(x instanceof Error ? x.message : String(x)); }
    }
    return (
        <Modal open={id !== null} onClose={onClose} size="lg" title={q.data?.employee?.name ? `${t('gapd.exit.title')} · ${q.data.employee.name}` : t('gapd.exit.title')} formId="exit-form"
            footer={<>{editable && <button type="submit" className="btn-primary">{t('gapd.exit.save')}</button>}<button type="button" className="btn-secondary" onClick={onClose}>{t('gapd.close')}</button></>}>
            <QueryStatus query={q} variant="list" rows={3} />
            {err && <Alert variant="danger" className="mb-3">{err}</Alert>}
            {q.data && f && (
                <form id="exit-form" onSubmit={save} className="grid gap-3 text-sm sm:grid-cols-2">
                    <p className="sm:col-span-2 text-slate-600">{t('gapd.status')}: {t(`people.exit_status.${q.data.status}`)}{q.data.settlement ? ` · ${t('people.exits.total')}: ${formatNumber(q.data.settlement.total)}` : ''}{q.data.certificate_number ? ` · ${q.data.certificate_number}` : ''}</p>
                    {!editable && <p className="sm:col-span-2 text-slate-600">{t('gapd.exit.locked')}</p>}
                    <Field label={t('people.exits.kind')}><select className="select w-full" disabled={!editable} value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })}>{['resignation', 'termination', 'end_of_contract'].map((k) => <option key={k} value={k}>{t(`people.exit_kind.${k}`)}</option>)}</select></Field>
                    <Field label={t('people.exits.reason')}><input className="input w-full" maxLength={255} disabled={!editable} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
                    <Field label={t('people.exits.notice_date')}><input type="date" required className="input w-full" disabled={!editable} value={f.notice_date} onChange={(e) => setF({ ...f, notice_date: e.target.value })} /></Field>
                    <Field label={t('people.exits.last_day')}><input type="date" required className="input w-full" disabled={!editable} value={f.last_day} onChange={(e) => setF({ ...f, last_day: e.target.value })} /></Field>
                    <div className="flex flex-col gap-1 sm:col-span-2">
                        <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={f.notice_worked} onChange={(e) => setF({ ...f, notice_worked: e.target.checked })} />{t('people.exits.notice_worked')}</label>
                        <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={f.gross_misconduct} onChange={(e) => setF({ ...f, gross_misconduct: e.target.checked })} />{t('people.exits.gross_misconduct')}</label>
                        <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={f.is_executive} onChange={(e) => setF({ ...f, is_executive: e.target.checked })} />{t('people.exits.executive')}</label>
                    </div>
                </form>
            )}
        </Modal>
    );
}
