import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { cn, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, Loading, today } from './shared';
import { CoreNav, M, Table, Td, Th, yearStart } from './coreShared';

interface Amt { account: string; name: string; amount: number }
interface Cons {
    currency: string; closing_rate: number; average_rate: number;
    hotels: { hotel: { id: number; code: string; name: string }; revenue: number; expenses: number; result: number }[];
    eliminations: { intercompany_services: number; ic_difference: number; receivable: number; payable: number };
    trial_balance: { rows: { account: string; name: string; debit: number; credit: number; elim_debit: number; elim_credit: number; balance: number }[]; total_debit: number; total_credit: number; balanced: boolean };
    income_statement: { revenue: Amt[]; expenses: Amt[]; total_revenue: number; total_expenses: number; result: number };
    balance_sheet: { assets: Amt[]; liabilities_equity: Amt[]; result: number; cta: number; total_assets: number; total_liabilities_equity: number };
}
interface Ic { id: number; kind: string; date: string; amount: number; description: string; from_code: string; to_code: string; status: string }
interface Hotel { id: number; code: string; name: string }

type View = 'tb' | 'is' | 'bs';

export default function Consolidation() {
    const t = useT();
    const [from, setFrom] = useState(yearStart());
    const [to, setTo] = useState(today());
    const [currency, setCurrency] = useState('XAF');
    const [closing, setClosing] = useState('');
    const [average, setAverage] = useState('');
    const [view, setView] = useState<View>('is');
    const q = `from=${from}&to=${to}&currency=${currency}${closing ? `&closing_rate=${closing}` : ''}${average ? `&average_rate=${average}` : ''}`;
    const cons = useApi<Cons>(`accounting/core/consolidation?${q}`, [q]);
    const ics = useApi<Ic[]>('accounting/core/intercompany');
    const hotels = useApi<{ data: Hotel[] }>('hotels');
    const [ic, setIc] = useState({ from_hotel_id: '', to_hotel_id: '', kind: 'service', date: today(), amount: '', description: '' });
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const c = cons.data;
    const f = (n: number) => M(n, c?.currency ?? 'XAF');

    async function postIc() {
        setError(null); setOk(null);
        try {
            await api('accounting/core/intercompany', { method: 'POST', body: JSON.stringify({ ...ic, from_hotel_id: Number(ic.from_hotel_id), to_hotel_id: Number(ic.to_hotel_id), amount: Number(ic.amount) }) });
            setOk(t('acc2.saved')); ics.reload(); cons.reload();
        } catch (e) { setError(errMsg(e)); }
    }
    async function reverse(id: number) {
        const reason = window.prompt(t('acc2.ic.reason'));
        if (!reason) return;
        setError(null);
        try { await api(`accounting/core/intercompany/${id}/reverse`, { method: 'POST', body: JSON.stringify({ reason }) }); ics.reload(); cons.reload(); } catch (e) { setError(errMsg(e)); }
    }

    return (
        <AppLayout title={t('acc2.cons.title')} subtitle={t('acc2.cons.subtitle')}>
            <Head title={t('acc2.cons.title')} />
            <CoreNav active="/finance/consolidation" />
            <Banner error={error ?? cons.error?.message} success={ok} />

            <div className="card mb-4 p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Field label={t('acc2.from')}><input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label={t('acc2.to')}><input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                    <Field label={t('acc2.cons.currency')}><input className="input w-24 uppercase" maxLength={3} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} /></Field>
                    {currency !== 'XAF' && currency.length === 3 && (
                        <>
                            <Field label={t('acc2.cons.closing_rate')}><input type="number" step="any" className="input w-32" value={closing} onChange={(e) => setClosing(e.target.value)} /></Field>
                            <Field label={t('acc2.cons.average_rate')}><input type="number" step="any" className="input w-32" value={average} onChange={(e) => setAverage(e.target.value)} /></Field>
                        </>
                    )}
                    <div className="flex gap-1">
                        {(['is', 'bs', 'tb'] as View[]).map((v) => (
                            <button key={v} onClick={() => setView(v)} className={cn(view === v ? 'btn-primary' : 'btn-secondary')}>{t(`acc2.cons.view.${v}`)}</button>
                        ))}
                    </div>
                </div>
                {currency !== 'XAF' && <p className="mb-3 text-xs text-slate-500">{t('acc2.cons.rate_hint', { currency })}</p>}
                <Loading on={cons.loading} />
                {c && (
                    <>
                        <div className="mb-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4 [&>*]:min-w-0">
                            <div><div className="text-xs text-slate-500">{t('acc2.cons.ic_services')}</div><div className="font-semibold">{M(c.eliminations.intercompany_services)}</div></div>
                            <div><div className="text-xs text-slate-500">{t('acc2.cons.ic_receivable')}</div><div className="font-semibold">{M(c.eliminations.receivable)}</div></div>
                            <div><div className="text-xs text-slate-500">{t('acc2.cons.ic_payable')}</div><div className="font-semibold">{M(c.eliminations.payable)}</div></div>
                            <div><div className="text-xs text-slate-500">{t('acc2.cons.ic_diff')}</div><div className={cn('font-semibold', c.eliminations.ic_difference !== 0 && 'text-red-700')}>{M(c.eliminations.ic_difference)}</div></div>
                        </div>
                        {c.eliminations.ic_difference !== 0 && <p className="mb-3 text-sm text-red-700">{t('acc2.cons.ic_break')}</p>}

                        {view === 'is' && (
                            <>
                                <Table head={<><Th>{t('fin.col.account')}</Th><Th>{t('fin.col.name')}</Th><Th r>{t('fin.col.amount')}</Th></>}>
                                    {c.income_statement.revenue.map((r) => <tr key={r.account}><Td mono>{r.account}</Td><Td>{r.name}</Td><Td r>{f(r.amount)}</Td></tr>)}
                                    <tr><Td b>{t('acc2.cons.total_revenue')}</Td><Td /><Td r b>{f(c.income_statement.total_revenue)}</Td></tr>
                                    {c.income_statement.expenses.map((r) => <tr key={r.account}><Td mono>{r.account}</Td><Td>{r.name}</Td><Td r>{f(r.amount)}</Td></tr>)}
                                    <tr><Td b>{t('acc2.cons.total_expenses')}</Td><Td /><Td r b>{f(c.income_statement.total_expenses)}</Td></tr>
                                    <tr><Td b>{t('acc2.cons.result')}</Td><Td /><Td r b>{f(c.income_statement.result)}</Td></tr>
                                </Table>
                                <h4 className="mb-2 mt-4 font-semibold">{t('acc2.cons.by_hotel')}</h4>
                                <Table head={<><Th>{t('acc2.cons.hotel')}</Th><Th r>{t('acc2.dim.revenue')}</Th><Th r>{t('acc2.dim.expenses')}</Th><Th r>{t('acc2.dim.result')}</Th></>}>
                                    {c.hotels.map((h) => <tr key={h.hotel.id}><Td b>{h.hotel.code} {h.hotel.name}</Td><Td r>{M(h.revenue)}</Td><Td r>{M(h.expenses)}</Td><Td r>{M(h.result)}</Td></tr>)}
                                </Table>
                            </>
                        )}
                        {view === 'bs' && (
                            <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                                <Table head={<><Th>{t('acc2.cons.assets')}</Th><Th r>{t('fin.col.amount')}</Th></>}>
                                    {c.balance_sheet.assets.map((r) => <tr key={r.account}><Td>{r.account} {r.name}</Td><Td r>{f(r.amount)}</Td></tr>)}
                                    <tr><Td b>{t('fin.col.total')}</Td><Td r b>{f(c.balance_sheet.total_assets)}</Td></tr>
                                </Table>
                                <Table head={<><Th>{t('acc2.cons.liabilities')}</Th><Th r>{t('fin.col.amount')}</Th></>}>
                                    {c.balance_sheet.liabilities_equity.map((r) => <tr key={r.account}><Td>{r.account} {r.name}</Td><Td r>{f(r.amount)}</Td></tr>)}
                                    <tr><Td>{t('acc2.cons.result')}</Td><Td r>{f(c.balance_sheet.result)}</Td></tr>
                                    {c.balance_sheet.cta !== 0 && <tr><Td>{t('acc2.cons.cta')}</Td><Td r>{f(c.balance_sheet.cta)}</Td></tr>}
                                    <tr><Td b>{t('fin.col.total')}</Td><Td r b>{f(c.balance_sheet.total_liabilities_equity)}</Td></tr>
                                </Table>
                            </div>
                        )}
                        {view === 'tb' && (
                            <Table head={<><Th>{t('fin.col.account')}</Th><Th>{t('fin.col.name')}</Th><Th r>{t('acc2.cons.debit')}</Th><Th r>{t('acc2.cons.credit')}</Th><Th r>{t('acc2.cons.elim_debit')}</Th><Th r>{t('acc2.cons.elim_credit')}</Th><Th r>{t('fin.col.balance')}</Th></>}>
                                {c.trial_balance.rows.map((r) => <tr key={r.account}><Td mono>{r.account}</Td><Td>{r.name}</Td><Td r>{f(r.debit)}</Td><Td r>{f(r.credit)}</Td><Td r>{f(r.elim_debit)}</Td><Td r>{f(r.elim_credit)}</Td><Td r>{f(r.balance)}</Td></tr>)}
                                <tr><Td b>{t('fin.col.total')}</Td><Td /><Td r b>{f(c.trial_balance.total_debit)}</Td><Td r b>{f(c.trial_balance.total_credit)}</Td><Td /><Td /><Td r b>{c.trial_balance.balanced ? t('acc2.cons.balanced') : t('acc2.cons.unbalanced')}</Td></tr>
                            </Table>
                        )}
                    </>
                )}
            </div>

            <div className="card p-4">
                <h3 className="mb-1 font-semibold">{t('acc2.ic.title')}</h3>
                <p className="mb-3 text-xs text-slate-500">{t('acc2.ic.hint')}</p>
                <form className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4 [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); postIc(); }}>
                    <Field label={t('acc2.ic.from')}><select className="input w-full" required value={ic.from_hotel_id} onChange={(e) => setIc({ ...ic, from_hotel_id: e.target.value })}><option value="" />{hotels.data?.data.map((h) => <option key={h.id} value={h.id}>{h.code} {h.name}</option>)}</select></Field>
                    <Field label={t('acc2.ic.to')}><select className="input w-full" required value={ic.to_hotel_id} onChange={(e) => setIc({ ...ic, to_hotel_id: e.target.value })}><option value="" />{hotels.data?.data.map((h) => <option key={h.id} value={h.id}>{h.code} {h.name}</option>)}</select></Field>
                    <Field label={t('acc2.ic.kind')}><select className="input w-full" value={ic.kind} onChange={(e) => setIc({ ...ic, kind: e.target.value })}>{['service', 'funding', 'settlement'].map((k) => <option key={k} value={k}>{t(`acc2.ic.kind.${k}`)}</option>)}</select></Field>
                    <Field label={t('fin.col.date')}><input type="date" className="input w-full" value={ic.date} onChange={(e) => setIc({ ...ic, date: e.target.value })} /></Field>
                    <Field label={t('fin.col.amount')}><input type="number" min={1} required className="input w-full" value={ic.amount} onChange={(e) => setIc({ ...ic, amount: e.target.value })} /></Field>
                    <Field label={t('acc2.acc.description')}><input className="input w-full" required value={ic.description} onChange={(e) => setIc({ ...ic, description: e.target.value })} /></Field>
                    <button className="btn-primary col-span-2">{t('acc2.ic.post')}</button>
                </form>
                <Table head={<><Th>{t('fin.col.date')}</Th><Th>{t('acc2.ic.kind')}</Th><Th>{t('acc2.ic.flow')}</Th><Th>{t('acc2.acc.description')}</Th><Th r>{t('fin.col.amount')}</Th><Th /></>}>
                    {ics.data?.map((x) => (
                        <tr key={x.id}><Td>{formatDate(x.date)}</Td><Td>{t(`acc2.ic.kind.${x.kind}`)}</Td><Td>{x.from_code} → {x.to_code}</Td><Td>{x.description}</Td><Td r>{M(x.amount)}</Td>
                            <Td r>{x.status === 'posted' ? <button className="btn-secondary" onClick={() => reverse(x.id)}>{t('acc2.ic.reverse')}</button> : <span className="text-xs text-slate-500">{t('acc2.status.reversed')}</span>}</Td></tr>
                    ))}
                </Table>
            </div>
        </AppLayout>
    );
}
