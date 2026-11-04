import { useState } from 'react';
import Alert from '@/Components/ui/Alert';
import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { toast } from '@/Components/ui/Toast';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';

/** GAPI: manager cancel of an open shift request, with a mandatory reason (POST workforce/requests/{id}/manager-cancel, workforce.manage). */
export default function ManagerCancelButton({ requestId, summary, onDone }: { requestId: number; summary: string; onDone: () => void }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const formId = `gapi-mc-${requestId}`;
    const submit = async (e: React.FormEvent) => {
        e.preventDefault(); setBusy(true); setErr(null);
        try {
            await api(`workforce/requests/${requestId}/manager-cancel`, { method: 'POST', body: JSON.stringify({ reason: reason.trim() }) });
            toast.success(t('gapi.mc.done'));
            setOpen(false); setReason(''); onDone();
        } catch (x) { setErr(x instanceof Error ? x.message : String(x)); } finally { setBusy(false); }
    };
    return (
        <>
            <Button variant="danger" size="sm" onClick={() => { setErr(null); setOpen(true); }}>{t('gapi.mc.action')}</Button>
            <Modal open={open} onClose={() => setOpen(false)} title={t('gapi.mc.title')} formId={formId}
                footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t('gapi.mc.keep')}</Button><Button variant="danger" type="submit" loading={busy} disabled={reason.trim().length < 3}>{t('gapi.mc.confirm')}</Button></>}>
                <form id={formId} onSubmit={submit} className="grid gap-3">
                    <p className="text-sm text-slate-700">{t('gapi.mc.body')}</p>
                    <p className="text-sm font-medium">{summary}</p>
                    {err && <Alert variant="danger">{err}</Alert>}
                    <Field label={t('gapi.mc.reason')} hint={t('gapi.mc.reason_hint')} required>
                        <textarea className="textarea" required minLength={3} maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} />
                    </Field>
                </form>
            </Modal>
        </>
    );
}
