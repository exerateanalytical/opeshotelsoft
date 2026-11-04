import { ReactNode } from 'react';
import { activeHotelId } from '@/lib/api';
import { cn } from '@/lib/utils';

/** Small helpers for the back-office finance gap screens (tax, payables, close, dunning, bank rules). */

/** Authenticated blob fetch (the API needs the hotel header), returned as an object URL for preview. */
export async function fetchBlobUrl(path: string): Promise<{ url: string; type: string }> {
    const hotel = activeHotelId();
    const res = await fetch(`/api/v1/${path.replace(/^\//, '')}`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
    });
    if (!res.ok) throw new Error(String(res.status));
    const blob = await res.blob();
    return { url: URL.createObjectURL(blob), type: blob.type };
}

/** Desktop table that turns into a card list under 768 px (cells need a `label`). */
export function CardTable({ caption, head, children }: { caption: string; head: string[]; children: ReactNode }) {
    return (
        <div className="table-wrap">
            <table className="table-auto table-cards w-full text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead><tr>{head.map((h, i) => <th key={i} scope="col" className="p-2 text-left text-slate-500">{h}</th>)}</tr></thead>
                <tbody className="[&>tr]:border-t [&>tr]:border-slate-100">{children}</tbody>
            </table>
        </div>
    );
}

export function C({ label, children, r, mono, b }: { label: string; children?: ReactNode; r?: boolean; mono?: boolean; b?: boolean }) {
    return <td data-label={label} className={cn('p-2', r && 'num text-right whitespace-nowrap', mono && 'font-mono', b && 'font-semibold')}>{children}</td>;
}

export function SectionTitle({ children, hint }: { children: ReactNode; hint?: ReactNode }) {
    return (
        <div className="mb-3">
            <h2 className="l1-section-title font-semibold">{children}</h2>
            {hint && <p className="text-sm text-slate-500">{hint}</p>}
        </div>
    );
}

export const yesNo = (v: unknown) => (v === true || v === 1 || v === '1' || v === 't');
