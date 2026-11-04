import { ShieldAlert } from 'lucide-react';
import { useT } from '@/lib/i18n';

/** 403 inside a page: says which role can do it and offers a way back. `roles` is a readable list such as "managers and finance". */
export default function PermissionState({ roles, onBack }: { roles?: string; onBack?: () => void }) {
    const t = useT();
    return (
        <div role="alert" className="flex flex-col items-center px-4 py-12 text-center">
            <ShieldAlert className="mb-3 h-10 w-10 text-amber-700" aria-hidden="true" />
            <p className="text-base font-semibold text-slate-900">{t('states.permission.title')}</p>
            <p className="mt-1 max-w-md text-sm text-slate-700">
                {roles ? t('states.permission.text', { roles }) : t('states.permission.generic')}
            </p>
            <button type="button" className="btn-secondary mt-4" onClick={onBack ?? (() => window.history.back())}>{t('states.back')}</button>
        </div>
    );
}
