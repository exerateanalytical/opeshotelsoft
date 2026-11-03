import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, Loading, today } from './shared';
import { CoreNav, M, Table, Td, Th, yearStart } from './coreShared';

const KINDS = ['department', 'outlet', 'project', 'cost_centre'] as const;
interface Dim { id: number; kind: string; code: string; name: string; active: boolean }
interface Def { id: number; department: string; outlet: string | null; project: string | null; cost_centre: string | null }
interface Rep { by: string; rows: { code: string; name: string | null; revenue: number; expenses: number; result: number }[]; total: { revenue: number; expenses: number } }

export default function Dimensions() {
    const t = useT();
    const dims = useApi<{ dimensions: Dim[]; defaults: Def[] }>('accounting/core/dimensions');
    const [by, setBy] = useState<(typeof KINDS)[number]>('outlet');
    const [from, setFrom] = useState(yearStart());
    const [to, setTo] = useState(today());
    const rep = useApi<Rep>(`accounting/core/reports/dimension?by=${by}&from=${from}&to=${to}`, [by, from, to]);
    const [d, setD] = useState({ kind: 'outlet', code: '', name: '' });
    const [df, setDf] = useState({ department: '', outlet: '', project: '', cost_centre: '' });
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    async function post(path: string, body: object, after: () => void) {
        setError(null); setOk(null);
        try { await api(path, { method: 'POST', body: JSON.stringify(body) }); setOk(t('acc2.saved')); after(); dims.reload(); rep.reload(); } catch (e) { setError(errMsg(e)); }
    }

    return (
        <AppLayout title={t('acc2.dim.title')} subtitle={t('acc2.dim.subtitle')}>
            <Head title={t('acc2.dim.title')} />
            <CoreNav active="/finance/dimensions" />
            <Banner error={error ?? dims.error?.message} success={ok} />

            <div className="card mb-4 p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Field label={t('acc2.dim.by')}>
                        <select className="input" value={by} onChange={(e) => setBy(e.target.value as typeof by)}>{KINDS.map((k) => <option key={k} value={k}>{t(`acc2.dim.kind.${k}`)}</option>)}</select>
                    </Field>
                    <Field label={t('acc2.from')}><input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label={t('acc2.to')}><input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                </div>
                <Loading on={rep.loading} />
                <Table head={<><Th>{t(`acc2.dim.kind.${by}`)}</Th><Th r>{t('acc2.dim.revenue')}</Th><Th r>{t('acc2.dim.expenses')}</Th><Th r>{t('acc2.dim.result')}</Th></>}>
                    {rep.data?.rows.map((r) => (
                        <tr key={r.code}><Td b>{r.code === '' ? t('acc2.none') : `${r.code}${r.name && r.name !== r.code ? ` - ${r.name}` : ''}`}</Td><Td r>{M(r.revenue)}</Td><Td r>{M(r.expenses)}</Td><Td r b>{M(r.result)}</Td></tr>
                    ))}
                    {rep.data && <tr><Td b>{t('fin.col.total')}</Td><Td r b>{M(rep.data.total.revenue)}</Td><Td r b>{M(rep.data.total.expenses)}</Td><Td r b>{M(rep.data.total.revenue - rep.data.total.expenses)}</Td></tr>}
                </Table>
            </div>

            <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                <div className="card p-4">
                    <h3 className="mb-3 font-semibold">{t('acc2.dim.values')}</h3>
                    <form className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:flex sm:items-end [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); post('accounting/core/dimensions', d, () => setD({ ...d, code: '', name: '' })); }}>
                        <Field label={t('acc2.dim.kind')}><select className="input w-full" value={d.kind} onChange={(e) => setD({ ...d, kind: e.target.value })}>{KINDS.map((k) => <option key={k} value={k}>{t(`acc2.dim.kind.${k}`)}</option>)}</select></Field>
                        <Field label={t('acc2.dim.code')}><input className="input w-full" required value={d.code} onChange={(e) => setD({ ...d, code: e.target.value })} /></Field>
                        <Field label={t('fin.col.name')}><input className="input w-full" required value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} /></Field>
                        <button className="btn-primary sm:col-span-2">{t('common.save')}</button>
                    </form>
                    <Table head={<><Th>{t('acc2.dim.kind')}</Th><Th>{t('acc2.dim.code')}</Th><Th>{t('fin.col.name')}</Th></>}>
                        {dims.data?.dimensions.map((x) => <tr key={x.id}><Td>{t(`acc2.dim.kind.${x.kind}`)}</Td><Td mono>{x.code}</Td><Td>{x.name}</Td></tr>)}
                    </Table>
                </div>
                <div className="card p-4">
                    <h3 className="mb-1 font-semibold">{t('acc2.dim.defaults')}</h3>
                    <p className="mb-3 text-xs text-slate-500">{t('acc2.dim.defaults_hint')}</p>
                    <form className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); post('accounting/core/dimension-defaults', df, () => setDf({ department: '', outlet: '', project: '', cost_centre: '' })); }}>
                        <Field label={t('acc2.dim.kind.department')}><input className="input w-full" required value={df.department} onChange={(e) => setDf({ ...df, department: e.target.value })} /></Field>
                        <Field label={t('acc2.dim.kind.outlet')}><input className="input w-full" value={df.outlet} onChange={(e) => setDf({ ...df, outlet: e.target.value })} /></Field>
                        <Field label={t('acc2.dim.kind.project')}><input className="input w-full" value={df.project} onChange={(e) => setDf({ ...df, project: e.target.value })} /></Field>
                        <Field label={t('acc2.dim.kind.cost_centre')}><input className="input w-full" value={df.cost_centre} onChange={(e) => setDf({ ...df, cost_centre: e.target.value })} /></Field>
                        <button className="btn-primary sm:col-span-2">{t('common.save')}</button>
                    </form>
                    <Table head={<><Th>{t('acc2.dim.kind.department')}</Th><Th>{t('acc2.dim.kind.outlet')}</Th><Th>{t('acc2.dim.kind.project')}</Th><Th>{t('acc2.dim.kind.cost_centre')}</Th></>}>
                        {dims.data?.defaults.map((x) => <tr key={x.id}><Td mono>{x.department}</Td><Td>{x.outlet}</Td><Td>{x.project}</Td><Td>{x.cost_centre}</Td></tr>)}
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
