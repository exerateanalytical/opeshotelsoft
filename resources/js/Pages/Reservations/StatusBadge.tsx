import { CheckCircle2, Clock, LogIn, LogOut, XCircle, Circle, UserX } from 'lucide-react';
import type { ElementType } from 'react';
import { cn } from '@/lib/utils';

const STYLE: Record<string, string> = {
    tentative: 'bg-amber-50 text-amber-800 border-amber-300',
    confirmed: 'bg-blue-50 text-blue-800 border-blue-300',
    checked_in: 'bg-emerald-50 text-emerald-800 border-emerald-300',
    checked_out: 'bg-slate-100 text-slate-700 border-slate-300',
    cancelled: 'bg-red-50 text-red-800 border-red-300',
    no_show: 'bg-red-50 text-red-800 border-red-300',
};
export const STATUS_ICON: Record<string, ElementType> = { tentative: Clock, confirmed: CheckCircle2, checked_in: LogIn, checked_out: LogOut, cancelled: XCircle, no_show: UserX };

/** Reservation status: colour plus icon plus label, never colour alone. */
export default function StatusBadge({ status, label }: { status: string; label: string }) {
    const I = STATUS_ICON[status] ?? Circle;
    return (
        <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded border px-2 py-0.5 text-xs font-medium', STYLE[status] ?? STYLE.checked_out)}>
            <I className="h-3.5 w-3.5" aria-hidden="true" />{label}
        </span>
    );
}
