import { ShieldAlert } from 'lucide-react';
import { ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';

export interface Paginated<T> { data: T[]; total?: number }

export function errorText(e: unknown): string {
    if (e instanceof ApiError) {
        const errs = e.problem.errors;
        if (errs) return Object.values(errs).flat().join(' ');
        return e.message;
    }
    return String(e);
}

export function Forbidden({ permission }: { permission: string }) {
    const t = useT();
    return (
        <div className="card px-6 py-12 text-center max-w-lg mx-auto">
            <ShieldAlert className="w-10 h-10 mx-auto text-amber-500 mb-3" />
            <h3 className="font-semibold text-slate-800">{t('common.access_restricted')}</h3>
            <p className="text-sm text-slate-500 mt-1">{t('common.access_restricted_text', { permission })}</p>
        </div>
    );
}

export function Banner({ ok, text }: { ok: boolean; text: string }) {
    return (
        <div className={'mb-4 rounded-lg text-sm px-4 py-3 ' + (ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>{text}</div>
    );
}

export function Field({ label, children, className }: { label: string; children: React.ReactNode; className?: string }) {
    return (
        <label className={'block text-sm ' + (className ?? '')}>
            <span className="text-slate-600">{label}</span>
            <div className="mt-1">{children}</div>
        </label>
    );
}
