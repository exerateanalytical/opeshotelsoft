import { AlertCircle } from 'lucide-react';
import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface Aria { id: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }

interface Props {
    label: string;
    /** The control. Either an element (it receives id, aria-describedby, aria-invalid) or a render function. */
    children: ReactElement<Record<string, unknown>> | ((p: Aria) => ReactNode);
    hint?: string;
    error?: string | null;
    required?: boolean;
    className?: string;
}

/** Label, control, hint and error linked by id. Errors are announced (role=alert) and never rely on colour alone. */
export default function Field({ label, children, hint, error, required, className }: Props) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errId = `${id}-err`;
    const describedBy = [hint && hintId, error && errId].filter(Boolean).join(' ') || undefined;
    const aria: Aria = { id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined };
    const control = typeof children === 'function'
        ? children(aria)
        : isValidElement(children) ? cloneElement(children, { ...aria, required: required || children.props.required }) : children;

    return (
        <div className={cn('min-w-0', className)}>
            <label htmlFor={id} className="label">
                {label}
                {required && <span className="text-red-700" aria-hidden="true"> *</span>}
            </label>
            {control}
            {hint && <p id={hintId} className="field-hint">{hint}</p>}
            {error && (
                <p id={errId} role="alert" className="field-error">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </div>
    );
}
