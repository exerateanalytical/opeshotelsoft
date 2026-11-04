import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { formatNumber } from '@/lib/utils';
import Alert from '@/Components/ui/Alert';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { useSplitView } from '@/Components/ui/MasterDetail';

interface Employee { id: number; number: string; first_name: string; last_name: string }
interface Balance { leave_type_id: number; code: string | null; name: string | null; balance: number }

/** MS1: per-employee leave balances (GET workforce/leave/balances) and manual adjustment (POST workforce/leave/adjust, workforce.manage, reason required). */
export default function LeaveBalancesPanel() {
    const t = useT();
    const can = useCan();
    const wide = useSplitView();
    const [q, setQ] = useState('');
    const [emp, setEmp] = useState<Employee | null>(null);
    const [nonce, setNonce] = useState(0);
    const [adj, setAdj] = useState<{ leave_type_id: string; days: string; note: string } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const people = useApi<{ data: Employee[] }>(q.trim().length >= 2 ? `hr/employees?q=${encodeURIComponent(q.trim())}` : null, [q]);
    const bal = useApi<{ employee_id: number; balances: Balance[] }>(emp ? `workforce/leave/balances?employee_id=${emp.id}` : null, [emp?.id, nonce]);

    return (
        <section className="card mb-4 space-y-3 p-4 text-sm">
            <h2 className="font-semibold">{t('ms1.leave.title')}</h2>
            {ok && <Alert variant="success" onDismiss={() => setOk(null)}>{ok}</Alert>}
            <Field label={t('ms1.leave.find')}>
                <input type="search" className="input sm:w-80" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('ms1.leave.find_ph')} />
            </Field>
            {people.error && <Alert variant="danger">{people.error.message}</Alert>}
            {people.data && (
                <div className="flex flex-wrap gap-2">
                    {people.data.data.slice(0, 12).map((p) => (
                        <button key={p.id} type="button" aria-pressed={emp?.id === p.id} className={emp?.id === p.id ? 'btn-primary btn-sm' : 'btn-secondary btn-sm'} onClick={() => setEmp(p)}>{p.first_name} {p.last_name} · {p.number}</button>
                    ))}
                    {!people.data.data.length && <p className="text-slate-600">{t('ms1.leave.none')}</p>}
                </div>
            )}
            {emp && (
                <>
                    {bal.error && <Alert variant="danger">{bal.error.message}</Alert>}
                    <DataTable
                        caption={t('ms1.leave.caption', { name: `${emp.first_name} ${emp.last_name}` })}
                        tableKey="leave-balances"
                        loading={bal.loading && !bal.data}
                        rows={bal.data?.balances ?? []}
                        rowKey={(b) => b.leave_type_id}
                        empty={<EmptyState title={t('ms1.leave.no_policy')} />}
                        columns={[
                            { key: 'type', header: t('workforce.leave.type'), sortValue: (b) => b.name ?? '', cell: (b) => b.name ?? b.code },
                            { key: 'balance', header: t('ms1.leave.balance'), align: 'right', sortValue: (b) => b.balance, cell: (b) => formatNumber(b.balance) },
                        ]}
                    />
                    {can('workforce.manage') && (
                        <button type="button" className="btn-secondary" onClick={() => { setErr(null); setAdj({ leave_type_id: String(bal.data?.balances[0]?.leave_type_id ?? ''), days: '', note: '' }); }}>{t('ms1.leave.adjust')}</button>
                    )}
                </>
            )}
            {adj && emp && (
                <Modal open variant={wide ? 'dialog' : 'sheet'} title={t('ms1.leave.adjust_title', { name: `${emp.first_name} ${emp.last_name}` })} onClose={() => setAdj(null)} formId="leave-adjust"
                    footer={<><button type="button" className="btn-secondary" onClick={() => setAdj(null)}>{t('common.cancel')}</button><button type="submit" form="leave-adjust" className="btn-primary">{t('ms1.leave.save')}</button></>}>
                    <form id="leave-adjust" className="space-y-3" onSubmit={async (e) => {
                        e.preventDefault();
                        setErr(null);
                        try {
                            await api('workforce/leave/adjust', { method: 'POST', body: JSON.stringify({ employee_id: emp.id, leave_type_id: Number(adj.leave_type_id), days: Number(adj.days), note: adj.note }) });
                            setAdj(null); setOk(t('ms1.leave.adjusted')); setNonce((n) => n + 1);
                        } catch (x) { setErr(x instanceof Error ? x.message : String(x)); }
                    }}>
                        {err && <Alert variant="danger">{err}</Alert>}
                        <Field label={t('workforce.leave.type')}>
                            <select className="select" required value={adj.leave_type_id} onChange={(e) => setAdj({ ...adj, leave_type_id: e.target.value })}>
                                {(bal.data?.balances ?? []).map((b) => <option key={b.leave_type_id} value={b.leave_type_id}>{b.name ?? b.code}</option>)}
                            </select>
                        </Field>
                        <Field label={t('ms1.leave.days')} hint={t('ms1.leave.days_hint')}>
                            <input className="input" type="number" step="0.5" min={-60} max={60} required value={adj.days} onChange={(e) => setAdj({ ...adj, days: e.target.value })} />
                        </Field>
                        <Field label={t('ms1.leave.reason')}>
                            <textarea className="input" required maxLength={200} rows={3} value={adj.note} onChange={(e) => setAdj({ ...adj, note: e.target.value })} />
                        </Field>
                    </form>
                </Modal>
            )}
        </section>
    );
}
