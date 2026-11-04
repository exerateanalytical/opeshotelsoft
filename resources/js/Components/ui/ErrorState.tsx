import { AlertTriangle, CloudOff, Lock, RefreshCw, SearchX, Timer, type LucideIcon } from 'lucide-react';
import { describeError, type ErrorKind } from '@/lib/apiErrors';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const ICON: Partial<Record<ErrorKind, LucideIcon>> = {
    network: CloudOff, timeout: Timer, forbidden: Lock, unauthenticated: Lock, not_found: SearchX, throttle: Timer,
};

interface Props {
    /** An ApiError (or anything thrown). Never rendered raw: only the friendly description is shown. */
    error?: unknown;
    /** Overrides the friendly text. */
    title?: string;
    message?: string;
    onRetry?: () => void;
    /** Smaller, for a card or panel instead of a whole page area. */
    compact?: boolean;
    className?: string;
}

/** Understandable error with a retry button and the request reference when the server gave one. Never a stack trace. */
export default function ErrorState({ error, title, message, onRetry, compact, className }: Props) {
    const t = useT();
    const d = describeError(error, t);
    const Icon = ICON[d.kind] ?? AlertTriangle;
    const sessionExpired = d.kind === 'unauthenticated';
    return (
        <div role="alert" className={cn('flex flex-col items-center text-center', compact ? 'px-4 py-6' : 'px-4 py-12', className)}>
            <Icon className={cn('mb-3 text-red-700', compact ? 'h-8 w-8' : 'h-10 w-10')} aria-hidden="true" />
            <p className="text-base font-semibold text-slate-900">{title ?? d.title}</p>
            <p className="mt-1 max-w-md text-sm text-slate-700">{message ?? d.message}</p>
            {d.requestId && <p className="mt-2 font-mono text-xs text-slate-600">{t('states.request_id', { id: d.requestId })}</p>}
            <div className="mt-4 flex flex-wrap justify-center gap-2">
                {sessionExpired && <a href="/login" className="btn-primary">{t('states.sign_in')}</a>}
                {d.kind === 'expired' && <button type="button" className="btn-primary" onClick={() => window.location.reload()}>{t('states.page.419.action')}</button>}
                {onRetry && d.retryable && !sessionExpired && d.kind !== 'expired' && (
                    <button type="button" className="btn-primary" onClick={onRetry}>
                        <RefreshCw className="h-4 w-4" aria-hidden="true" /> {t('states.retry')}
                    </button>
                )}
            </div>
        </div>
    );
}
