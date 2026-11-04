import { ArrowDown, ArrowUp, ChevronsUpDown, Columns3, MoreVertical, Rows3, X } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { readJson, safeGet, safeSet } from '@/lib/desktop';
import { useT } from '@/lib/i18n';
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
    /** Sortable column: value used to sort rows on the client (or pass `sort` + `onSortChange` to sort on the server). */
    sortValue?: (row: T) => string | number | null | undefined;
    /** Show the sort control even when no client `sortValue` exists (server sorting). */
    sortable?: boolean;
    /** Never offered in the column visibility menu (the first column and action columns are always kept). */
    required?: boolean;
    /** Hidden until the user turns it on in the column menu. */
    defaultHidden?: boolean;
    /** Initial width in px (only applied after the user resizes, or when set explicitly). */
    width?: number;
}

export interface RowAction<T = unknown> {
    key: string;
    label: string;
    icon?: React.ElementType;
    onSelect: (row: T) => void;
    danger?: boolean;
    disabled?: boolean;
    hidden?: boolean;
}

export type SortState = { key: string; dir: 'asc' | 'desc' } | null;

interface Props<T> {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    caption: string;
    loading?: boolean;
    empty?: ReactNode;
    /** Error slot: shown instead of the rows. A string is rendered as an alert. */
    error?: ReactNode;
    /** Keep the first column visible while scrolling sideways (tablet and desktop). */
    stickyFirst?: boolean;
    onRowClick?: (row: T) => void;
    className?: string;
    /** Persist column visibility, widths (localStorage) and show the toolbar (columns, density) on desktop. */
    tableKey?: string;
    /** Keep the header in view while the table scrolls inside its own frame (default true from 1024px). */
    stickyHeader?: boolean;
    /** Row selection with checkboxes; `bulkActions` renders the actions slot of the bar. */
    selectable?: boolean;
    bulkActions?: (ctx: { rows: T[]; keys: (string | number)[]; clear: () => void }) => ReactNode;
    /** Row menu: right-click, Shift+F10 or the kebab button. */
    rowActions?: (row: T) => RowAction<T>[];
    /** Highlights the row whose detail is open (master-detail). */
    activeKey?: string | number | null;
    /** Column resize by dragging (and arrow keys on the handle), desktop only. Default true when tableKey is set. */
    resizable?: boolean;
    /** Controlled sort. Without it the table sorts the rows itself using `sortValue`. */
    sort?: SortState;
    onSortChange?: (s: SortState) => void;
    /** Accessible name for a row in menus and selection (defaults to the first column text). */
    rowLabel?: (row: T) => string;
    /** Extra toolbar content, left side. */
    toolbar?: ReactNode;
}

interface Stored { hidden?: string[]; widths?: Record<string, number> }
const DENSITY_KEY = 'opes.density';
const tableStore = (k: string) => `opes.table.${k}`;

/**
 * Table that scrolls inside its own wrapper and becomes a card list under 768px (each cell carries data-label).
 * Use for any list with more than three columns. Desktop extras are all optional and opt in: sticky header,
 * column menu and widths remembered per `tableKey`, density, sorting with aria-sort, row selection with a bulk bar,
 * a row menu (right-click, kebab or Shift+F10), row keyboard navigation and loading, empty and error slots.
 */
export default function DataTable<T>({
    columns, rows, rowKey, caption, loading, empty, error, stickyFirst, onRowClick, className, tableKey, stickyHeader = true,
    selectable, bulkActions, rowActions, activeKey, resizable, sort, onSortChange, rowLabel, toolbar,
}: Props<T>) {
    const t = useT();
    const uid = useId();
    const stored = useMemo<Stored>(() => (tableKey ? readJson<Stored>(tableStore(tableKey), {}) : {}), [tableKey]);
    const [hidden, setHidden] = useState<string[]>(() => stored.hidden ?? columns.filter((c) => c.defaultHidden).map((c) => c.key));
    const [widths, setWidths] = useState<Record<string, number>>(() => stored.widths ?? {});
    const [density, setDensity] = useState<'comfortable' | 'compact'>(() => (safeGet(DENSITY_KEY) === 'compact' ? 'compact' : 'comfortable'));
    const [innerSort, setInnerSort] = useState<SortState>(null);
    const [selected, setSelected] = useState<Set<string | number>>(new Set());
    const [focusIdx, setFocusIdx] = useState(0);
    const [menu, setMenu] = useState<{ row: T; x: number; y: number; opener: HTMLElement | null } | null>(null);
    const [colMenu, setColMenu] = useState(false);
    const body = useRef<HTMLTableSectionElement>(null);
    const colBox = useRef<HTMLDivElement>(null);

    const canResize = resizable ?? !!tableKey;
    const curSort = sort !== undefined ? sort : innerSort;
    const persist = (h: string[], w: Record<string, number>) => { if (tableKey) safeSet(tableStore(tableKey), JSON.stringify({ hidden: h, widths: w })); };

    const cols = useMemo(() => {
        const vis = columns.filter((c, i) => i === 0 || c.required || !hidden.includes(c.key));
        return vis.length ? vis : columns.slice(0, 1);
    }, [columns, hidden]);

    const sorted = useMemo(() => {
        if (sort !== undefined || !innerSort) return rows;
        const col = columns.find((c) => c.key === innerSort.key);
        if (!col?.sortValue) return rows;
        const f = col.sortValue;
        const dir = innerSort.dir === 'asc' ? 1 : -1;
        return [...rows].sort((a, b) => {
            const x = f(a), y = f(b);
            if (x == null && y == null) return 0;
            if (x == null) return 1;
            if (y == null) return -1;
            return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })) * dir;
        });
    }, [rows, columns, innerSort, sort]);

    // Drop selections that no longer exist (page change, filter).
    useEffect(() => {
        setSelected((s) => {
            if (!s.size) return s;
            const keys = new Set(rows.map(rowKey));
            const next = new Set([...s].filter((k) => keys.has(k)));
            return next.size === s.size ? s : next;
        });
        setFocusIdx(0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows]);

    useEffect(() => {
        if (!colMenu) return;
        const out = (e: MouseEvent) => colBox.current && !colBox.current.contains(e.target as Node) && setColMenu(false);
        const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.stopPropagation(); setColMenu(false); colBox.current?.querySelector<HTMLElement>('button')?.focus(); } };
        document.addEventListener('mousedown', out);
        document.addEventListener('keydown', esc, true);
        return () => { document.removeEventListener('mousedown', out); document.removeEventListener('keydown', esc, true); };
    }, [colMenu]);

    const interactive = !!(onRowClick || rowActions || selectable);
    const label = (row: T) => rowLabel?.(row) ?? String((row as Record<string, unknown>)[columns[0].key] ?? '');
    const allKeys = sorted.map(rowKey);
    const allSelected = allKeys.length > 0 && allKeys.every((k) => selected.has(k));
    const someSelected = selected.size > 0 && !allSelected;
    const toolbarOn = !!tableKey || !!toolbar;

    const toggleSort = (c: Column<T>) => {
        const cur = curSort?.key === c.key ? curSort : null;
        const next: SortState = !cur ? { key: c.key, dir: 'asc' } : cur.dir === 'asc' ? { key: c.key, dir: 'desc' } : null;
        if (onSortChange) onSortChange(next);
        if (sort === undefined) setInnerSort(next);
    };
    const toggleRow = (k: string | number) => setSelected((s) => { const n = new Set(s); if (n.has(k)) n.delete(k); else n.add(k); return n; });
    const clear = () => setSelected(new Set());

    const setDensityPersist = (d: 'comfortable' | 'compact') => { setDensity(d); safeSet(DENSITY_KEY, d === 'compact' ? 'compact' : null); };
    const toggleCol = (key: string) => {
        const h = hidden.includes(key) ? hidden.filter((k) => k !== key) : [...hidden, key];
        setHidden(h); persist(h, widths);
    };
    const resizeTo = (key: string, w: number, save = false) => {
        const next = { ...widths, [key]: Math.max(64, Math.min(640, Math.round(w))) };
        setWidths(next);
        if (save) persist(hidden, next);
    };
    const startDrag = (e: React.PointerEvent, key: string) => {
        e.preventDefault();
        e.stopPropagation();
        const th = (e.currentTarget as HTMLElement).closest('th') as HTMLElement;
        const startX = e.clientX;
        const startW = th.getBoundingClientRect().width;
        let last = startW;
        const move = (ev: PointerEvent) => { last = startW + ev.clientX - startX; resizeTo(key, last); };
        const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); resizeTo(key, last, true); };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    };

    const focusRow = (i: number) => {
        const n = Math.max(0, Math.min(sorted.length - 1, i));
        setFocusIdx(n);
        body.current?.querySelectorAll<HTMLElement>(':scope > tr')[n]?.focus();
    };
    const openMenu = (row: T, x: number, y: number, opener: HTMLElement | null) => setMenu({ row, x, y, opener });
    const onBodyKey = (e: React.KeyboardEvent) => {
        const tr = (e.target as HTMLElement).closest('tr');
        if (!tr || !body.current?.contains(tr)) return;
        const i = [...body.current.children].indexOf(tr);
        const row = sorted[i];
        if (!row) return;
        if (rowActions && ((e.shiftKey && e.key === 'F10') || e.key === 'ContextMenu')) {
            e.preventDefault();
            const r = tr.getBoundingClientRect();
            openMenu(row, r.left + 24, r.top + r.height / 2, tr as HTMLElement);
            return;
        }
        if (e.target !== tr) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); focusRow(i + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); focusRow(i - 1); }
        else if (e.key === 'Home') { e.preventDefault(); focusRow(0); }
        else if (e.key === 'End') { e.preventDefault(); focusRow(sorted.length - 1); }
        else if (e.key === 'Enter' && onRowClick) { e.preventDefault(); onRowClick(row); }
        else if (e.key === ' ' && selectable) { e.preventDefault(); toggleRow(rowKey(row)); }
    };

    if (loading) return <div className={cn('card', className)} aria-busy="true"><SkeletonRows /></div>;
    if (error) {
        return (
            <div className={cn('card', className)}>
                <div role="alert" className="px-4 py-6 text-center text-sm text-red-700">{error}</div>
            </div>
        );
    }
    if (!rows.length) return <div className={cn('card', className)}>{empty ?? <EmptyState />}</div>;

    const selectedRows = sorted.filter((r) => selected.has(rowKey(r)));
    const hideable = columns.filter((c, i) => i > 0 && !c.required && c.header);

    return (
        <div className={cn('card overflow-hidden', className)}>
            {toolbarOn && (
                <div className="n1-table-toolbar">
                    <div className="min-w-0 flex-1">{toolbar}</div>
                    {tableKey && (
                        <>
                            <div ref={colBox} className="relative">
                                <button type="button" className="btn-ghost btn-sm" aria-haspopup="true" aria-expanded={colMenu} aria-controls={`${uid}-cols`} onClick={() => setColMenu(!colMenu)}>
                                    <Columns3 className="h-4 w-4" aria-hidden="true" /> {t('desktop.table.columns')}
                                </button>
                                {colMenu && (
                                    <div id={`${uid}-cols`} role="group" aria-label={t('desktop.table.columns')} className="n1-popover">
                                        {hideable.map((c) => (
                                            <label key={c.key} className="flex min-h-9 cursor-pointer items-center gap-2 px-3 text-sm text-slate-800 hover:bg-slate-50">
                                                <input type="checkbox" checked={!hidden.includes(c.key)} onChange={() => toggleCol(c.key)} /> {c.header}
                                            </label>
                                        ))}
                                        <button type="button" className="mt-1 w-full border-t border-slate-200 px-3 py-2 text-left text-sm text-navy-600 hover:bg-slate-50"
                                            onClick={() => { const h = columns.filter((c) => c.defaultHidden).map((c) => c.key); setHidden(h); setWidths({}); persist(h, {}); }}>
                                            {t('desktop.table.reset')}
                                        </button>
                                    </div>
                                )}
                            </div>
                            <div role="group" aria-label={t('desktop.table.density')} className="flex items-center">
                                <Rows3 className="mr-1 h-4 w-4 text-slate-600" aria-hidden="true" />
                                {(['comfortable', 'compact'] as const).map((d) => (
                                    <button key={d} type="button" aria-pressed={density === d} onClick={() => setDensityPersist(d)}
                                        className={cn('n1-seg', density === d && 'is-on')}>{t(`desktop.table.${d}`)}</button>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            )}

            {selectable && selected.size > 0 && (
                <div role="region" aria-label={t('desktop.table.bulk')} className="n1-bulkbar">
                    <span className="text-sm font-semibold" role="status">{t('desktop.table.selected', { count: selected.size })}</span>
                    <div className="flex flex-wrap items-center gap-2">{bulkActions?.({ rows: selectedRows, keys: [...selected], clear })}</div>
                    <button type="button" className="btn-ghost btn-sm ml-auto" onClick={clear}><X className="h-4 w-4" aria-hidden="true" /> {t('desktop.table.clear')}</button>
                </div>
            )}

            <div className={cn('table-wrap', stickyFirst && 'sticky-first', stickyHeader && 'n1-sticky-head', density === 'compact' && 'n1-compact')}>
                <table className="table-std table-cards">
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr>
                            {selectable && (
                                <th scope="col" className="n1-col-select max-lg:hidden">
                                    <input type="checkbox" aria-label={t('desktop.table.select_all')} checked={allSelected}
                                        ref={(el) => { if (el) el.indeterminate = someSelected; }}
                                        onChange={() => setSelected(allSelected ? new Set() : new Set(allKeys))} />
                                </th>
                            )}
                            {cols.map((c, ci) => {
                                const sortable = !!(c.sortValue || c.sortable);
                                const state = curSort?.key === c.key ? curSort.dir : null;
                                const w = widths[c.key] ?? c.width;
                                return (
                                    <th key={c.key} scope="col"
                                        aria-sort={sortable ? (state === 'asc' ? 'ascending' : state === 'desc' ? 'descending' : 'none') : undefined}
                                        style={w ? { width: w, minWidth: w, maxWidth: w } : undefined}
                                        className={cn(c.align === 'right' && 'text-right', c.className, canResize && 'n1-resizable')}>
                                        {sortable && c.header ? (
                                            <button type="button" className="n1-sort" onClick={() => toggleSort(c)} aria-label={t('desktop.table.sort_by', { col: c.header })}>
                                                {c.header}
                                                {state === 'asc' ? <ArrowUp className="h-3.5 w-3.5" aria-hidden="true" /> : state === 'desc' ? <ArrowDown className="h-3.5 w-3.5" aria-hidden="true" /> : <ChevronsUpDown className="h-3.5 w-3.5 text-slate-500" aria-hidden="true" />}
                                            </button>
                                        ) : c.header}
                                        {canResize && c.header && ci < cols.length && (
                                            <span role="separator" aria-orientation="vertical" tabIndex={0} className="n1-resize-handle"
                                                aria-label={t('desktop.table.resize', { col: c.header })}
                                                aria-valuenow={Math.round(w ?? 0)} aria-valuemin={64} aria-valuemax={640}
                                                onPointerDown={(e) => startDrag(e, c.key)}
                                                onKeyDown={(e) => {
                                                    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
                                                    e.preventDefault();
                                                    const cur = w ?? (e.currentTarget.closest('th') as HTMLElement).getBoundingClientRect().width;
                                                    resizeTo(c.key, cur + (e.key === 'ArrowRight' ? 16 : -16), true);
                                                }} />
                                        )}
                                    </th>
                                );
                            })}
                            {rowActions && <th scope="col" className="n1-col-actions max-md:hidden"><span className="sr-only">{t('desktop.table.actions')}</span></th>}
                        </tr>
                    </thead>
                    <tbody ref={body} onKeyDown={onBodyKey}>
                        {sorted.map((row, i) => {
                            const k = rowKey(row);
                            const isSel = selected.has(k);
                            return (
                                <tr key={k}
                                    tabIndex={interactive ? (i === focusIdx ? 0 : -1) : undefined}
                                    onFocus={interactive ? (e) => { if (e.target === e.currentTarget) setFocusIdx(i); } : undefined}
                                    onClick={onRowClick ? (e) => { if (!(e.target as HTMLElement).closest('a,button,input,select,textarea,label,[role="menu"]')) onRowClick(row); } : undefined}
                                    onContextMenu={rowActions ? (e) => { e.preventDefault(); openMenu(row, e.clientX, e.clientY, e.currentTarget); } : undefined}
                                    aria-current={activeKey != null && activeKey === k ? 'true' : undefined}
                                    className={cn(onRowClick && 'cursor-pointer', interactive && 'n1-row', isSel && 'is-selected', activeKey != null && activeKey === k && 'is-active')}>
                                    {selectable && (
                                        <td className="n1-col-select max-lg:hidden" data-label="">
                                            <input type="checkbox" aria-label={t('desktop.table.select_row', { name: label(row) })} checked={isSel} onChange={() => toggleRow(k)} />
                                        </td>
                                    )}
                                    {cols.map((c) => (
                                        <td key={c.key} data-label={c.hideLabel ? '' : c.header} className={cn(c.align === 'right' && 'text-right', c.className)}>
                                            {c.cell ? c.cell(row) : String((row as Record<string, unknown>)[c.key] ?? '')}
                                        </td>
                                    ))}
                                    {rowActions && (
                                        <td className="n1-col-actions max-md:hidden" data-label="">
                                            <button type="button" className="btn-ghost btn-icon" aria-haspopup="menu" aria-label={t('desktop.table.row_actions', { name: label(row) })}
                                                onClick={(e) => { const r = e.currentTarget.getBoundingClientRect(); openMenu(row, r.right, r.bottom, e.currentTarget); }}>
                                                <MoreVertical className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <div className="sr-only" role="status" aria-live="polite">{selectable ? t('desktop.table.selected', { count: selected.size }) : ''}</div>
            {menu && rowActions && <RowMenu x={menu.x} y={menu.y} actions={rowActions(menu.row).filter((a) => !a.hidden)} row={menu.row} label={t('desktop.table.row_actions', { name: label(menu.row) })}
                onClose={() => { menu.opener?.focus?.(); setMenu(null); }} />}
        </div>
    );
}

function RowMenu<T>({ x, y, actions, row, label, onClose }: { x: number; y: number; actions: RowAction<T>[]; row: T; label: string; onClose: () => void }) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ left: x, top: y });
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        setPos({ left: Math.max(8, Math.min(x, window.innerWidth - r.width - 8)), top: Math.max(8, Math.min(y, window.innerHeight - r.height - 8)) });
        el.querySelector<HTMLElement>('[role="menuitem"]:not([disabled])')?.focus();
        const out = (e: MouseEvent) => { if (!el.contains(e.target as Node)) onClose(); };
        const away = () => onClose();
        document.addEventListener('mousedown', out);
        window.addEventListener('resize', away);
        document.getElementById('main')?.addEventListener('scroll', away, { passive: true });
        return () => {
            document.removeEventListener('mousedown', out);
            window.removeEventListener('resize', away);
            document.getElementById('main')?.removeEventListener('scroll', away);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const items = () => [...(ref.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? [])];
    const onKey = (e: React.KeyboardEvent) => {
        const list = items();
        const i = list.indexOf(document.activeElement as HTMLElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length]?.focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length]?.focus(); }
        else if (e.key === 'Home') { e.preventDefault(); list[0]?.focus(); }
        else if (e.key === 'End') { e.preventDefault(); list[list.length - 1]?.focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); onClose(); }
        else if (e.key === 'Tab') { e.preventDefault(); onClose(); }
    };
    if (!actions.length) return null;
    return (
        <div ref={ref} role="menu" aria-label={label} className="n1-menu" style={pos} onKeyDown={onKey}>
            {actions.map((a) => {
                const Icon = a.icon;
                return (
                    <button key={a.key} type="button" role="menuitem" disabled={a.disabled} className={cn('n1-menu-item', a.danger && 'is-danger')}
                        onClick={() => { onClose(); a.onSelect(row); }}>
                        {Icon && <Icon className="h-4 w-4" aria-hidden="true" />} {a.label}
                    </button>
                );
            })}
        </div>
    );
}
