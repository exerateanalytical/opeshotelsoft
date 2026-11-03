import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Banner, errMsg, Field, Loading, monthStart, today } from './shared';
import { useT } from '@/lib/i18n';

interface Bank { id: number; name: string; kind: string; gl_account: string; currency: string; unmatched: number }
interface StmtLine { id: number; date: string; description: string; reference: string | null; amount: number; balance: number | null; journal_line_id: number | null; match_kind: string | null }
interface LedgerLine { id: number; date: string; number: string; description: string | null; amount: number }
interface Report {
    statement_opening_balance: number | null; statement_closing_balance: number | null; book_opening_balance: number; book_closing_balance: number;
    unmatched_statement_total: number; unmatched_ledger_total: number; adjusted_book_balance: number; adjusted_statement_balance: number | null;
    difference: number | null; reconciled: boolean;
}
const M = (n: number | null | undefined) => (n === null || n === undefined ? '—' : formatCurrency(n));
const COLS = ['date', 'reference', 'description', 'amount', 'debit', 'credit', 'balance'] as const;

export default function Reconciliation() {
    const t = useT();
    const banks = useApi<Bank[]>('accounting/bank-accounts');
    const [bankId, setBankId] = useState<number | null>(null);
    const bank = banks.data?.find((b) => b.id === bankId) ?? banks.data?.[0] ?? null;
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [from, setFrom] = useState(monthStart());
    const [to, setTo] = useState(today());
    const [selected, setSelected] = useState<StmtLine | null>(null);
    const [csv, setCsv] = useState('');
    const [mapping, setMapping] = useState<Record<string, string>>({ date: 'Date', reference: 'Reference', amount: 'Amount', balance: 'Balance', description: '', debit: '', credit: '' });
    const [newBank, setNewBank] = useState({ name: '', kind: 'bank', gl_account: '521100' });
    const [diffAccount, setDiffAccount] = useState('');

    const id = bank?.id;
    const stmt = useApi<StmtLine[]>(id ? `accounting/bank-accounts/${id}/statement-lines` : null, [id]);
    const ledger = useApi<LedgerLine[]>(id ? `accounting/bank-accounts/${id}/ledger-lines` : null, [id]);
    const report = useApi<Report>(id ? `accounting/bank-accounts/${id}/reconciliation?from=${from}&to=${to}` : null, [id, from, to]);

    async function act(path: string, body: object, msg: string) {
        setBusy(true); setError(null); setOk(null);
        try {
            const r = await api<Record<string, unknown>>(path, { method: 'POST', body: JSON.stringify(body) });
            setOk(typeof r?.matched === 'number' ? t('fin.recon.auto_result', { matched: String(r.matched), unmatched: String(r.unmatched) }) : msg);
            setSelected(null);
            stmt.reload(); ledger.reload(); report.reload(); banks.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    function loadFile(f: File | undefined) {
        if (!f) return;
        f.text().then(setCsv);
    }

    return (
        <div className="space-y-4">
            <Banner error={error ?? banks.error?.message} success={ok} />
            <div className="card grid grid-cols-1 gap-2 p-4 sm:flex sm:items-end">
                <Field label={t('fin.recon.treasury_account')}>
                    <select className="input w-full sm:w-64" value={bank?.id ?? ''} onChange={(e) => setBankId(Number(e.target.value))}>
                        {banks.data?.map((b) => <option key={b.id} value={b.id}>{b.name} ({b.gl_account}) · {t('fin.recon.n_open', { count: b.unmatched })}</option>)}
                    </select>
                </Field>
                <Field label={t('fin.from')}><input type="date" className="input w-full" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                <Field label={t('fin.to')}><input type="date" className="input w-full" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                {id && <button className="btn-primary" disabled={busy} onClick={() => act(`accounting/bank-accounts/${id}/auto-match`, {}, t('fin.recon.matched'))}>{t('fin.recon.auto_match')}</button>}
            </div>

            <form className="card grid grid-cols-1 gap-2 p-4 sm:grid-cols-4 sm:items-end"
                onSubmit={(e) => { e.preventDefault(); act('accounting/bank-accounts', newBank, t('fin.recon.account_added')); }}>
                <Field label={t('fin.recon.new_account_name')}><input className="input w-full" required value={newBank.name} onChange={(e) => setNewBank({ ...newBank, name: e.target.value })} /></Field>
                <Field label={t('fin.recon.kind')}>
                    <select className="input w-full" value={newBank.kind} onChange={(e) => setNewBank({ ...newBank, kind: e.target.value, gl_account: e.target.value === 'mobile_money' ? '521500' : '521100' })}>
                        <option value="bank">{t('fin.recon.bank')}</option><option value="mobile_money">{t('fin.recon.mobile_money')}</option>
                    </select>
                </Field>
                <Field label={t('fin.recon.gl_account')}><input className="input w-full" value={newBank.gl_account} onChange={(e) => setNewBank({ ...newBank, gl_account: e.target.value })} /></Field>
                <button className="btn-secondary" disabled={busy}>{t('fin.recon.add_account')}</button>
            </form>

            {id && (
                <form className="card space-y-2 p-4" onSubmit={(e) => {
                    e.preventDefault();
                    const m = Object.fromEntries(Object.entries(mapping).filter(([, v]) => v.trim() !== ''));
                    act(`accounting/bank-accounts/${id}/statements`, { csv, mapping: m }, t('fin.recon.imported'));
                }}>
                    <h4 className="font-semibold">{t('fin.recon.import_title')}</h4>
                    <input type="file" accept=".csv,.txt" className="text-sm" onChange={(e) => loadFile(e.target.files?.[0])} />
                    <textarea className="input h-24 w-full font-mono text-sm" placeholder={t('fin.recon.paste_csv')} value={csv} onChange={(e) => setCsv(e.target.value)} />
                    <p className="text-xs text-slate-500">{t('fin.recon.mapping_hint')}</p>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-7">
                        {COLS.map((c) => (
                            <Field key={c} label={t(`fin.recon.col.${c}`)}><input className="input w-full" value={mapping[c]} onChange={(e) => setMapping({ ...mapping, [c]: e.target.value })} /></Field>
                        ))}
                    </div>
                    <button className="btn-primary" disabled={busy || !csv}>{t('fin.recon.import')}</button>
                </form>
            )}

            {report.data && (
                <div className="card grid grid-cols-2 gap-2 p-4 text-sm sm:grid-cols-4">
                    <div><p className="text-xs text-slate-500">{t('fin.recon.stmt_opening')}</p>{M(report.data.statement_opening_balance)}</div>
                    <div><p className="text-xs text-slate-500">{t('fin.recon.stmt_closing')}</p>{M(report.data.statement_closing_balance)}</div>
                    <div><p className="text-xs text-slate-500">{t('fin.recon.book_opening')}</p>{M(report.data.book_opening_balance)}</div>
                    <div><p className="text-xs text-slate-500">{t('fin.recon.book_closing')}</p>{M(report.data.book_closing_balance)}</div>
                    <div><p className="text-xs text-slate-500">{t('fin.recon.in_stmt_not_books')}</p>{M(report.data.unmatched_statement_total)}</div>
                    <div><p className="text-xs text-slate-500">{t('fin.recon.in_books_not_stmt')}</p>{M(report.data.unmatched_ledger_total)}</div>
                    <div><p className="text-xs text-slate-500">{t('fin.closing.difference')}</p>{M(report.data.difference)}</div>
                    <div><span className={`badge-${report.data.reconciled ? 'success' : 'warning'} rounded px-2 py-0.5 text-xs`}>{report.data.reconciled ? t('fin.recon.reconciled') : t('fin.recon.not_reconciled')}</span></div>
                </div>
            )}

            {selected && (
                <div className="card space-y-2 p-4">
                    <p className="text-sm">{t('fin.recon.selected')} {formatDate(selected.date)} · {selected.description} · <b>{M(selected.amount)}</b></p>
                    <p className="text-xs text-slate-500">{t('fin.recon.select_hint')}</p>
                    <div className="flex flex-wrap items-end gap-2">
                        <Field label={t('fin.recon.counter_account')}><input className="input w-full sm:w-40" value={diffAccount} onChange={(e) => setDiffAccount(e.target.value)} /></Field>
                        <button className="btn-secondary" disabled={busy} onClick={() => act(`accounting/statement-lines/${selected.id}/post-difference`, diffAccount ? { account: diffAccount } : {}, t('fin.recon.diff_posted'))}>{t('fin.recon.post_as_entry')}</button>
                        <button className="btn-secondary" onClick={() => setSelected(null)}>{t('common.cancel')}</button>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="card overflow-x-auto p-2">
                    <h4 className="p-2 font-semibold">{t('fin.recon.stmt_lines')}</h4>
                    <Loading on={stmt.loading} />
                    <table className="table-auto w-full text-sm">
                        <tbody>
                            {stmt.data?.map((l) => (
                                <tr key={l.id} className={`border-t border-slate-100 ${selected?.id === l.id ? 'bg-slate-50' : ''}`}>
                                    <td className="p-2 whitespace-nowrap">{formatDate(l.date)}</td>
                                    <td className="p-2">{l.description}<span className="block text-xs text-slate-500">{l.reference}</span></td>
                                    <td className="p-2 text-right whitespace-nowrap">{M(l.amount)}</td>
                                    <td className="p-2 text-right">
                                        {l.journal_line_id
                                            ? <button className="btn-secondary text-xs" disabled={busy} onClick={() => act(`accounting/statement-lines/${l.id}/unmatch`, {}, t('fin.recon.unmatched'))}>{t('fin.recon.unmatch')}</button>
                                            : <button className="btn-secondary text-xs" onClick={() => setSelected(l)}>{t('fin.recon.select')}</button>}
                                    </td>
                                </tr>
                            ))}
                            {stmt.data?.length === 0 && <tr><td className="p-6 text-center text-slate-400">{t('fin.recon.no_stmt_lines')}</td></tr>}
                        </tbody>
                    </table>
                </div>
                <div className="card overflow-x-auto p-2">
                    <h4 className="p-2 font-semibold">{t('fin.recon.open_ledger')}</h4>
                    <Loading on={ledger.loading} />
                    <table className="table-auto w-full text-sm">
                        <tbody>
                            {ledger.data?.map((l) => (
                                <tr key={l.id} className="border-t border-slate-100">
                                    <td className="p-2 whitespace-nowrap">{formatDate(l.date)}</td>
                                    <td className="p-2">{l.description}<span className="block text-xs text-slate-500">{l.number}</span></td>
                                    <td className="p-2 text-right whitespace-nowrap">{M(l.amount)}</td>
                                    <td className="p-2 text-right">
                                        {selected && <button className="btn-primary text-xs" disabled={busy} onClick={() => act(`accounting/statement-lines/${selected.id}/match`, { journal_line_id: l.id }, t('fin.recon.matched'))}>{t('fin.recon.match')}</button>}
                                    </td>
                                </tr>
                            ))}
                            {ledger.data?.length === 0 && <tr><td className="p-6 text-center text-slate-400">{t('fin.recon.all_reconciled')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
