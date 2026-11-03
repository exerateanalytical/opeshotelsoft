import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, num, usePerms } from './ControlShared';

interface Rec { id: number; employee_name: string; issuable_name: string; kind: string; quantity: number; returned_quantity: number; issued_on: string; due_back_on: string | null; status: string; overdue: boolean }

export default function Issues() {
    const t = useT();
    const canEdit = usePerms().includes('stockops.issues');
    const [overdue, setOverdue] = useState(false);
    const rows = useApi<Rec[]>(`stock-ops/issues${overdue ? '?overdue=1' : ''}`);
    const [msg, setMsg] = useState<string | null>(null);

    async function giveBack(id: number, condition: string) {
        setMsg(null);
        try { await api(`stock-ops/issues/${id}/return`, { method: 'POST', body: JSON.stringify({ condition }) }); rows.reload(); } catch (e) { setMsg(errMsg(e)); }
    }

    return (
        <AppLayout title={t('stock3.issue.title')} subtitle={t('stock3.issue.subtitle')}>
            <Head title={t('stock3.issue.title')} />
            {msg && <p className="mb-3 text-sm text-red-700">{msg}</p>}
            <label className="mb-3 flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5 shrink-0" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} />{t('stock3.issue.overdue_only')}</label>
            <div className="card overflow-x-auto p-3 text-sm">
                <table className="w-full table-auto">
                    <thead><tr className="text-left text-xs uppercase text-slate-500">{['employee', 'item', 'qty', 'issued', 'due', 'status', ''].map((h) => <th key={h} className="px-2 py-1">{h && t(`stock3.issue.col.${h}`)}</th>)}</tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {(rows.data ?? []).map((r) => (
                            <tr key={r.id}>
                                <td className="px-2 py-1">{r.employee_name}</td><td className="px-2 py-1">{r.issuable_name}</td><td className="px-2 py-1">{num(r.returned_quantity)} / {num(r.quantity)}</td>
                                <td className="px-2 py-1">{r.issued_on}</td><td className={`px-2 py-1 ${r.overdue ? 'text-red-700' : ''}`}>{r.due_back_on ?? '-'}</td>
                                <td className="px-2 py-1">{t(`stock3.issue.status.${r.status}`)}</td>
                                <td className="px-2 py-1">{canEdit && r.status === 'issued' && (<>
                                    <button type="button" className="underline" onClick={() => giveBack(r.id, 'good')}>{t('stock3.issue.return')}</button>
                                    <button type="button" className="ml-2 underline" onClick={() => giveBack(r.id, 'lost')}>{t('stock3.issue.lost')}</button></>)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {rows.data && rows.data.length === 0 && <p className="mt-2 text-slate-500">{t('stock3.issue.empty')}</p>}
            </div>
        </AppLayout>
    );
}
