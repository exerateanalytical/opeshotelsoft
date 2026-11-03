import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, Loading, today } from './shared';
import { M, Table, Td, Th } from './coreShared';
import { AbNav, uploadFile } from './ab3Shared';

interface Chain { id: number; name: string; min_amount: number; max_amount: number | null; steps_list: { role: string; label: string | null }[]; active: boolean }
interface Inbox { id: number; supplier_invoice_id: number; reference: string; supplier: string; total: number; role: string; label: string | null; step_no: number }
interface Delegation { id: number; from_name: string; to_name: string; starts_on: string; ends_on: string; active: boolean }
interface Capture { id: number; filename: string; status: string; engine: string | null; extracted: Record<string, unknown>; supplier_invoice_id: number | null }
interface Supplier { id: number; name: string }

export default function PayablesWorkflow() {
    const t = useT();
    const chains = useApi<Chain[]>('accounting3/payables/approval-chains');
    const inbox = useApi<Inbox[]>('accounting3/payables/approvals/inbox');
    const deleg = useApi<Delegation[]>('accounting3/payables/delegations');
    const caps = useApi<{ captures: Capture[]; schema: { engine: string } }>('accounting3/payables/captures');
    const suppliers = useApi<{ data: Supplier[] }>('suppliers?per_page=200');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [chain, setChain] = useState({ name: '', min_amount: 0, roles: 'accountant,finance-director' });
    const [dg, setDg] = useState({ to_user_id: '', starts_on: today(), ends_on: today(), reason: '' });
    const [conv, setConv] = useState<{ id: number; supplier_id: string; reference: string; date: string; net_total: string; tax_total: string; account: string } | null>(null);
    const [note, setNote] = useState('');

    const run = async (fn: () => Promise<unknown>, after?: () => void) => {
        setError(null); setOk(null);
        try { await fn(); setOk(t('acc3.saved')); after?.(); chains.reload(); inbox.reload(); deleg.reload(); caps.reload(); } catch (e) { setError(errMsg(e)); }
    };

    return (
        <AppLayout title={t('acc3.pay.title')} subtitle={t('acc3.pay.subtitle')}>
            <Head title={t('acc3.pay.title')} />
            <AbNav active="/finance/payables-workflow" />
            <Banner error={error} success={ok} />

            <div className="card mb-4 p-4">
                <h3 className="mb-3 font-semibold">{t('acc3.pay.inbox')}</h3>
                <Loading on={inbox.loading} />
                <input className="input mb-2 w-full sm:w-96" placeholder={t('acc3.pay.note')} aria-label={t('acc3.pay.note')} value={note} onChange={(e) => setNote(e.target.value)} />
                <Table head={<><Th>{t('acc3.pay.supplier')}</Th><Th>{t('acc3.pay.reference')}</Th><Th r>{t('acc3.amount')}</Th><Th>{t('acc3.pay.step')}</Th><Th /></>}>
                    {inbox.data?.map((a) => (
                        <tr key={a.id}>
                            <Td>{a.supplier}</Td><Td mono>{a.reference}</Td><Td r>{M(a.total)}</Td><Td>{a.step_no}. {a.label ?? a.role}</Td>
                            <Td r>
                                <button className="btn-primary mr-2" onClick={() => run(() => api(`accounting3/payables/approvals/${a.id}/decide`, { method: 'POST', body: JSON.stringify({ approve: true, note }) }))}>{t('acc3.approve')}</button>
                                <button className="btn-secondary" onClick={() => run(() => api(`accounting3/payables/approvals/${a.id}/decide`, { method: 'POST', body: JSON.stringify({ approve: false, note }) }))}>{t('acc3.reject')}</button>
                            </Td>
                        </tr>
                    ))}
                </Table>
                {inbox.data?.length === 0 && <p className="py-3 text-sm text-slate-500">{t('acc3.pay.inbox_empty')}</p>}
            </div>

            <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                <div className="card p-4">
                    <h3 className="mb-1 font-semibold">{t('acc3.pay.chains')}</h3>
                    <p className="mb-3 text-xs text-slate-500">{t('acc3.pay.chains_hint')}</p>
                    <form className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); run(() => api('accounting3/payables/approval-chains', { method: 'POST', body: JSON.stringify({ name: chain.name, min_amount: Number(chain.min_amount), steps: chain.roles.split(',').map((r) => ({ role: r.trim() })).filter((s) => s.role) }) })); }}>
                        <Field label={t('acc3.name')}><input className="input w-full" required value={chain.name} onChange={(e) => setChain({ ...chain, name: e.target.value })} /></Field>
                        <Field label={t('acc3.pay.min_amount')}><input type="number" min={0} className="input w-full" value={chain.min_amount} onChange={(e) => setChain({ ...chain, min_amount: Number(e.target.value) })} /></Field>
                        <div className="sm:col-span-2"><Field label={t('acc3.pay.roles')}><input className="input w-full" value={chain.roles} onChange={(e) => setChain({ ...chain, roles: e.target.value })} /></Field></div>
                        <button className="btn-primary sm:col-span-2">{t('common.save')}</button>
                    </form>
                    <Table head={<><Th>{t('acc3.name')}</Th><Th r>{t('acc3.pay.min_amount')}</Th><Th>{t('acc3.pay.steps')}</Th></>}>
                        {chains.data?.map((c) => <tr key={c.id}><Td b>{c.name}</Td><Td r>{M(c.min_amount)}</Td><Td>{c.steps_list.map((s) => s.role).join(' > ')}</Td></tr>)}
                    </Table>
                </div>
                <div className="card p-4">
                    <h3 className="mb-3 font-semibold">{t('acc3.pay.delegations')}</h3>
                    <form className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); run(() => api('accounting3/payables/delegations', { method: 'POST', body: JSON.stringify({ ...dg, to_user_id: Number(dg.to_user_id) }) })); }}>
                        <Field label={t('acc3.pay.delegate_to')}><input type="number" required className="input w-full" placeholder="user id" value={dg.to_user_id} onChange={(e) => setDg({ ...dg, to_user_id: e.target.value })} /></Field>
                        <Field label={t('acc3.pay.reason')}><input className="input w-full" value={dg.reason} onChange={(e) => setDg({ ...dg, reason: e.target.value })} /></Field>
                        <Field label={t('acc3.from')}><input type="date" className="input w-full" value={dg.starts_on} onChange={(e) => setDg({ ...dg, starts_on: e.target.value })} /></Field>
                        <Field label={t('acc3.to')}><input type="date" className="input w-full" value={dg.ends_on} onChange={(e) => setDg({ ...dg, ends_on: e.target.value })} /></Field>
                        <button className="btn-primary sm:col-span-2">{t('common.save')}</button>
                    </form>
                    <Table head={<><Th>{t('acc3.pay.from_user')}</Th><Th>{t('acc3.pay.delegate_to')}</Th><Th>{t('acc3.pay.period')}</Th><Th /></>}>
                        {deleg.data?.map((d) => (
                            <tr key={d.id}><Td>{d.from_name}</Td><Td>{d.to_name}</Td><Td>{d.starts_on} / {d.ends_on}</Td>
                                <Td r>{d.active ? <button className="btn-secondary" onClick={() => run(() => api(`accounting3/payables/delegations/${d.id}/revoke`, { method: 'POST' }))}>{t('acc3.pay.revoke')}</button> : t('acc3.pay.revoked')}</Td></tr>
                        ))}
                    </Table>
                </div>
            </div>

            <div className="card mt-4 p-4">
                <h3 className="mb-1 font-semibold">{t('acc3.pay.capture')}</h3>
                <p className="mb-3 text-xs text-slate-500">{t('acc3.pay.capture_hint', { engine: caps.data?.schema.engine ?? '' })}</p>
                <input type="file" accept=".pdf,.png,.jpg,.jpeg" className="input mb-3 w-full sm:w-auto" aria-label={t('acc3.pay.capture')} onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (!f) return;
                    const fd = new FormData(); fd.append('file', f);
                    run(() => uploadFile('accounting3/payables/captures', fd));
                }} />
                <Table head={<><Th>{t('acc3.pay.file')}</Th><Th>{t('acc3.status')}</Th><Th /></>}>
                    {caps.data?.captures.map((c) => (
                        <tr key={c.id}><Td>{c.filename}</Td><Td>{t(`acc3.pay.cap.${c.status}`)}</Td>
                            <Td r>{c.status !== 'converted' && <button className="btn-secondary" onClick={() => setConv({ id: c.id, supplier_id: String(c.extracted.supplier_id ?? ''), reference: String(c.extracted.reference ?? ''), date: String(c.extracted.date ?? today()), net_total: String(c.extracted.net_total ?? ''), tax_total: String(c.extracted.tax_total ?? '0'), account: '632000' })}>{t('acc3.pay.confirm')}</button>}</Td></tr>
                    ))}
                </Table>
                {conv && (
                    <form className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:grid-cols-4 [&>*]:min-w-0" onSubmit={(e) => {
                        e.preventDefault();
                        run(() => api(`accounting3/payables/captures/${conv.id}/convert`, { method: 'POST', body: JSON.stringify({ supplier_id: Number(conv.supplier_id), reference: conv.reference, date: conv.date, net_total: Number(conv.net_total), tax_total: Number(conv.tax_total), expense_lines: [{ account: conv.account, amount: Number(conv.net_total) }] }) }), () => setConv(null));
                    }}>
                        <Field label={t('acc3.pay.supplier')}><select required className="input w-full" value={conv.supplier_id} onChange={(e) => setConv({ ...conv, supplier_id: e.target.value })}><option value="" />{suppliers.data?.data.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                        <Field label={t('acc3.pay.reference')}><input required className="input w-full" value={conv.reference} onChange={(e) => setConv({ ...conv, reference: e.target.value })} /></Field>
                        <Field label={t('acc3.date')}><input type="date" required className="input w-full" value={conv.date} onChange={(e) => setConv({ ...conv, date: e.target.value })} /></Field>
                        <Field label={t('acc3.pay.expense_account')}><input required className="input w-full" value={conv.account} onChange={(e) => setConv({ ...conv, account: e.target.value })} /></Field>
                        <Field label={t('acc3.pay.net')}><input type="number" required className="input w-full" value={conv.net_total} onChange={(e) => setConv({ ...conv, net_total: e.target.value })} /></Field>
                        <Field label={t('acc3.pay.tax')}><input type="number" className="input w-full" value={conv.tax_total} onChange={(e) => setConv({ ...conv, tax_total: e.target.value })} /></Field>
                        <button className="btn-primary sm:col-span-2 self-end">{t('acc3.pay.record_invoice')}</button>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
