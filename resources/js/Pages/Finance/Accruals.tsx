import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, FinEmptyState, Loading, today } from './shared';
import { CoreNav, M, Table, Td, Th } from './coreShared';

const KINDS = ['prepaid_expense', 'deferred_income', 'accrued_expense', 'accrued_income'] as const;
interface Schedule { id: number; kind: string; description: string; total: number; start_date: string; months: number; status: string; posted: number; pending: number }
interface Item { id: number; entry_type: string; period_date: string; amount: number; journal_entry_id: number | null; error: string | null }
interface Recurring { id: number; name: string; frequency: string; next_date: string; end_date: string | null; active: boolean; lines: { account: string; debit?: number; credit?: number }[] }
interface Lock { id: number; year: number; month: number; action: string; status: string; reason: string | null; requester: string; decider: string | null; created_at: string }
interface Audit { id: number; action: string; subject_type: string; subject_id: string; actor: string | null; created_at: string }

export default function Accruals() {
    const t = useT();
    const schedules = useApi<Schedule[]>('accounting/core/schedules');
    const recurring = useApi<Recurring[]>('accounting/core/recurring');
    const locks = useApi<Lock[]>('accounting/core/period-locks');
    const audit = useApi<Audit[]>('accounting/core/audit-log?limit=30');
    const [open, setOpen] = useState<number | null>(null);
    const items = useApi<Item[]>(open ? `accounting/core/schedules/${open}/items` : null, [open]);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [s, setS] = useState({ kind: 'prepaid_expense', description: '', debit_account: '', credit_account: '', total: '', start_date: today(), months: '12', cost_centre: '', department: '' });
    const [r, setR] = useState({ name: '', journal: 'OD', frequency: 'monthly', next_date: today(), account_d: '', account_c: '', amount: '' });
    const [lk, setLk] = useState({ year: new Date().getFullYear(), month: new Date().getMonth() || 12, action: 'close', reason: '' });

    async function call(path: string, body: object | null, msg: string, reload: (() => void)[]) {
        setError(null); setOk(null);
        try { await api(path, { method: 'POST', ...(body ? { body: JSON.stringify(body) } : {}) }); setOk(msg); reload.forEach((f) => f()); audit.reload(); } catch (e) { setError(errMsg(e)); }
    }

    return (
        <AppLayout title={t('acc2.acc.title')} subtitle={t('acc2.acc.subtitle')}>
            <Head title={t('acc2.acc.title')} />
            <CoreNav active="/finance/accruals" />
            <Banner error={error ?? schedules.error?.message} success={ok} />

            <div className="card mb-4 p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold">{t('acc2.acc.schedules')}</h3>
                    <button className="btn-secondary" onClick={() => call('accounting/core/schedules/run', { as_of: today() }, t('acc2.acc.ran'), [schedules.reload, recurring.reload, items.reload])}>{t('acc2.acc.run_now')}</button>
                </div>
                <form className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:grid-cols-4 [&>*]:min-w-0" onSubmit={(e) => {
                    e.preventDefault();
                    call('accounting/core/schedules', { ...s, total: Number(s.total), months: Number(s.months), debit_account: s.debit_account || null, credit_account: s.credit_account || null, cost_centre: s.cost_centre || null, department: s.department || null }, t('acc2.saved'), [schedules.reload]);
                }}>
                    <Field label={t('acc2.acc.kind')}><select className="input w-full" value={s.kind} onChange={(e) => setS({ ...s, kind: e.target.value })}>{KINDS.map((k) => <option key={k} value={k}>{t(`acc2.acc.kind.${k}`)}</option>)}</select></Field>
                    <Field label={t('acc2.acc.description')}><input className="input w-full" required value={s.description} onChange={(e) => setS({ ...s, description: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.debit')}><input className="input w-full" value={s.debit_account} onChange={(e) => setS({ ...s, debit_account: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.credit')}><input className="input w-full" value={s.credit_account} onChange={(e) => setS({ ...s, credit_account: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.total')}><input type="number" min={1} required className="input w-full" value={s.total} onChange={(e) => setS({ ...s, total: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.start')}><input type="date" className="input w-full" value={s.start_date} onChange={(e) => setS({ ...s, start_date: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.months')}><input type="number" min={1} className="input w-full" value={s.months} onChange={(e) => setS({ ...s, months: e.target.value })} /></Field>
                    <Field label={t('acc2.dim.kind.cost_centre')}><input className="input w-full" value={s.cost_centre} onChange={(e) => setS({ ...s, cost_centre: e.target.value })} /></Field>
                    <button className="btn-primary sm:col-span-2 sm:col-span-4">{t('acc2.acc.create')}</button>
                    <p className="sm:col-span-2 text-xs text-slate-500 sm:col-span-4">{t('acc2.acc.hint')}</p>
                </form>
                <Loading on={schedules.loading} />
                <Table head={<><Th>{t('acc2.acc.kind')}</Th><Th>{t('acc2.acc.description')}</Th><Th r>{t('acc2.acc.total')}</Th><Th>{t('acc2.acc.start')}</Th><Th r>{t('acc2.acc.posted')}</Th><Th /></>}>
                    {schedules.data?.map((x) => (
                        <tr key={x.id}>
                            <Td>{t(`acc2.acc.kind.${x.kind}`)}</Td><Td>{x.description}{x.status === 'cancelled' && <span className="ml-2 text-xs text-slate-500">{t('acc2.status.cancelled')}</span>}</Td><Td r>{M(x.total)}</Td><Td>{formatDate(x.start_date)}</Td>
                            <Td r>{x.posted}/{x.posted + x.pending}</Td>
                            <Td r>
                                <button className="btn-secondary mr-2" onClick={() => setOpen(open === x.id ? null : x.id)}>{t('acc2.acc.items')}</button>
                                {x.status === 'active' && x.pending > 0 && <button className="btn-secondary" onClick={() => call(`accounting/core/schedules/${x.id}/cancel`, null, t('acc2.saved'), [schedules.reload])}>{t('common.cancel')}</button>}
                            </Td>
                        </tr>
                    ))}
                </Table>
                {schedules.data?.length === 0 && <FinEmptyState k="schedules" />}
                {open && (
                    <div className="mt-3 border-t border-slate-100 pt-3">
                        <Loading on={items.loading} />
                        <Table head={<><Th>{t('fin.col.date')}</Th><Th>{t('acc2.acc.entry_type')}</Th><Th r>{t('fin.col.amount')}</Th><Th>{t('fin.col.status')}</Th></>}>
                            {items.data?.map((i) => (
                                <tr key={i.id}><Td>{formatDate(i.period_date)}</Td><Td>{t(`acc2.acc.type.${i.entry_type}`)}</Td><Td r>{M(i.amount)}</Td>
                                    <Td>{i.journal_entry_id ? t('acc2.acc.done') : i.error ? <span className="text-red-700">{i.error}</span> : t('acc2.acc.pending')}</Td></tr>
                            ))}
                        </Table>
                    </div>
                )}
            </div>

            <div className="card mb-4 p-4">
                <h3 className="mb-3 font-semibold">{t('acc2.rec.title')}</h3>
                <form className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:grid-cols-4 [&>*]:min-w-0" onSubmit={(e) => {
                    e.preventDefault();
                    const amt = Number(r.amount);
                    call('accounting/core/recurring', { name: r.name, journal: r.journal, frequency: r.frequency, next_date: r.next_date, lines: [{ account: r.account_d, debit: amt }, { account: r.account_c, credit: amt }] }, t('acc2.saved'), [recurring.reload]);
                }}>
                    <Field label={t('acc2.acc.description')}><input className="input w-full" required value={r.name} onChange={(e) => setR({ ...r, name: e.target.value })} /></Field>
                    <Field label={t('acc2.rec.frequency')}><select className="input w-full" value={r.frequency} onChange={(e) => setR({ ...r, frequency: e.target.value })}>{['monthly', 'quarterly', 'yearly'].map((f) => <option key={f} value={f}>{t(`acc2.rec.freq.${f}`)}</option>)}</select></Field>
                    <Field label={t('acc2.rec.next')}><input type="date" className="input w-full" value={r.next_date} onChange={(e) => setR({ ...r, next_date: e.target.value })} /></Field>
                    <Field label={t('fin.col.amount')}><input type="number" min={1} required className="input w-full" value={r.amount} onChange={(e) => setR({ ...r, amount: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.debit')}><input className="input w-full" required value={r.account_d} onChange={(e) => setR({ ...r, account_d: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.credit')}><input className="input w-full" required value={r.account_c} onChange={(e) => setR({ ...r, account_c: e.target.value })} /></Field>
                    <button className="btn-primary sm:col-span-2">{t('acc2.rec.create')}</button>
                </form>
                <Table head={<><Th>{t('acc2.acc.description')}</Th><Th>{t('acc2.rec.frequency')}</Th><Th>{t('acc2.rec.next')}</Th><Th r>{t('fin.col.amount')}</Th></>}>
                    {recurring.data?.map((x) => (
                        <tr key={x.id}><Td b>{x.name}</Td><Td>{t(`acc2.rec.freq.${x.frequency}`)}</Td><Td>{formatDate(x.next_date)}</Td><Td r>{M(x.lines.reduce((a, l) => a + (l.debit ?? 0), 0))}</Td></tr>
                    ))}
                </Table>
            </div>

            <div className="card mb-4 p-4">
                <h3 className="mb-1 font-semibold">{t('acc2.lock.title')}</h3>
                <p className="mb-3 text-xs text-slate-500">{t('acc2.lock.hint')}</p>
                <form className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:flex sm:items-end [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); call('accounting/core/period-locks', lk, t('acc2.lock.requested'), [locks.reload]); }}>
                    <Field label={t('fin.periods.year')}><input type="number" className="input w-full sm:w-24" value={lk.year} onChange={(e) => setLk({ ...lk, year: Number(e.target.value) })} /></Field>
                    <Field label={t('fin.periods.month')}><input type="number" min={1} max={12} className="input w-full sm:w-20" value={lk.month} onChange={(e) => setLk({ ...lk, month: Number(e.target.value) })} /></Field>
                    <Field label={t('acc2.lock.action')}><select className="input w-full" value={lk.action} onChange={(e) => setLk({ ...lk, action: e.target.value })}><option value="close">{t('acc2.lock.close')}</option><option value="reopen">{t('acc2.lock.reopen')}</option></select></Field>
                    <Field label={t('acc2.lock.reason')}><input className="input w-full" value={lk.reason} onChange={(e) => setLk({ ...lk, reason: e.target.value })} /></Field>
                    <button className="btn-primary sm:col-span-2">{t('acc2.lock.request')}</button>
                </form>
                <Table head={<><Th>{t('fin.col.period')}</Th><Th>{t('acc2.lock.action')}</Th><Th>{t('fin.col.status')}</Th><Th>{t('acc2.lock.requester')}</Th><Th>{t('acc2.lock.reason')}</Th><Th /></>}>
                    {locks.data?.map((x) => (
                        <tr key={x.id}>
                            <Td>{x.month}/{x.year}</Td><Td>{t(`acc2.lock.${x.action}`)}</Td><Td>{t(`acc2.status.${x.status}`)}</Td><Td>{x.requester}{x.decider ? ` / ${x.decider}` : ''}</Td><Td>{x.reason}</Td>
                            <Td r>{x.status === 'pending' && (
                                <>
                                    <button className="btn-primary mr-2" onClick={() => call(`accounting/core/period-locks/${x.id}/decide`, { approve: true }, t('acc2.lock.approved'), [locks.reload])}>{t('acc2.lock.approve')}</button>
                                    <button className="btn-secondary" onClick={() => call(`accounting/core/period-locks/${x.id}/decide`, { approve: false }, t('acc2.lock.rejected'), [locks.reload])}>{t('acc2.lock.reject')}</button>
                                </>
                            )}</Td>
                        </tr>
                    ))}
                </Table>
            </div>

            <div className="card p-4">
                <h3 className="mb-3 font-semibold">{t('acc2.audit.title')}</h3>
                <Table head={<><Th>{t('fin.col.date')}</Th><Th>{t('acc2.audit.action')}</Th><Th>{t('acc2.audit.subject')}</Th><Th>{t('acc2.audit.actor')}</Th></>}>
                    {audit.data?.map((a) => <tr key={a.id}><Td>{formatDate(a.created_at)}</Td><Td mono>{a.action}</Td><Td>{a.subject_type} {a.subject_id}</Td><Td>{a.actor ?? t('acc2.audit.system')}</Td></tr>)}
                </Table>
            </div>
        </AppLayout>
    );
}
