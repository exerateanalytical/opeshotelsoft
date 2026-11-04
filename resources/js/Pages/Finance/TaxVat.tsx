import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, FinEmptyState, today } from './shared';
import { M, Table, Td, Th } from './coreShared';
import { AbNav, Gate, downloadFile } from './ab3Shared';

interface Profile { profile: { country: string; name: string; vat_rate: number; vat_form: string; filing_day: number; gate: string }; countries: { code: string; name: string; vat_rate: number }[] }
interface VatReturn { id: number; period_from: string; period_to: string; version: number; status: string; filing_reference: string | null; figures: Record<string, number>; locked: boolean }
interface Cert { id: number; number: string; supplier_name: string; date: string; rate: number; base: number; amount: number; status: string }

const monthRange = () => {
    const d = new Date(); d.setDate(0);
    return { from: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`, to: d.toISOString().slice(0, 10) };
};

export default function TaxVat() {
    const t = useT();
    const prof = useApi<Profile>('accounting3/tax/profile');
    const returns = useApi<VatReturn[]>('accounting3/tax/vat-returns');
    const certs = useApi<Cert[]>('accounting3/tax/withholding-certificates');
    const stamp = useApi<{ rules: { code: string; name: string; kind: string; rate: number; fixed_amount: number }[]; register: { id: number; date: string; rule_code: string; base: number; amount: number }[] }>('accounting3/tax/stamp-duty');
    const [range, setRange] = useState(monthRange());
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [ref, setRef] = useState<Record<number, string>>({});
    const [remit, setRemit] = useState({ account: '521100', reference: '' });

    const run = async (fn: () => Promise<unknown>, msg = t('acc3.saved')) => {
        setError(null); setOk(null);
        try { await fn(); setOk(msg); prof.reload(); returns.reload(); certs.reload(); stamp.reload(); } catch (e) { setError(errMsg(e)); }
    };
    const issued = certs.data?.filter((c) => c.status === 'issued') ?? [];

    return (
        <AppLayout title={t('acc3.tax.title')} subtitle={t('acc3.tax.subtitle')}>
            <Head title={t('acc3.tax.title')} />
            <QueryStatus query={prof} variant="form" />
            <AbNav active="/finance/tax" />
            <Gate>{t('acc3.tax.gate')}</Gate>
            <Banner error={error} success={ok} />

            <div className="card mb-4 p-4">
                <div className="flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.tax.country')}>
                        <select className="input" value={prof.data?.profile.country ?? ''} onChange={(e) => run(() => api('accounting3/tax/profile', { method: 'PUT', body: JSON.stringify({ country: e.target.value }) }))}>
                            {prof.data?.countries.map((c) => <option key={c.code} value={c.code}>{c.name} ({c.vat_rate}%)</option>)}
                        </select>
                    </Field>
                    {prof.data && <p className="pb-2 text-sm text-slate-600">{prof.data.profile.vat_form} - {t('acc3.tax.deadline_day', { day: prof.data.profile.filing_day })}</p>}
                </div>
            </div>

            <div className="card mb-4 p-4">
                <h3 className="mb-3 font-semibold">{t('acc3.tax.vat')}</h3>
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.from')}><input type="date" className="input" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
                    <Field label={t('acc3.to')}><input type="date" className="input" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
                    <button className="btn-primary" onClick={() => run(() => api('accounting3/tax/vat-returns', { method: 'POST', body: JSON.stringify(range) }), t('acc3.tax.prepared'))}>{t('acc3.tax.prepare')}</button>
                </div>
                <QueryStatus query={returns} variant="table" />
                <Table head={<><Th>{t('acc3.pay.period')}</Th><Th>v</Th><Th>{t('acc3.status')}</Th><Th r>{t('acc3.tax.collected')}</Th><Th r>{t('acc3.tax.deductible')}</Th><Th r>{t('acc3.tax.due')}</Th><Th r>{t('acc3.tax.credit')}</Th><Th /></>}>
                    {returns.data?.map((r) => (
                        <tr key={r.id}>
                            <Td>{r.period_from} / {r.period_to}</Td><Td>{r.version}</Td><Td>{t(`acc3.tax.st.${r.status}`)}{r.filing_reference ? ` (${r.filing_reference})` : ''}</Td>
                            <Td r>{M(r.figures.vat_collected)}</Td><Td r>{M(r.figures.vat_deductible)}</Td><Td r b>{M(r.figures.vat_due)}</Td><Td r>{M(r.figures.credit_to_carry)}</Td>
                            <Td r>
                                {r.status === 'draft' && (
                                    <span className="flex justify-end gap-1">
                                        <input className="input w-36" placeholder={t('acc3.tax.reference')} aria-label={t('acc3.tax.reference')} value={ref[r.id] ?? ''} onChange={(e) => setRef({ ...ref, [r.id]: e.target.value })} />
                                        <button className="btn-primary" disabled={!ref[r.id]} onClick={() => run(() => api(`accounting3/tax/vat-returns/${r.id}/file`, { method: 'POST', body: JSON.stringify({ reference: ref[r.id] }) }), t('acc3.tax.filed'))}>{t('acc3.tax.file')}</button>
                                    </span>
                                )}
                                {r.status === 'filed' && <button className="btn-secondary mr-1" onClick={() => run(() => api(`accounting3/tax/vat-returns/${r.id}/amend`, { method: 'POST' }))}>{t('acc3.tax.amend')}</button>}
                                <button className="btn-secondary" onClick={() => downloadFile(`accounting3/tax/vat-returns/${r.id}/export?format=xlsx`, `vat-${r.period_to}.xlsx`).catch((e) => setError(errMsg(e)))}>Excel</button>
                            </Td>
                        </tr>
                    ))}
                </Table>
                {returns.data?.length === 0 && <FinEmptyState k="vat_returns" />}
            </div>

            <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                <div className="card p-4">
                    <h3 className="mb-3 font-semibold">{t('acc3.tax.wht')}</h3>
                    <Table head={<><Th>{t('acc3.tax.cert')}</Th><Th>{t('acc3.pay.supplier')}</Th><Th r>{t('acc3.tax.base')}</Th><Th r>{t('acc3.amount')}</Th><Th>{t('acc3.status')}</Th></>}>
                        {certs.data?.map((c) => <tr key={c.id}><Td mono>{c.number}</Td><Td>{c.supplier_name}</Td><Td r>{M(c.base)}</Td><Td r b>{M(c.amount)}</Td><Td>{t(`acc3.tax.cert.${c.status}`)}</Td></tr>)}
                    </Table>
                    {certs.data?.length === 0 && <FinEmptyState k="wht" />}
                    {issued.length > 0 && (
                        <div className="mt-3 flex flex-wrap items-end gap-2">
                            <Field label={t('acc3.tax.pay_from')}><input className="input w-28" value={remit.account} onChange={(e) => setRemit({ ...remit, account: e.target.value })} /></Field>
                            <Field label={t('acc3.tax.reference')}><input className="input w-36" value={remit.reference} onChange={(e) => setRemit({ ...remit, reference: e.target.value })} /></Field>
                            <button className="btn-primary" onClick={() => run(() => api('accounting3/tax/withholding-certificates/remit', { method: 'POST', body: JSON.stringify({ ids: issued.map((c) => c.id), date: today(), account: remit.account, reference: remit.reference || null }) }), t('acc3.tax.remitted'))}>{t('acc3.tax.remit', { total: M(issued.reduce((a, c) => a + c.amount, 0)) })}</button>
                        </div>
                    )}
                </div>
                <div className="card p-4">
                    <h3 className="mb-3 font-semibold">{t('acc3.tax.stamp')}</h3>
                    <Table head={<><Th>{t('acc3.date')}</Th><Th>{t('acc3.tax.rule')}</Th><Th r>{t('acc3.tax.base')}</Th><Th r>{t('acc3.amount')}</Th></>}>
                        {stamp.data?.register.map((s) => <tr key={s.id}><Td>{s.date}</Td><Td mono>{s.rule_code}</Td><Td r>{M(s.base)}</Td><Td r b>{M(s.amount)}</Td></tr>)}
                    </Table>
                    <p className="mt-2 text-xs text-slate-500">{t('acc3.tax.stamp_note', { rules: stamp.data?.rules.length ?? 0 })}</p>
                </div>
            </div>
        </AppLayout>
    );
}
