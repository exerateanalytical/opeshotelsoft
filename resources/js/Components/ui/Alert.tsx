import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import type { ElementType, ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Variant = 'info' | 'success' | 'warning' | 'danger';

interface Props {
    variant?: Variant;
    title?: ReactNode;
    /** Optional action (a button or link) shown under the text. */
    action?: ReactNode;
    /** When given, a close button is shown and calls this. */
    onDismiss?: () => void;
    /** Replace the default icon. */
    icon?: ElementType;
    className?: string;
    children?: ReactNode;
}

const ICON: Record<Variant, ElementType> = { info: Info, success: CheckCircle2, warning: AlertTriangle, danger: XCircle };

/**
 * The one callout. Flat, token colours (AA contrast), an icon plus text so the status is never colour alone.
 * danger and warning announce as role="alert"; info and success as role="status".
 */
export default function Alert({ variant = 'info', title, action, onDismiss, icon, className, children }: Props) {
    const t = useT();
    const Icon = icon ?? ICON[variant];
    const urgent = variant === 'danger' || variant === 'warning';
    return (
        <div role={urgent ? 'alert' : 'status'} className={cn('alert', `alert-${variant}`, className)}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div className="min-w-0 flex-1">
                {title && <p className="alert-title">{title}</p>}
                {children}
                {action && <div className="mt-2">{action}</div>}
            </div>
            {onDismiss && (
                <button type="button" onClick={onDismiss} aria-label={t('ui.nav.close')} className="btn-ghost btn-icon btn-sm -my-1 -mr-2 shrink-0">
                    <X className="h-4 w-4" aria-hidden="true" />
                </button>
            )}
        </div>
    );
}
