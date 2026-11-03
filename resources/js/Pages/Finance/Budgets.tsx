import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import PrintPdfButton from '@/Components/PrintPdfButton';
import { Badge, Banner, errMsg, Field, Loading } from './shared';
import { CoreNav, M, Table, Td, Th } from './coreShared';

interface Budget { id: number; name: string; year: number; status: string; total: number }
interface Row {
    key: string; account: string | null; name: string | null; department: string | null; month: number | null;
    budget_ytd: number; actual_ytd: number; variance: number; variance_pct: number | null; forecast: number; budget_full: number; forecast_variance: number;
}
interface Totals { budget: number; actual: number; forecast: number; budget_full: number }
interface Variance { rows: Row[]; totals: { revenue: Totals; expense: Totals; result: Totals } }
interface Account { code: string; name: string; class: number }
interface Line { account: string; department: string; amounts: string[] }

const blank = (): Line => ({ account: '', department: '', amounts: Array(12).fill('') });

export default function Budgets() {
    const t = useT();
    const list = useApi<Budget[]>('accounting/core/budgets');
    const accounts = useApi<Account[]>('accounting/accounts');
    const [sel, setSel] = useState<number | null>(null);
    const [by, setBy] = useState('account');
    const [through, setThrough] = useState(new Date().getMonth() + 1);
    const [method, setMethod] = useState('budget');
    const variance = useApi<Variance>(sel ? `accounting/core/budgets/${sel}/variance?by=${by}&through=${through}&method=${method}` : null, [sel, by, through, method]);
    const [name, setName] = useState('');
    const [year, setYear] = useState(new Date().getFullYear());
    const [lines, setLines] = useState<Line[]>([blank()]);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const plAccounts = (accounts.data ?? []).filter((a) => a.class === 6 || a.class === 7);

    const setLine = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const setAmount = (i: number, m: number, v: string) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, amounts: l.amounts.map((a, k) => (k === m ? v : a)) } : l)));
    const spread = (i: number) => setLines((ls) => ls.map((l, j) => {
        if (j !== i) return l;
        const first = Number(l.amounts[0] || 0);
        return { ...l, amounts: Array(12).fill(String(first)) };
    }));

    async function save() {
        setBusy(true); setError(null); setOk(null);
        const payload = lines.flatMap((l) => l.amounts.map((a, m) => ({ account: l.account, department: l.department || null, month: m + 1, amount: Number(a || 0) })).filter((x) => x.account && x.amount > 0));
        try {
            const b = await api<Budget>('accounting/core/budgets', { method: 'POST', body: JSON.stringify({ name, year, lines: payload }) });
            setOk(t('acc2.budgets.saved')); setSel(b.id); setLines([blank()]); setName(''); list.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    async function approve(id: number) {
        setError(null);
        try { await api(`accounting/core/budgets/${id}/approve`, { method: 'POST' }); setOk(t('acc2.budgets.approved')); list.reload(); } catch (e) { setError(errMsg(e)); }
    }

    const tot = variance.data?.totals;
    const label = (r: Row) => (by === 'account' ? `${r.account} ${r.name ?? ''}` : by === 'department' ? r.department || t('acc2.none') : t(`fin.month.${['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'][(r.month ?? 1) - 1]}`));

    return (
        <AppLayout title={t('acc2.budgets.title')} subtitle={t('acc2.budgets.subtitle')}>
            <Head title={t('acc2.budgets.title')} />
            <CoreNav active="/finance/budgets" />
            <Banner error={error ?? list.error?.message} success={ok} />

            <div className="card mb-4 overflow-x-auto">
                <Loading on={list.loading} />
                <Table head={<><Th>{t('acc2.budgets.name')}</Th><Th>{t('acc2.budgets.year')}</Th><Th>{t('fin.col.status')}</Th><Th r>{t('acc2.budgets.revenue')}</Th><Th /></>}>
                    {list.data?.map((b) => (
                        <tr key={b.id} className={cn(sel === b.id && 'bg-slate-50')}>
                            <Td b>{b.name}</Td><Td>{b.year}</Td><Td><Badge s={b.status === 'approved' ? 'open' : 'issued'} /> <span className="text-xs text-slate-500">{t(`acc2.status.${b.status}`)}</span></Td><Td r>{M(b.total)}</Td>
                            <Td r>
                                <button className="btn-secondary mr-2" onClick={() => setSel(b.id)}>{t('acc2.budgets.variance')}</button>
                                <PrintPdfButton path={`budget-variance/${b.id}?by=${by}`} className="btn-secondary mr-2 inline-flex items-center gap-1" />
                                {b.status === 'draft' && <button className="btn-primary" onClick={() => approve(b.id)}>{t('acc2.budgets.approve')}</button>}
                            </Td>
                        </tr>
                    ))}
                </Table>
                {list.data?.length === 0 && <p className="py-4 text-center text-sm text-slate-500">{t('acc2.budgets.empty')}</p>}
            </div>

            {sel && (
                <div className="card mb-4 p-4">
                    <div className="mb-3 flex flex-wrap items-end gap-2">
                        <Field label={t('acc2.budgets.by')}>
                            <select className="input" value={by} onChange={(e) => setBy(e.target.value)}>
                                {['account', 'department', 'month'].map((k) => <option key={k} value={k}>{t(`acc2.budgets.by_${k}`)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('acc2.budgets.through')}>
                            <select className="input" value={through} onChange={(e) => setThrough(Number(e.target.value))}>
                                {Array.from({ length: 12 }, (_, i) => <option key={i} value={i + 1}>{i + 1}</option>)}
                            </select>
                        </Field>
                        <Field label={t('acc2.budgets.method')}>
                            <select className="input" value={method} onChange={(e) => setMethod(e.target.value)}>
                                <option value="budget">{t('acc2.budgets.method_budget')}</option>
                                <option value="run_rate">{t('acc2.budgets.method_run_rate')}</option>
                            </select>
                        </Field>
                    </div>
                    <Loading on={variance.loading} />
                    {tot && (
                        <div className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 text-sm sm:grid-cols-4 [&>*]:min-w-0">
                            <div><div className="text-xs text-slate-500">{t('acc2.budgets.result_budget')}</div><div className="font-semibold">{M(tot.result.budget)}</div></div>
                            <div><div className="text-xs text-slate-500">{t('acc2.budgets.result_actual')}</div><div className="font-semibold">{M(tot.result.actual)}</div></div>
                            <div><div className="text-xs text-slate-500">{t('acc2.budgets.result_forecast')}</div><div className="font-semibold">{M(tot.result.forecast)}</div></div>
                            <div><div className="text-xs text-slate-500">{t('acc2.budgets.result_full')}</div><div className="font-semibold">{M(tot.result.budget_full)}</div></div>
                        </div>
                    )}
                    <Table head={<><Th>{t('fin.col.name')}</Th><Th r>{t('acc2.budgets.budget')}</Th><Th r>{t('acc2.budgets.actual')}</Th><Th r>{t('acc2.budgets.var')}</Th><Th r>%</Th><Th r>{t('acc2.budgets.forecast')}</Th><Th r>{t('acc2.budgets.full')}</Th></>}>
                        {variance.data?.rows.map((r) => (
                            <tr key={r.key}>
                                <Td>{label(r)}</Td><Td r>{M(r.budget_ytd)}</Td><Td r>{M(r.actual_ytd)}</Td>
                                <Td r b><span className={r.variance < 0 ? 'text-red-700' : 'text-emerald-700'}>{M(r.variance)}</span></Td>
                                <Td r>{r.variance_pct === null ? '-' : `${r.variance_pct}%`}</Td><Td r>{M(r.forecast)}</Td><Td r>{M(r.budget_full)}</Td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-xs text-slate-500">{t('acc2.budgets.sign_hint')}</p>
                </div>
            )}

            <div className="card p-4">
                <h3 className="mb-3 font-semibold">{t('acc2.budgets.new')}</h3>
                <div className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:flex [&>*]:min-w-0">
                    <Field label={t('acc2.budgets.name')}><input className="input w-full" value={name} onChange={(e) => setName(e.target.value)} /></Field>
                    <Field label={t('acc2.budgets.year')}><input type="number" className="input w-full sm:w-28" value={year} onChange={(e) => setYear(Number(e.target.value))} /></Field>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                        <thead><tr className="text-left text-slate-500"><th className="p-1">{t('fin.col.account')}</th><th className="p-1">{t('acc2.budgets.dept')}</th>{Array.from({ length: 12 }, (_, m) => <th key={m} className="p-1 text-right">{m + 1}</th>)}<th /></tr></thead>
                        <tbody>
                            {lines.map((l, i) => (
                                <tr key={i} className="border-t border-slate-100">
                                    <td className="p-1"><select className="input w-44" aria-label={t('fin.col.account')} value={l.account} onChange={(e) => setLine(i, { account: e.target.value })}><option value="" />{plAccounts.map((a) => <option key={a.code} value={a.code}>{a.code} {a.name}</option>)}</select></td>
                                    <td className="p-1"><input className="input w-24" aria-label={t('acc2.budgets.dept')} value={l.department} onChange={(e) => setLine(i, { department: e.target.value })} /></td>
                                    {l.amounts.map((a, m) => <td key={m} className="p-1"><input type="number" min={0} aria-label={`${l.account} ${m + 1}`.trim()} className="input w-24 text-right" value={a} onChange={(e) => setAmount(i, m, e.target.value)} /></td>)}
                                    <td className="p-1"><button type="button" className="btn-secondary" title={t('acc2.budgets.spread_hint')} onClick={() => spread(i)}>{t('acc2.budgets.spread')}</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="mt-3 flex gap-2">
                    <button type="button" className="btn-secondary" onClick={() => setLines((ls) => [...ls, blank()])}>{t('acc2.budgets.add_line')}</button>
                    <button type="button" className="btn-primary" disabled={busy || !name} onClick={save}>{t('acc2.budgets.save')}</button>
                </div>
                <p className="mt-2 text-xs text-slate-500">{t('acc2.budgets.frozen_hint')}</p>
            </div>
        </AppLayout>
    );
}
