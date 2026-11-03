import { SlidersHorizontal } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useIsPhone } from '@/lib/useMediaQuery';
import { useT } from '@/lib/i18n';
import Button from './Button';
import { Sheet } from './Modal';

interface Props {
    children: ReactNode;
    /** Number of filters currently applied; shown on the phone button. */
    activeCount?: number;
    onClear?: () => void;
    /** Optional search box kept visible on phones next to the Filters button. */
    search?: ReactNode;
    className?: string;
}

/**
 * Filters row. From 768px the controls wrap on one or more lines and keep their size.
 * On phones they collapse into a "Filters" bottom sheet with an active-filter count.
 * Put each control in a <Field> so labels stay visible.
 */
export default function FilterBar({ children, activeCount = 0, onClear, search, className }: Props) {
    const t = useT();
    const phone = useIsPhone();
    const [open, setOpen] = useState(false);

    if (!phone) {
        return (
            <div role="search" className={`mb-4 flex flex-wrap items-end gap-3 ${className ?? ''}`}>
                {search}
                {children}
                {onClear && activeCount > 0 && <Button variant="ghost" size="sm" onClick={onClear}>{t('ui.filters.clear')}</Button>}
            </div>
        );
    }
    return (
        <div className={`mb-4 flex items-center gap-2 ${className ?? ''}`}>
            {search && <div className="min-w-0 flex-1">{search}</div>}
            <Button variant="secondary" icon={SlidersHorizontal} onClick={() => setOpen(true)} className={search ? '' : 'flex-1'}>
                {t('ui.filters')}
                {activeCount > 0 && <span className="badge-navy" aria-label={t('ui.filters.active', { count: activeCount })}>{activeCount}</span>}
            </Button>
            <Sheet open={open} onClose={() => setOpen(false)} title={t('ui.filters')}
                footer={<>
                    {onClear && <Button variant="ghost" onClick={onClear}>{t('ui.filters.clear')}</Button>}
                    <Button onClick={() => setOpen(false)}>{t('ui.filters.apply')}</Button>
                </>}>
                <div className="grid gap-4">{children}</div>
            </Sheet>
        </div>
    );
}
