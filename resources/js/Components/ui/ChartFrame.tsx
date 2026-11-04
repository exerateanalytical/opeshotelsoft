import { useId, type ReactNode } from 'react';
import { ResponsiveContainer } from 'recharts';
import { useIsPhone } from '@/lib/useMediaQuery';
import { useLocale, useT } from '@/lib/i18n';
import { cn, formatCompact, formatCurrency, formatDayShort, formatNumber, formatPercent, type UiLocale } from '@/lib/utils';
import EmptyState from './EmptyState';
import Skeleton from './Skeleton';

/**
 * Shared chart kit (UI-DIRECTIVE 24). Flat: no fill washes, no effects, no animation when the
 * user asks for reduced motion. Colours are CSS tokens (--chart-1..6) and every series also
 * carries its own dash pattern and marker shape so it can be told apart without colour.
 */

export type SeriesFormat = 'currency' | 'percent' | 'number';

export interface ChartSeries {
    key: string;
    label: string;
    format?: SeriesFormat;
}

export const CHART_COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)', 'var(--chart-6)'];
export const CHART_DASHES = ['0', '6 3', '2 3', '8 3 2 3', '4 4', '1 2'];
const MARKERS = ['circle', 'square', 'triangle', 'diamond', 'circle', 'square'] as const;

export const seriesColor = (i: number) => CHART_COLORS[i % CHART_COLORS.length];
export const seriesDash = (i: number) => CHART_DASHES[i % CHART_DASHES.length];

export function formatValue(v: unknown, f: SeriesFormat = 'number', locale?: UiLocale): string {
    const n = Number(v ?? 0);
    if (f === 'currency') return formatCurrency(n, 'XAF', locale);
    if (f === 'percent') return formatPercent(n, { locale });
    return formatNumber(n, { locale });
}

/** Axis tick formatter for a series format (compact money, percentages, plain numbers). */
export function axisFormatter(f: SeriesFormat = 'number', locale?: UiLocale) {
    return (v: number) => (f === 'currency' ? formatCompact(v, locale) : f === 'percent' ? formatPercent(v, { locale, decimals: 0 }) : formatCompact(v, locale));
}

export const dayTick = (locale?: UiLocale) => (d: string) => { try { return formatDayShort(d, locale); } catch { return String(d); } };

/** Common axis props: high-contrast text, no tick marks, at most 4 ticks on phones. */
export function useAxisProps() {
    const phone = useIsPhone();
    const tick = { fontSize: 13, fill: 'var(--chart-axis)' };
    return {
        phone,
        x: { tick, tickLine: false, axisLine: { stroke: 'var(--chart-grid)' }, interval: 'preserveStartEnd' as const, padding: { left: phone ? 20 : 16, right: phone ? 20 : 16 }, dy: 4, minTickGap: phone ? 32 : 16, tickCount: phone ? 4 : undefined },
        y: { tick, tickLine: false, axisLine: false, width: phone ? 48 : 60, tickCount: phone ? 4 : 5 },
        grid: { stroke: 'var(--chart-grid)', strokeDasharray: '3 3', vertical: false },
    };
}

function Marker({ i }: { i: number }) {
    const c = seriesColor(i);
    const shape = MARKERS[i % MARKERS.length];
    return (
        <svg width="28" height="12" viewBox="0 0 28 12" aria-hidden="true">
            <line x1="0" y1="6" x2="28" y2="6" stroke={c} strokeWidth="2.5" strokeDasharray={seriesDash(i)} />
            {shape === 'circle' && <circle cx="14" cy="6" r="3.5" fill={c} />}
            {shape === 'square' && <rect x="10.5" y="2.5" width="7" height="7" fill={c} />}
            {shape === 'triangle' && <path d="M14 2 L18 10 L10 10 Z" fill={c} />}
            {shape === 'diamond' && <path d="M14 1.5 L18.5 6 L14 10.5 L9.5 6 Z" fill={c} />}
        </svg>
    );
}

export function ChartLegend({ series }: { series: ChartSeries[] }) {
    if (series.length < 2) return null;
    return (
        <ul className="l1-chart-legend">
            {series.map((s, i) => <li key={s.key}><Marker i={i} />{s.label}</li>)}
        </ul>
    );
}

/** Readable tooltip: white card, formatted label (date) and values. Pass as <Tooltip content={...} />. */
export function makeTooltip(series: ChartSeries[], labelFormat: (l: string) => string, locale?: UiLocale) {
    return function ChartTip({ active, payload, label }: { active?: boolean; payload?: { dataKey?: string | number; value?: unknown }[]; label?: string | number }) {
        if (!active || !payload?.length) return null;
        return (
            <dl className="l1-chart-tip">
                <dt>{labelFormat(String(label ?? ''))}</dt>
                {payload.map((p) => {
                    const i = series.findIndex((s) => s.key === p.dataKey);
                    const s = series[i];
                    if (!s) return null;
                    return <dd key={s.key}><Marker i={i} /><span>{s.label}</span><strong className="ml-auto pl-3">{formatValue(p.value, s.format, locale)}</strong></dd>;
                })}
            </dl>
        );
    };
}

interface FrameProps<Row> {
    title: string;
    description?: string;
    /** Rows for the screen-reader table fallback. */
    data: Row[];
    series: ChartSeries[];
    /** Key of the category / date column, and how to show it. */
    xKey: keyof Row & string;
    xLabel: string;
    xFormat?: (v: string) => string;
    loading?: boolean;
    error?: ReactNode;
    empty?: ReactNode;
    /** Heights in px: phone and wider. */
    height?: { phone: number; desktop: number };
    className?: string;
    headingLevel?: 2 | 3;
    actions?: ReactNode;
    children: ReactNode;
}

export default function ChartFrame<Row extends Record<string, unknown>>({
    title, description, data, series, xKey, xLabel, xFormat, loading, error, empty, height = { phone: 220, desktop: 280 },
    className, headingLevel = 3, actions, children,
}: FrameProps<Row>) {
    const t = useT();
    const locale = useLocale();
    const phone = useIsPhone();
    const id = useId();
    const H = headingLevel === 2 ? 'h2' : 'h3';
    const h = phone ? height.phone : height.desktop;
    const fx = xFormat ?? ((v: string) => v);

    let body: ReactNode;
    if (loading && data.length === 0) body = <div style={{ height: h }} role="status" aria-busy="true"><span className="l1-sr-only">{t('ui2.chart_loading')}</span><Skeleton className="h-full" /></div>;
    else if (error) body = typeof error === 'string' ? <p role="alert" className="l1-support text-red-700">{error}</p> : error;
    else if (data.length === 0) body = empty ?? <EmptyState title={t('ui2.chart_empty')} />;
    else body = (
        <>
            <div className="l1-chart-body" role="img" aria-labelledby={`${id}-t`} aria-describedby={description ? `${id}-d` : undefined} style={{ height: h }}>
                <ResponsiveContainer width="100%" height="100%">{children as React.ReactElement}</ResponsiveContainer>
            </div>
            <ChartLegend series={series} />
            <table className="l1-sr-only">
                <caption>{title}</caption>
                <thead><tr><th scope="col">{xLabel}</th>{series.map((s) => <th key={s.key} scope="col">{s.label}</th>)}</tr></thead>
                <tbody>
                    {data.map((r, i) => (
                        <tr key={i}><th scope="row">{fx(String(r[xKey] ?? ''))}</th>{series.map((s) => <td key={s.key}>{formatValue(r[s.key], s.format, locale)}</td>)}</tr>
                    ))}
                </tbody>
            </table>
        </>
    );

    return (
        <section className={cn('l1-chart', className)} aria-labelledby={`${id}-t`}>
            <div className="l1-chart-head flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <H id={`${id}-t`}>{title}</H>
                    {description && <p id={`${id}-d`}>{description}</p>}
                </div>
                {actions}
            </div>
            {body}
        </section>
    );
}
