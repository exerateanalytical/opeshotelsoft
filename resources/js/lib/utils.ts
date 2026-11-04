import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/* ---------- Number, money, percentage and date formatting (UI-DIRECTIVE 11 and 24) ----------
 * One place for every figure on screen. Thousands use a narrow no-break space (U+202F) in both
 * languages so columns line up; negatives use a real minus sign (U+2212); XAF has no decimals
 * unless the amount carries them. The language comes from <html lang> unless passed explicitly. */

export type UiLocale = 'fr' | 'en';
const NNBSP = ' ';
const MINUS = '−';

export function currentLocale(): UiLocale {
    if (typeof document === 'undefined') return 'fr';
    return document.documentElement.lang?.toLowerCase().startsWith('en') ? 'en' : 'fr';
}

const intlTag = (l: UiLocale) => (l === 'en' ? 'en-GB' : 'fr-FR');

function group(intPart: string): string {
    return intPart.replace(/\B(?=(\d{3})+(?!\d))/g, NNBSP);
}

/** Plain number: 12 500, 1 250,5 (fr) or 1 250.5 (en). */
export function formatNumber(value: number | string | null | undefined, opts: { decimals?: number; locale?: UiLocale; signed?: boolean } = {}): string {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return '—';
    const locale = opts.locale ?? currentLocale();
    const decimals = opts.decimals ?? (Number.isInteger(n) ? 0 : Math.min(2, (String(Math.abs(n)).split('.')[1] ?? '').length));
    const fixed = Math.abs(n).toFixed(decimals);
    const [i, d] = fixed.split('.');
    const body = group(i) + (d ? (locale === 'en' ? '.' : ',') + d : '');
    const isZero = Number(fixed) === 0;
    if (n < 0 && !isZero) return MINUS + body;
    if (opts.signed && n > 0 && !isZero) return '+' + body;
    return body;
}

/** Money: 12 500 XAF. Other ISO codes keep two decimals when they have cents. */
export function formatCurrency(amount: number | string | null | undefined, currency = 'XAF', locale?: UiLocale): string {
    const n = Number(amount ?? 0);
    const zeroDecimal = currency === 'XAF' || currency === 'XOF';
    const decimals = zeroDecimal ? (Number.isInteger(n) ? 0 : 2) : (Number.isInteger(n) ? 0 : 2);
    return `${formatNumber(n, { decimals, locale })}${NNBSP}${currency}`;
}

/** Short money for chart axes: 1,2 M, 850 k. */
export function formatCompact(value: number, locale?: UiLocale): string {
    const l = locale ?? currentLocale();
    const a = Math.abs(value);
    if (a >= 1e6) return `${formatNumber(Math.round(value / 1e5) / 10, { locale: l })}${NNBSP}M`;
    if (a >= 1e3) return `${formatNumber(Math.round(value / 1e3), { locale: l })}${NNBSP}k`;
    return formatNumber(value, { locale: l });
}

/** Percentage: 72,5 % (fr) or 72.5% (en). */
export function formatPercent(value: number | string | null | undefined, opts: { decimals?: number; locale?: UiLocale } = {}): string {
    const locale = opts.locale ?? currentLocale();
    const body = formatNumber(Number(value ?? 0), { decimals: opts.decimals ?? (Number.isInteger(Number(value ?? 0)) ? 0 : 1), locale });
    return locale === 'en' ? `${body}%` : `${body}${NNBSP}%`;
}

export function formatDate(date: string | Date, locale?: UiLocale): string {
    return new Intl.DateTimeFormat(intlTag(locale ?? currentLocale()), { dateStyle: 'medium' }).format(new Date(date));
}

/** Short day for chart axes and dense tables: 4 nov. / 4 Nov. */
export function formatDayShort(date: string | Date, locale?: UiLocale): string {
    const d = typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) ? new Date(`${date}T00:00:00`) : new Date(date);
    return new Intl.DateTimeFormat(intlTag(locale ?? currentLocale()), { day: 'numeric', month: 'short' }).format(d);
}

export function formatDateTime(date: string | Date, locale?: UiLocale): string {
    return new Intl.DateTimeFormat(intlTag(locale ?? currentLocale()), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(date));
}

export function initials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}
