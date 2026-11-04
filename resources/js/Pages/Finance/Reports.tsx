import { useState } from 'react';
import { useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { Banner, Field, Loading, monthStart, today } from './shared';
import { useT } from '@/lib/i18n';
import PrintPdfButton from '@/Components/PrintPdfButton';
import ExportButtons from '@/Components/ExportButtons';

type Kind = 'trial' | 'gl' | 'income' | 'balance' | 'vat' | 'dept' | 'ageing' | 'consolidated';
const KINDS: [Kind, string][] = [
    ['trial', 'fin.rep.trial'], ['gl', 'fin.rep.gl'], ['income', 'fin.rep.income'], ['balance', 'fin.rep.balance'],
    ['vat', 'fin.rep.vat'], ['dept', 'fin.rep.dept'], ['ageing', 'fin.rep.ageing'], ['consolidated', 'fin.rep.consolidated'],
];

interface Amt { account: string; name: string; amount: number }
interface TB { rows: { account: string; name: string; debit: number; credit: number; balance: number }[]; total_debit: number; total_credit: number }
interface GL { account: string; opening: number; closing: number; lines: { date: string; number: string; journal_code: string; description: string; debit: number; credit: number; balance: number }[] }
interface IS { revenue: Amt[]; expenses: Amt[]; total_revenue: number; total_expenses: number; result: number }
interface BS { assets: Amt[]; total_assets: number; liabilities_and_equity: Amt[]; total_liabilities_and_equity: number }
interface VAT { vat_collected: number; vat_deductible: number; vat_payable: number; tourist_tax_collected: number }
interface Dept { department: string; revenue: number | string; cost: number | string }
interface Ageing { id: number; number: string; bill_to_name: string; issue_date: string; due_date: string | null; total: number; paid: number; outstanding: number }
interface Consol { hotels: { hotel: { id: number; code: string; name: string }; revenue: number; expenses: number; result: number }[]; total: { revenue: number; expenses: number; result: number } }

const Th = ({ children, r }: { children?: React.ReactNode; r?: boolean }) => <th className={cn('p-2 text-slate-500', r ? 'text-right' : 'text-left')}>{children}</th>;
const Td = ({ children, r, b }: { children?: React.ReactNode; r?: boolean; b?: boolean }) => <td className={cn('p-2', r && 'text-right whitespace-nowrap', b && 'font-semibold')}>{children}</td>;
const M = (n: number | string) => formatCurrency(Number(n));

function Table({ head, children }: { head: React.ReactNode; children: React.ReactNode }) {
    return <div className="overflow-x-auto"><table className="table-auto w-full text-sm"><thead><tr>{head}</tr></thead><tbody className="[&>tr]:border-t [&>tr]:border-slate-100">{children}</tbody></table></div>;
}

function AmtTable({ title, rows, total }: { title: string; rows: Amt[]; total: number }) {
    const t = useT();
    return (
        <div className="card p-4">
            <h4 className="mb-2 font-semibold">{title}</h4>
            <Table head={<><Th>{t('fin.col.account')}</Th><Th>{t('fin.col.name')}</Th><Th r>{t('fin.col.amount')}</Th></>}>
                {rows.map((r) => <tr key={r.account}><Td><span className="font-mono">{r.account}</span></Td><Td>{r.name}</Td><Td r>{M(r.amount)}</Td></tr>)}
                <tr><Td b>{t('fin.col.total')}</Td><Td /><Td r b>{M(total)}</Td></tr>
            </Table>
        </div>
    );
}

export default function Reports() {
    const t = useT();
    const [kind, setKind] = useState<Kind>('trial');
    const [from, setFrom] = useState(monthStart());
    const [to, setTo] = useState(today());
    const [account, setAccount] = useState('');

    const needsRange = !['balance', 'ageing'].includes(kind);
    const path: Record<Kind, string | null> = {
        trial: `reports/trial-balance?from=${from}&to=${to}`,
        gl: account ? `reports/general-ledger?account=${encodeURIComponent(account)}&from=${from}&to=${to}` : null,
        income: `reports/income-statement?from=${from}&to=${to}`,
        balance: `reports/balance-sheet?date=${to}`,
        vat: `reports/vat?from=${from}&to=${to}`,
        dept: `reports/departmental?from=${from}&to=${to}`,
        ageing: 'reports/receivables-ageing',
        consolidated: `reports/consolidated?from=${from}&to=${to}`,
    };
    const p = path[kind];
    const printMap: Partial<Record<Kind, string>> = { trial: 'trial-balance', gl: 'general-ledger', income: 'income-statement', balance: 'balance-sheet', vat: 'vat-return', ageing: 'ageing' };
    const printPath = printMap[kind] && (kind !== 'gl' || account) ? `${printMap[kind]}?from=${from}&to=${to}${kind === 'gl' ? `&account=${encodeURIComponent(account)}` : ''}` : null;
    const res = useApi<unknown>(p ? `accounting/${p}` : null);
    const exportMap: Partial<Record<Kind, string>> = { trial: 'trial-balance', gl: 'general-ledger', income: 'income-statement', balance: 'balance-sheet', vat: 'vat', ageing: 'ageing' };
    const exportKey = exportMap[kind];
    const d = res.data as never;

    return (
        <div>
            <div className="mb-4 flex flex-wrap gap-1">
                {KINDS.map(([k, l]) => (
                    <button key={k} type="button" aria-pressed={k === kind} onClick={() => setKind(k)} className={cn('btn-sm', k === kind ? 'btn-primary' : 'btn-secondary')}>{t(l)}</button>
                ))}
            </div>
            <div className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:flex sm:items-end [&>*]:min-w-0">
                {needsRange && <Field label={t('fin.from')}><input type="date" className="input w-full" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>}
                {kind !== 'ageing' && <Field label={kind === 'balance' ? t('fin.rep.as_at') : t('fin.to')}><input type="date" className="input w-full" value={to} onChange={(e) => setTo(e.target.value)} /></Field>}
                {kind === 'gl' && <Field label={t('fin.col.account')}><input className="input w-full" placeholder={t('fin.rep.account_ph')} aria-label={t('fin.rep.account_ph')} value={account} onChange={(e) => setAccount(e.target.value)} /></Field>}
                <button className="btn-secondary" onClick={res.reload}>{t('fin.refresh')}</button>
                {printPath && <PrintPdfButton path={printPath} className="btn-secondary inline-flex items-center gap-1" />}
            </div>
            {exportKey && (kind !== 'gl' || account) && (
                <ExportButtons className="mb-4" report={exportKey} params={kind === 'balance' ? { date: to } : kind === 'ageing' ? {} : { from, to, account: kind === 'gl' ? account : null }} />
            )}
            {(kind === 'dept' || kind === 'consolidated') && <ExportButtons className="mb-4" report={kind === 'dept' ? 'departmental' : 'consolidated'} params={{ from, to }} />}
            <Banner error={res.error?.message} />
            <Loading on={res.loading} />
            {kind === 'gl' && !account && <p className="text-sm text-slate-500">{t('fin.rep.enter_account')}</p>}
            {res.data != null && !res.loading && <Render kind={kind} d={d} />}
        </div>
    );
}

function Render({ kind, d }: { kind: Kind; d: never }) {
    const t = useT();
    switch (kind) {
        case 'trial': {
            const tb = d as TB;
            return (
                <div className="card p-4">
                    {tb.total_debit !== tb.total_credit && <p className="mb-2 text-sm text-red-600">{t('fin.rep.trial_unbalanced')}</p>}
                    <Table head={<><Th>{t('fin.col.account')}</Th><Th>{t('fin.col.name')}</Th><Th r>{t('fin.col.debit')}</Th><Th r>{t('fin.col.credit')}</Th><Th r>{t('fin.col.balance')}</Th></>}>
                        {tb.rows.map((r) => <tr key={r.account}><Td><span className="font-mono">{r.account}</span></Td><Td>{r.name}</Td><Td r>{M(r.debit)}</Td><Td r>{M(r.credit)}</Td><Td r>{M(r.balance)}</Td></tr>)}
                        <tr><Td b>{t('fin.col.total')}</Td><Td /><Td r b>{M(tb.total_debit)}</Td><Td r b>{M(tb.total_credit)}</Td><Td /></tr>
                    </Table>
                </div>
            );
        }
        case 'gl': {
            const g = d as GL;
            return (
                <div className="card p-4">
                    <Table head={<><Th>{t('fin.col.date')}</Th><Th>{t('fin.rep.entry')}</Th><Th>{t('fin.rep.jnl')}</Th><Th>{t('fin.col.description')}</Th><Th r>{t('fin.col.debit')}</Th><Th r>{t('fin.col.credit')}</Th><Th r>{t('fin.col.balance')}</Th></>}>
                        <tr><Td b>{t('fin.rep.opening')}</Td><Td /><Td /><Td /><Td /><Td /><Td r b>{M(g.opening)}</Td></tr>
                        {g.lines.map((l, i) => <tr key={i}><Td>{formatDate(l.date)}</Td><Td>{l.number}</Td><Td>{l.journal_code}</Td><Td>{l.description}</Td><Td r>{l.debit ? M(l.debit) : ''}</Td><Td r>{l.credit ? M(l.credit) : ''}</Td><Td r>{M(l.balance)}</Td></tr>)}
                        <tr><Td b>{t('fin.rep.closing')}</Td><Td /><Td /><Td /><Td /><Td /><Td r b>{M(g.closing)}</Td></tr>
                    </Table>
                </div>
            );
        }
        case 'income': {
            const s = d as IS;
            return (
                <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                    <AmtTable title={t('fin.rep.revenue_c7')} rows={s.revenue} total={s.total_revenue} />
                    <AmtTable title={t('fin.rep.expenses_c6')} rows={s.expenses} total={s.total_expenses} />
                    <div className={cn('card p-4 text-lg font-semibold lg:col-span-2', s.result >= 0 ? 'text-emerald-700' : 'text-red-600')}>{t('fin.rep.net_result')} {M(s.result)}</div>
                </div>
            );
        }
        case 'balance': {
            const b = d as BS;
            return (
                <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                    <AmtTable title={t('fin.rep.assets')} rows={b.assets} total={b.total_assets} />
                    <AmtTable title={t('fin.rep.liabilities_equity')} rows={b.liabilities_and_equity} total={b.total_liabilities_and_equity} />
                    {b.total_assets !== b.total_liabilities_and_equity && <p className="text-sm text-amber-700 lg:col-span-2">{t('fin.rep.totals_differ', { amount: M(Math.abs(b.total_assets - b.total_liabilities_and_equity)) })}</p>}
                </div>
            );
        }
        case 'vat': {
            const v = d as VAT;
            return (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                    {([[t('fin.rep.vat_collected'), v.vat_collected], [t('fin.rep.vat_deductible'), v.vat_deductible], [t('fin.rep.vat_payable'), v.vat_payable], [t('fin.rep.tourist_tax'), v.tourist_tax_collected]] as const).map(([l, n]) => (
                        <div key={l} className="card p-4"><p className="text-xs text-slate-500">{l}</p><p className="text-lg font-semibold">{M(n)}</p></div>
                    ))}
                </div>
            );
        }
        case 'dept': {
            const rows = d as Dept[];
            return (
                <div className="card p-4">
                    <Table head={<><Th>{t('fin.rep.department')}</Th><Th r>{t('fin.rep.revenue')}</Th><Th r>{t('fin.assets.cost')}</Th><Th r>{t('fin.rep.margin')}</Th></>}>
                        {rows.map((r) => <tr key={r.department}><Td>{r.department}</Td><Td r>{M(r.revenue)}</Td><Td r>{M(r.cost)}</Td><Td r>{M(Number(r.revenue) - Number(r.cost))}</Td></tr>)}
                        {rows.length === 0 && <tr><td colSpan={4} className="p-4 text-center text-slate-500">{t('fin.rep.no_dept')}</td></tr>}
                    </Table>
                </div>
            );
        }
        case 'ageing': {
            const g = (Array.isArray(d) ? {} : d) as Record<string, Ageing[]>;
            const buckets = ['current', '1-30', '31-60', '61-90', '90+'];
            return (
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-5 [&>*]:min-w-0">
                        {buckets.map((b) => <div key={b} className="card p-3"><p className="text-xs text-slate-500">{b === 'current' ? t('fin.rep.current') : b}</p><p className="font-semibold">{M((g[b] ?? []).reduce((s, i) => s + i.outstanding, 0))}</p></div>)}
                    </div>
                    <div className="card p-4">
                        <Table head={<><Th>{t('fin.rep.bucket')}</Th><Th>{t('fin.recv.invoice')}</Th><Th>{t('fin.recv.bill_to')}</Th><Th>{t('fin.recv.due')}</Th><Th r>{t('fin.col.total')}</Th><Th r>{t('fin.rep.outstanding')}</Th></>}>
                            {buckets.flatMap((b) => (g[b] ?? []).map((i) => <tr key={i.id}><Td>{b === 'current' ? t('fin.rep.current') : b}</Td><Td>{i.number}</Td><Td>{i.bill_to_name}</Td><Td>{i.due_date ? formatDate(i.due_date) : '—'}</Td><Td r>{M(i.total)}</Td><Td r>{M(i.outstanding)}</Td></tr>))}
                        </Table>
                    </div>
                </div>
            );
        }
        case 'consolidated': {
            const c = d as Consol;
            return (
                <div className="card p-4">
                    <Table head={<><Th>{t('fin.rep.hotel')}</Th><Th r>{t('fin.rep.revenue')}</Th><Th r>{t('fin.rep.expenses')}</Th><Th r>{t('fin.rep.result')}</Th></>}>
                        {c.hotels.map((h) => <tr key={h.hotel.id}><Td>{h.hotel.code} · {h.hotel.name}</Td><Td r>{M(h.revenue)}</Td><Td r>{M(h.expenses)}</Td><Td r>{M(h.result)}</Td></tr>)}
                        <tr><Td b>{t('fin.rep.group_total')}</Td><Td r b>{M(c.total.revenue)}</Td><Td r b>{M(c.total.expenses)}</Td><Td r b>{M(c.total.result)}</Td></tr>
                    </Table>
                </div>
            );
        }
    }
}
