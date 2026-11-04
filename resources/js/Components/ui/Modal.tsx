import { X } from 'lucide-react';
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Props {
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    /** Sticky footer, usually the action buttons. */
    footer?: ReactNode;
    size?: 'md' | 'lg' | 'xl';
    /** `dialog` is a bottom sheet on phones and a centred dialog from 768px. `sheet` is always a bottom sheet. */
    variant?: 'dialog' | 'sheet';
    /** id of a `<form>` inside the body: submit buttons in the footer are bound to it (`form` attribute), so they can submit from outside the body. */
    formId?: string;
}

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Accessible modal: role=dialog, labelled, Escape closes, focus is trapped and restored, body scroll locked.
 * Renders as a bottom Sheet on phones.
 */
export default function Modal({ open, onClose, title, children, footer, size = 'md', variant = 'dialog', formId }: Props) {
    const t = useT();
    const footerRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!open || !formId) return;
        footerRef.current?.querySelectorAll<HTMLButtonElement>('button[type="submit"]:not([form])').forEach((b) => b.setAttribute('form', formId));
    });
    const titleId = useId();
    const panel = useRef<HTMLDivElement>(null);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    useEffect(() => {
        if (!open) return;
        const prev = document.activeElement as HTMLElement | null;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const first = panel.current?.querySelector<HTMLElement>('[data-autofocus]') ?? panel.current?.querySelector<HTMLElement>(FOCUSABLE);
        (first ?? panel.current)?.focus();
        const key = (e: KeyboardEvent) => {
            if (e.key === 'Escape') { e.stopPropagation(); onCloseRef.current(); return; }
            if (e.key !== 'Tab' || !panel.current) return;
            const items = [...panel.current.querySelectorAll<HTMLElement>(FOCUSABLE)];
            if (!items.length) { e.preventDefault(); return; }
            const a = items[0], z = items[items.length - 1];
            if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
            else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
        };
        document.addEventListener('keydown', key);
        return () => {
            document.removeEventListener('keydown', key);
            document.body.style.overflow = overflow;
            prev?.focus?.();
        };
    }, [open]);

    if (!open || typeof document === 'undefined') return null;

    return createPortal(
        <div className={cn('overlay', variant === 'sheet' ? 'is-sheet' : 'is-modal')} onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div ref={panel} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1} className={cn('sheet-panel', `size-${size}`)}>
                <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <h2 id={titleId} className="text-lg font-semibold text-slate-900">{title}</h2>
                    <button type="button" onClick={onClose} aria-label={t('ui.modal.close')} className="btn-ghost btn-icon -mr-2">
                        <X className="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
                <div className="scroll-pane flex-1 px-4 py-4">{children}</div>
                {footer && <div ref={footerRef} className="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-white px-4 py-3">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}

/** Always a bottom sheet (phone More menu, Filters sheet). */
export function Sheet(props: Omit<Props, 'variant'>) {
    return <Modal {...props} variant="sheet" />;
}
