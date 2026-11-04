import { Loader2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import Modal from '@/Components/ui/Modal';
import { useT } from '@/lib/i18n';

interface Props {
    open: boolean;
    title: string;
    /** What will happen, in plain words. */
    message: string;
    confirmLabel: string;
    danger?: boolean;
    busy?: boolean;
    error?: string | null;
    onClose: () => void;
    onConfirm: (reason: string) => void;
}

/** Confirmation dialog for sensitive admin actions: a reason (5 to 500 characters) is required and goes to the audit log. */
export default function ReasonConfirmModal({ open, title, message, confirmLabel, danger, busy, error, onClose, onConfirm }: Props) {
    const t = useT();
    const formId = useId();
    const fieldId = useId();
    const [reason, setReason] = useState('');
    useEffect(() => { if (open) setReason(''); }, [open]);
    const valid = reason.trim().length >= 5;

    return (
        <Modal open={open} onClose={onClose} title={title} formId={formId}
            footer={(
                <>
                    <button type="button" className="btn-secondary" onClick={onClose}>{t('common.cancel')}</button>
                    <button type="submit" className={danger ? 'btn-danger' : 'btn-primary'} disabled={busy || !valid}>
                        {busy && <Loader2 className="w-4 h-4 animate-spin" />} {confirmLabel}
                    </button>
                </>
            )}>
            <form id={formId} className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (valid && !busy) onConfirm(reason.trim()); }}>
                <p className="text-sm">{message}</p>
                <label htmlFor={fieldId} className="block text-sm font-medium text-slate-700">{t('gaph.reason.label')}</label>
                <textarea id={fieldId} className="input w-full" rows={3} required minLength={5} maxLength={500}
                    value={reason} onChange={(e) => setReason(e.target.value)} aria-describedby={`${fieldId}-hint`} />
                <p id={`${fieldId}-hint`} className="text-xs text-slate-500">{t('gaph.reason.hint')}</p>
                {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
            </form>
        </Modal>
    );
}
