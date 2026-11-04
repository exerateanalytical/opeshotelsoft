import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Inline field error that never moves the layout: the slot always reserves one line, and the message appears inside it with
 * no animation. Link it to the control with aria-describedby={id} and aria-invalid.
 */
export default function FieldError({ id, message, className }: { id?: string; message?: string | null; className?: string }) {
    return (
        <p id={id} role={message ? 'alert' : undefined} className={cn('mt-1 flex min-h-[1.25rem] items-start gap-1.5 text-sm text-red-700', className)}>
            {message && <><AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{message}</span></>}
        </p>
    );
}
