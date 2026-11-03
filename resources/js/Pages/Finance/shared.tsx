import { ReactNode } from 'react';
import { ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';

export interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }
export interface Account { id: number; code: string; name: string; class: number; type: string; postable: boolean; reconcilable: boolean; active: boolean }
export interface JournalLine { id: number; account_code: string; debit: number; credit: number; description: string | null; partner_type: string | null; partner_id: number | null; department: string | null; date: string }
export interface JournalEntry {
    id: number; journal_code: string; number: string; date: string; description: string; source_type: string | null;
    status: string; reverses_entry_id: number | null; reversed: boolean; total: number; lines: JournalLine[];
}
export interface Invoice {
    id: number; number: string; kind: string; company_id: number | null; guest_id: number | null; bill_to_name: string;
    issue_date: string; due_date: string | null; currency: string; total: number; tax_total: number; paid: number; status: string;
}

export const errMsg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

export const today = () => new Date().toISOString().slice(0, 10);
export const monthStart = () => today().slice(0, 8) + '01';

export function Banner({ error, success }: { error?: string | null; success?: string | null }) {
    return (
        <>
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            {success && <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{success}</div>}
        </>
    );
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block text-xs font-medium text-slate-500">
            {label}
            <div className="mt-1">{children}</div>
        </label>
    );
}

export function Loading({ on }: { on: boolean }) {
    const t = useT();
    return on ? <p className="py-6 text-center text-sm text-slate-400">{t('common.loading')}</p> : null;
}

export const STATUS_BADGE: Record<string, string> = {
    issued: 'bg-blue-50 text-blue-700', partially_paid: 'bg-amber-50 text-amber-700', paid: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-600', open: 'bg-emerald-50 text-emerald-700', closed: 'bg-slate-100 text-slate-600',
};

export function Badge({ s }: { s: string }) {
    const t = useT();
    const key = `fin.status.${s}`;
    const label = t(key);
    return <span className={`rounded px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[s] ?? 'bg-slate-100 text-slate-600'}`}>{label === key ? s.replace('_', ' ') : label}</span>;
}
