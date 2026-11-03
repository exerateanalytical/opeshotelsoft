import { useEffect, useRef } from 'react';
import { useT } from '@/lib/i18n';

interface Props { message: string; danger?: boolean; onConfirm: () => void; onCancel: () => void }

/** Bottom sheet on phones, centred dialog from 640px: replaces the browser confirm() box. */
export default function ConfirmSheet({ message, danger, onConfirm, onCancel }: Props) {
    const t = useT();
    const ok = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        ok.current?.focus();
        const k = (e: KeyboardEvent) => e.key === 'Escape' && onCancel();
        window.addEventListener('keydown', k);
        return () => window.removeEventListener('keydown', k);
    }, [onCancel]);
    return (
        <div className="fixed inset-0 z-50 bg-slate-900/60 flex items-end sm:items-center justify-center" onClick={onCancel}>
            <div role="alertdialog" aria-modal="true" aria-label={message} onClick={(e) => e.stopPropagation()}
                className="w-full sm:max-w-md bg-white rounded-t-2xl sm:rounded-2xl border border-slate-200 p-4 pb-[calc(1rem+var(--safe-bottom))] space-y-4">
                <p className="text-base text-slate-900">{message}</p>
                <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button type="button" className="btn-secondary min-h-11 justify-center" onClick={onCancel}>{t('member.confirm.cancel')}</button>
                    <button ref={ok} type="button" className={`${danger ? 'btn-danger' : 'btn-primary'} min-h-11 justify-center`} onClick={onConfirm}>{t('member.confirm.ok')}</button>
                </div>
            </div>
        </div>
    );
}
