import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import EmptyState from './EmptyState';
import { SkeletonRows } from './Skeleton';

export interface Column<T> {
    key: string;
    header: string;
    /** Cell renderer; defaults to row[key]. */
    cell?: (row: T) => ReactNode;
    align?: 'left' | 'right';
    /** Hide the column label in phone card mode (use for the card title). */
    hideLabel?: boolean;
    className?: string;
}

interface Props<T> {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    caption: string;
    loading?: boolean;
    empty?: ReactNode;
    /** Keep the first column visible while scrolling sideways (tablet and desktop). */
    stickyFirst?: boolean;
    onRowClick?: (row: T) => void;
    className?: string;
}

/**
 * Table that scrolls inside its own wrapper and becomes a card list under 768px (each cell carries data-label).
 * Use for any list with more than three columns.
 */
export default function DataTable<T>({ columns, rows, rowKey, caption, loading, empty, stickyFirst, onRowClick, className }: Props<T>) {
    if (loading) return <div className={cn('card', className)}><SkeletonRows /></div>;
    if (!rows.length) return <div className={cn('card', className)}>{empty ?? <EmptyState />}</div>;
    return (
        <div className={cn('card overflow-hidden', className)}>
            <div className={cn('table-wrap', stickyFirst && 'sticky-first')}>
                <table className="table-std table-cards">
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr>{columns.map((c) => <th key={c.key} scope="col" className={cn(c.align === 'right' && 'text-right', c.className)}>{c.header}</th>)}</tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={rowKey(row)} onClick={onRowClick ? () => onRowClick(row) : undefined} className={cn(onRowClick && 'cursor-pointer')}>
                                {columns.map((c) => (
                                    <td key={c.key} data-label={c.hideLabel ? '' : c.header} className={cn(c.align === 'right' && 'text-right', c.className)}>
                                        {c.cell ? c.cell(row) : String((row as Record<string, unknown>)[c.key] ?? '')}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
