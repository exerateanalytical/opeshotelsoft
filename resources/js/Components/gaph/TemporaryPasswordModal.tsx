import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import Modal from '@/Components/ui/Modal';
import { useT } from '@/lib/i18n';

/** Shows a temporary password once. Closing the dialog drops it from memory; it cannot be shown again. */
export default function TemporaryPasswordModal({ name, password, onClose }: { name: string; password: string | null; onClose: () => void }) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    async function copy() {
        if (!password) return;
        try {
            await navigator.clipboard.writeText(password);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    return (
        <Modal open={password !== null} onClose={() => { setCopied(false); onClose(); }} title={t('gaph.password.shown_title')}
            footer={<button type="button" className="btn-primary" onClick={() => { setCopied(false); onClose(); }}>{t('gaph.password.done')}</button>}>
            <div className="space-y-3">
                <p className="text-sm">{t('gaph.password.shown_body', { name })}</p>
                <div className="flex items-center gap-2">
                    <code className="flex-1 min-w-0 break-all rounded border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-base text-slate-800" data-testid="temp-password">{password}</code>
                    <button type="button" className="btn-secondary hit-44" onClick={copy} aria-label={t('gaph.password.copy')}>
                        {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                    </button>
                </div>
                <p className="text-xs text-slate-500" role="status">{copied ? t('gaph.password.copied') : t('gaph.password.once')}</p>
            </div>
        </Modal>
    );
}
