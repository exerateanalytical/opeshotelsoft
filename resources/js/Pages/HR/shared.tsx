import { ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

export interface Paged<T> { data: T[]; total: number; current_page: number; last_page: number }
export interface Department { id: number; code: string; name: string; cost_center: string | null }
export interface Position { id: number; department_id: number; title: string; category: string | null }
export interface Employee {
    id: number; number: string; first_name: string; last_name: string; status: string; photo_url?: string | null;
    gender: string | null; phone: string | null; email: string | null; address: string | null;
    department_id: number | null; position_id: number | null; department?: { id: number; name: string } | null;
    hire_date: string; termination_date: string | null; termination_reason: string | null;
    base_salary: number; payment_method: string; bank_name: string | null; bank_account: string | null; momo_number: string | null;
    cnps_number: string | null; tax_number: string | null; allowances: { code: string; name: string; amount: number }[];
    contracts?: { id: number; kind: string; start_date: string; end_date: string | null; base_salary: number; status: string }[];
    leave_balance?: number;
}

export const errMsg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

export function Banner({ ok, err }: { ok?: string | null; err?: string | null }) {
    return (
        <>
            {err && <Alert variant="danger" className="mb-4">{err}</Alert>}
            {ok && <Alert variant="success" className="mb-4">{ok}</Alert>}
        </>
    );
}

export const STATUS_BADGE: Record<string, string> = {
    active: 'bg-emerald-50 text-emerald-700', on_leave: 'bg-blue-50 text-blue-700', suspended: 'bg-amber-50 text-amber-700',
    terminated: 'bg-red-50 text-red-700', pending: 'bg-amber-50 text-amber-700', approved: 'bg-emerald-50 text-emerald-700',
    rejected: 'bg-red-50 text-red-700', cancelled: 'bg-slate-100 text-slate-600', draft: 'bg-slate-100 text-slate-600', paid: 'bg-blue-50 text-blue-700',
};

export function Badge({ s }: { s: string }) {
    const t = useT();
    const k = `hr.status.${s}`;
    const label = t(k) === k ? s.replace('_', ' ') : t(k);
    return <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[s] ?? 'bg-slate-100 text-slate-600'}`}>{label}</span>;
}

export function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return <label className="block text-sm"><span className="mb-1 block text-slate-500">{label}</span>{children}</label>;
}

export function downloadJson(name: string, data: unknown) {
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
    const a = document.createElement('a');
    a.href = url; a.download = name; a.click();
    URL.revokeObjectURL(url);
}

export function downloadCsv(name: string, rows: Record<string, unknown>[]) {
    if (!rows.length) return;
    const cols = Object.keys(rows[0]);
    const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const csv = [cols.join(','), ...rows.map((r) => cols.map((c) => esc(r[c])).join(','))].join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    const a = document.createElement('a');
    a.href = url; a.download = name; a.click();
    URL.revokeObjectURL(url);
}
