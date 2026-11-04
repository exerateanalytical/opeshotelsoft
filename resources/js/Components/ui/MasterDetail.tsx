import { X } from 'lucide-react';
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { dialogOpen } from '@/lib/desktop';
import { useT } from '@/lib/i18n';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { cn } from '@/lib/utils';

/** True from 1280px: list and detail sit side by side. Below that, pages keep full-page navigation. */
export const useSplitView = () => useMediaQuery('(min-width: 1280px)');

interface Props {
    /** The list (usually a DataTable). */
    children: ReactNode;
    /** Detail content; the panel is shown when `open`. */
    detail: ReactNode;
    open: boolean;
    title: string;
    onClose: () => void;
    className?: string;
}

/**
 * Master-detail split (1280px and wider): list on the left, detail panel on the right. Esc closes the panel and returns
 * focus to the selected row; on open, focus moves to the panel heading. Below 1280px the panel is not rendered.
 * Keep the selection in a query parameter with `useQueryParam` so the view is shareable.
 */
export default function MasterDetail({ children, detail, open, title, onClose, className }: Props) {
    const t = useT();
    const split = useSplitView();
    const head = useRef<HTMLHeadingElement>(null);
    const id = useId();
    const show = open && split;

    useEffect(() => {
        if (!show) return;
        head.current?.focus({ preventScroll: true });
        const esc = (e: KeyboardEvent) => {
            if (e.key !== 'Escape' || e.defaultPrevented || dialogOpen()) return;
            if (document.querySelector('[role="menu"]')) return;
            const typing = (e.target as HTMLElement)?.closest?.('input,textarea,select');
            if (typing && !(e.target as HTMLElement).closest(`[data-detail-panel]`)) return;
            e.preventDefault();
            onClose();
            setTimeout(() => document.querySelector<HTMLElement>('tr[aria-current="true"]')?.focus(), 0);
        };
        document.addEventListener('keydown', esc);
        return () => document.removeEventListener('keydown', esc);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);

    return (
        <div className={cn('n1-split', show && 'is-open', className)}>
            <div className="min-w-0">{children}</div>
            {show && (
                <aside data-detail-panel role="complementary" aria-labelledby={`${id}-h`} className="n1-detail card">
                    <div className="flex items-start justify-between gap-2 border-b border-slate-200 px-4 py-3">
                        <h2 id={`${id}-h`} ref={head} tabIndex={-1} className="min-w-0 truncate text-base font-semibold text-slate-900 focus:outline-none">{title}</h2>
                        <button type="button" className="btn-ghost btn-icon -mr-2 -mt-1" onClick={onClose} aria-label={t('desktop.detail.close')}><X className="h-5 w-5" aria-hidden="true" /></button>
                    </div>
                    <div className="px-4 py-3">{detail}</div>
                </aside>
            )}
        </div>
    );
}
