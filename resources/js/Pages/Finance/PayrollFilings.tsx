import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, Loading } from './shared';
import { M, Table, Td, Th } from './coreShared';
import { AbNav, Gate, downloadFile } from './ab3Shared';

const KINDS = ['cnps', 'irpp', 'cfc_fne_cac'] as const;
interface Build { form: string; header: string[]; rows: Record<string, string | number | null>[]; totals: Record<string, number> }
interface Filing { id: number; kind: string; period: string; employees: number; status: string; filing_reference: string | null; created_at: string }

export default function PayrollFilings() {
    const t = useT();
    const [kind, setKind] = useState<(typeof KINDS)[number]>('cnps');
    const [period, setPeriod] = useState(new Date(Date.now() - 20 * 86400000).toISOString().slice(0, 7));
    const build = useApi<Build>(`accounting3/tax/payroll-filings/${kind}?period=${period}`, [kind, period]);
    const hist = useApi<Filing[]>('accounting3/tax/payroll-filings');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [ref, setRef] = useState<Record<number, string>>({});

    const dl = (format: 'xlsx' | 'csv') => downloadFile(`accounting3/tax/payroll-filings/${kind}/export?period=${period}&format=${format}`, `payroll-${kind}-${period}.${format}`)
        .then(() => { setOk(t('acc3.pf.exported')); hist.reload(); }).catch((e) => setError(errMsg(e)));

    return (
        <AppLayout title={t('acc3.pf.title')} subtitle={t('acc3.pf.subtitle')}>
            <Head title={t('acc3.pf.title')} />
            <AbNav active="/finance/payroll-filings" />
            <Gate>{t('acc3.pf.gate')}</Gate>
            <Banner error={error ?? build.error?.message} success={ok} />

            <div className="card mb-4 p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.pf.kind')}><select className="input" value={kind} onChange={(e) => setKind(e.target.value as typeof kind)}>{KINDS.map((k) => <option key={k} value={k}>{t(`acc3.pf.kind.${k}`)}</option>)}</select></Field>
                    <Field label={t('acc3.pf.period')}><input type="month" className="input" value={period} onChange={(e) => setPeriod(e.target.value)} /></Field>
                    <button className="btn-primary" disabled={!build.data} onClick={() => dl('xlsx')}>Excel</button>
                    <button className="btn-secondary" disabled={!build.data} onClick={() => dl('csv')}>CSV</button>
                </div>
                <Loading on={build.loading} />
                {build.data && (
                    <>
                        <p className="mb-2 text-sm text-slate-600">{build.data.form}</p>
                        <Table head={<>{build.data.header.map((h) => <Th key={h} r={typeof build.data!.rows[0]?.[h] === 'number'}>{t(`acc3.pf.col.${h}`)}</Th>)}</>}>
                            {build.data.rows.map((r, i) => <tr key={i}>{build.data!.header.map((h) => <Td key={h} r={typeof r[h] === 'number'}>{typeof r[h] === 'number' && h !== 'number' ? M(r[h] as number) : String(r[h] ?? '')}</Td>)}</tr>)}
                            <tr>{build.data.header.map((h, i) => <Td key={h} b r={h in build.data!.totals}>{i === 0 ? t('acc3.total') : h in build.data!.totals ? M(build.data!.totals[h]) : ''}</Td>)}</tr>
                        </Table>
                    </>
                )}
            </div>

            <div className="card p-4">
                <h3 className="mb-3 font-semibold">{t('acc3.pf.history')}</h3>
                <Table head={<><Th>{t('acc3.date')}</Th><Th>{t('acc3.pf.kind')}</Th><Th>{t('acc3.pf.period')}</Th><Th r>{t('acc3.pf.employees')}</Th><Th>{t('acc3.status')}</Th><Th /></>}>
                    {hist.data?.map((f) => (
                        <tr key={f.id}><Td>{f.created_at.slice(0, 10)}</Td><Td>{t(`acc3.pf.kind.${f.kind}`)}</Td><Td>{f.period}</Td><Td r>{f.employees}</Td><Td>{f.status === 'filed' ? `${t('acc3.pf.filed')} (${f.filing_reference})` : t('acc3.pf.generated')}</Td>
                            <Td r>{f.status !== 'filed' && (
                                <span className="flex justify-end gap-1">
                                    <input className="input w-32" placeholder={t('acc3.tax.reference')} aria-label={t('acc3.tax.reference')} value={ref[f.id] ?? ''} onChange={(e) => setRef({ ...ref, [f.id]: e.target.value })} />
                                    <button className="btn-secondary" disabled={!ref[f.id]} onClick={async () => { try { await api(`accounting3/tax/payroll-filings/${f.id}/filed`, { method: 'POST', body: JSON.stringify({ reference: ref[f.id] }) }); hist.reload(); } catch (e) { setError(errMsg(e)); } }}>{t('acc3.pf.mark_filed')}</button>
                                </span>
                            )}</Td></tr>
                    ))}
                </Table>
            </div>
        </AppLayout>
    );
}
