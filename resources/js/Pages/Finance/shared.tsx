import { ReactNode } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import { ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

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
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            {success && <Alert variant="success" className="mb-4">{success}</Alert>}
        </>
    );
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block text-sm font-semibold text-slate-700">
            {label}
            <div className="mt-1">{children}</div>
        </label>
    );
}

/** Empty panel for a finance list: texts come from states3.empty.<k>.title and .text, optional next action. */
export function FinEmptyState({ k, action }: { k: string; action?: ReactNode }) {
    const t = useT();
    return <EmptyState title={t(`states3.empty.${k}.title`)} description={t(`states3.empty.${k}.text`)} action={action} />;
}

export function Loading({ on }: { on: boolean }) {
    const t = useT();
    return on ? <p className="py-6 text-center text-sm text-slate-500">{t('common.loading')}</p> : null;
}

export const STATUS_BADGE: Record<string, string> = {
    issued: 'bg-blue-50 text-blue-700', partially_paid: 'bg-amber-50 text-amber-700', paid: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-600', open: 'bg-emerald-50 text-emerald-700', closed: 'bg-slate-100 text-slate-600',
};

export function Badge({ s }: { s: string }) {
    const t = useT();
    const key = `fin.status.${s}`;
    const label = t(key);
    return <span className={STATUS_BADGE[s] ?? 'badge-slate'}>{label === key ? s.replace('_', ' ') : label}</span>;
}
