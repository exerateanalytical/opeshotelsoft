import { useEffect, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import { toast } from '@/Components/ui/Toast';
import { api, ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';

export interface MessageRow { id: number; number: string; guest: string }

interface QuickReply { id: number; title: string; body: string }
interface Result { reservation_id: number; number: string | null; guest: string | null; channel: string | null; status: 'queued' | 'skipped' | 'failed'; reason: string | null }
interface Response { summary: Record<string, number>; results: Result[] }

const BADGE: Record<Result['status'], string> = { queued: 'badge-green', skipped: 'badge-yellow', failed: 'badge-red' };

/** Bulk message to the guests of the selected reservations, via POST messaging/bulk (preferred channel per guest). */
export default function BulkMessageModal({ rows, onClose }: { rows: MessageRow[] | null; onClose: () => void }) {
    const t = useT();
    const can = useCan();
    const [templates, setTemplates] = useState<QuickReply[]>([]);
    const [tpl, setTpl] = useState('');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [category, setCategory] = useState<'transactional' | 'marketing'>('transactional');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [res, setRes] = useState<Response | null>(null);

    useEffect(() => {
        if (!rows) return;
        setRes(null); setError(null); setTpl(''); setBody(''); setSubject(''); setCategory('transactional');
        if (can('messaging.view')) api<QuickReply[]>('messaging/quick-replies').then(setTemplates).catch(() => setTemplates([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows]);

    function pick(id: string) {
        setTpl(id);
        const q = templates.find((x) => String(x.id) === id);
        if (q) { setBody(q.body); setSubject(q.title); }
    }

    async function send() {
        if (!rows || !body.trim()) return;
        setBusy(true); setError(null);
        try {
            const r = await api<Response>('messaging/bulk', { method: 'POST', body: JSON.stringify({ reservation_ids: rows.map((x) => x.id), subject: subject.trim() || null, body: body.trim(), category }) });
            setRes(r);
            if (r.summary.queued) toast.success(t('gapg.msg.sent', { count: r.summary.queued }));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open={!!rows} onClose={onClose} size="lg" title={t('gapg.msg.title', { count: rows?.length ?? 0 })}
            footer={res
                ? <Button variant="primary" onClick={onClose}>{t('gapg.close')}</Button>
                : <>
                    <Button variant="secondary" onClick={onClose}>{t('gape.cancel')}</Button>
                    <Button variant="primary" loading={busy} disabled={!body.trim()} onClick={send}>{t('gapg.msg.send', { count: rows?.length ?? 0 })}</Button>
                </>}>
            {error && <div role="alert" className="mb-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>}
            {!res ? (
                <div className="space-y-3">
                    <p className="text-sm text-slate-600">{t('gapg.msg.hint')}</p>
                    {templates.length > 0 && (
                        <Field label={t('gapg.msg.template')}>
                            <select className="select" value={tpl} onChange={(e) => pick(e.target.value)}>
                                <option value="">{t('gapg.msg.free_text')}</option>
                                {templates.map((q) => <option key={q.id} value={q.id}>{q.title}</option>)}
                            </select>
                        </Field>
                    )}
                    <Field label={t('gapg.msg.kind')}>
                        <select className="select" value={category} onChange={(e) => setCategory(e.target.value as 'transactional' | 'marketing')}>
                            <option value="transactional">{t('gapg.msg.transactional')}</option>
                            <option value="marketing">{t('gapg.msg.marketing')}</option>
                        </select>
                    </Field>
                    <Field label={t('gapg.msg.subject')}>
                        <input className="input" maxLength={150} value={subject} onChange={(e) => setSubject(e.target.value)} />
                    </Field>
                    <Field label={t('gapg.msg.body')} required hint={t('gapg.msg.vars')}>
                        <textarea className="textarea" rows={5} maxLength={2000} data-autofocus value={body} onChange={(e) => setBody(e.target.value)} />
                    </Field>
                </div>
            ) : (
                <div>
                    <p role="status" className="mb-3 text-sm text-slate-700">
                        {t('gapg.msg.summary', { queued: res.summary.queued ?? 0, skipped: res.summary.skipped ?? 0, failed: res.summary.failed ?? 0 })}
                    </p>
                    <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200">
                        {res.results.map((r) => (
                            <li key={r.reservation_id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span><span className="font-mono">{r.number ?? `#${r.reservation_id}`}</span> · {r.guest ?? '-'}</span>
                                <span className="flex items-center gap-2">
                                    {r.channel && <span className="text-slate-600">{t(`gapg.channel.${r.channel}`)}</span>}
                                    <span className={`badge ${BADGE[r.status]}`}>{t(`gapg.status.${r.status}`)}</span>
                                    {r.reason && <span className="text-slate-600">{t(`gapg.reason.${r.reason}`)}</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Modal>
    );
}
