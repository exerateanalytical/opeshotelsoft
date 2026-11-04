import { useState } from 'react';
import { Download, Paperclip } from 'lucide-react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '@/lib/utils';
import EmptyState from '@/Components/ui/EmptyState';
import { Banner, errMsg, Field, Loading, today } from './shared';
import { downloadFile, uploadFile } from './ab3Shared';

/* GAP-A accounting core affordances: CSV export, FX exposure, entry attachments + audit, budget detail + group roll-up, bank import history. */

const M = (n: number | string | null | undefined) => formatCurrency(Number(n ?? 0));

type Cell = string | number | null | undefined;

/** Client-side CSV download of rows already on screen (UTF-8 with BOM so Excel opens accents correctly). */
export function CsvButton({ filename, header, rows, label }: { filename: string; header: string[]; rows: Cell[][]; label?: string }) {
    const t = useT();
    const esc = (v: Cell) => {
        const s = v === null || v === undefined ? '' : String(v);
        return /[",;\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    function go() {
        const csv = [header, ...rows].map((r) => r.map(esc).join(',')).join('\r\n');
        const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }
    return (
        <button type="button" className="btn-secondary" onClick={go} disabled={rows.length === 0}>
            <Download className="h-4 w-4" /> {label ?? t('gapa.csv')}
        </button>
    );
}

/** Small responsive table: desktop table, card list under 768 px (table-cards + data-label). */
function RTable({ caption, cols, rows }: { caption: string; cols: { h: string; r?: boolean }[]; rows: { key: string | number; cells: React.ReactNode[] }[] }) {
    return (
        <div className="table-wrap">
            <table className="table-auto table-cards w-full text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead><tr>{cols.map((c, i) => <th key={i} scope="col" className={c.r ? 'p-2 text-right text-slate-500' : 'p-2 text-left text-slate-500'}>{c.h}</th>)}</tr></thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.key} className="border-t border-slate-100">
                            {row.cells.map((c, i) => <td key={i} data-label={cols[i].h} className={cols[i].r ? 'num p-2' : 'p-2'}>{c}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ---------------------------------------------------------------- FX exposure + month-end revaluation (row 11 / 20)

interface FxPos { account: string; partner_type: string | null; partner_id: number | null; currency: string; fx_balance: number; book_balance: number; rate: number; revalued: number; difference: number }
interface FxExposure { date: string; rates: Record<string, number>; positions: FxPos[]; gain: number; loss: number }

export function GapaFxExposure() {
    const t = useT();
    const can = useCan();
    const [date, setDate] = useState(today());
    const fx = useApi<FxExposure>(`accounting/core/fx/exposure?date=${date}`, [date]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    async function runMonthEnd() {
        if (!window.confirm(t('gapa.fx.confirm', { date }))) return;
        setBusy(true); setError(null); setOk(null);
        try {
            const r = await api<{ status: string; run_id?: number }>('accounting/core/fx/month-end', { method: 'POST', body: JSON.stringify({ date }) });
            setOk(t(`gapa.fx.status.${r.status}`));
            fx.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    const d = fx.data;
    return (
        <section className="card p-4" aria-labelledby="gapa-fx-title">
            <div className="mb-3 grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:items-end [&>*]:min-w-0">
                <div className="sm:mr-auto">
                    <h2 id="gapa-fx-title" className="text-base font-semibold">{t('gapa.fx.title')}</h2>
                    <p className="text-sm text-slate-600">{t('gapa.fx.hint')}</p>
                </div>
                <Field label={t('gapa.fx.date')}><input type="date" className="input w-full" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
                {can('accounting.manage') && (
                    <button type="button" className="btn-primary" disabled={busy || !d || d.positions.length === 0} onClick={runMonthEnd}>{t('gapa.fx.run')}</button>
                )}
                {d && d.positions.length > 0 && (
                    <CsvButton filename={`fx-exposure-${date}.csv`} header={['account', 'currency', 'fx_balance', 'rate', 'book_balance', 'revalued', 'difference']}
                        rows={d.positions.map((p) => [p.account, p.currency, p.fx_balance, p.rate, p.book_balance, p.revalued, p.difference])} />
                )}
            </div>
            <Banner error={error ?? fx.error?.message} success={ok} />
            <Loading on={fx.loading} />
            {d && (
                <>
                    {Object.keys(d.rates).length > 0 && (
                        <p className="mb-2 text-sm text-slate-600">{t('gapa.fx.rates')} {Object.entries(d.rates).map(([c, r]) => `${c} ${formatNumber(r)}`).join(' · ')}</p>
                    )}
                    {d.positions.length === 0 ? (
                        <EmptyState title={t('gapa.fx.empty_title')} description={t('gapa.fx.empty_text')} />
                    ) : (
                        <>
                            <RTable caption={t('gapa.fx.title')}
                                cols={[{ h: t('gapa.col.account') }, { h: t('gapa.fx.partner') }, { h: t('gapa.fx.foreign'), r: true }, { h: t('gapa.fx.rate'), r: true }, { h: t('gapa.fx.book'), r: true }, { h: t('gapa.fx.revalued'), r: true }, { h: t('gapa.fx.difference'), r: true }]}
                                rows={d.positions.map((p, i) => ({
                                    key: i, cells: [
                                        <span className="font-mono">{p.account}</span>, p.partner_type ? `${p.partner_type} #${p.partner_id}` : '—',
                                        `${formatNumber(p.fx_balance)} ${p.currency}`, formatNumber(p.rate), M(p.book_balance), M(p.revalued),
                                        <span className={p.difference < 0 ? 'text-red-700' : 'text-emerald-700'}>{M(p.difference)}</span>,
                                    ],
                                }))} />
                            <p className="mt-2 text-sm">{t('gapa.fx.gain')} <b>{M(d.gain)}</b> · {t('gapa.fx.loss')} <b>{M(d.loss)}</b></p>
                        </>
                    )}
                </>
            )}
        </section>
    );
}

// ---------------------------------------------------------------- Journal entry attachments + audit trail (row 12 / 19)

interface Attachment { id: number; filename: string; mime: string; size: number; uploaded_by: number | null; created_at: string }
interface Trail { id: number; action: string; actor: string | null; payload: Record<string, unknown> | null; created_at: string }
interface Audit { entry: { created_by: number | null; approved_by: number | null; reversed: boolean; reverses_entry_id: number | null; created_at: string }; trail: Trail[]; attachments: Attachment[] }

export function GapaEntryPanel({ entryId, number }: { entryId: number; number: string }) {
    const t = useT();
    const can = useCan();
    const files = useApi<Attachment[]>(`accounting/core/entries/${entryId}/attachments`, [entryId]);
    const canAudit = can('accounting.audit');
    const audit = useApi<Audit>(canAudit ? `accounting/core/entries/${entryId}/audit` : null, [entryId, canAudit]);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function upload(f: File | undefined) {
        if (!f) return;
        setBusy(true); setError(null); setOk(null);
        try {
            const fd = new FormData();
            fd.append('file', f);
            await uploadFile(`accounting/core/entries/${entryId}/attachments`, fd);
            setOk(t('gapa.att.uploaded', { name: f.name }));
            files.reload(); audit.reload();
        } catch (e) { setError(errMsg(e)); } finally { setBusy(false); }
    }

    async function download(a: Attachment) {
        setError(null);
        try { await downloadFile(`accounting/core/attachments/${a.id}`, a.filename); audit.reload(); } catch (e) { setError(errMsg(e)); }
    }

    return (
        <div className="mt-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <section className="border border-slate-200 p-3" aria-labelledby={`gapa-att-${entryId}`}>
                <h4 id={`gapa-att-${entryId}`} className="mb-2 font-semibold">{t('gapa.att.title')}</h4>
                <Banner error={error ?? files.error?.message} success={ok} />
                <Loading on={files.loading} />
                {files.data && files.data.length === 0 && <p className="text-sm text-slate-600">{t('gapa.att.empty')}</p>}
                <ul className="divide-y divide-slate-100">
                    {files.data?.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                            <Paperclip className="h-4 w-4 text-slate-500" aria-hidden />
                            <span className="min-w-0 flex-1 break-all">{a.filename}<span className="block text-slate-500">{formatNumber(Math.round(a.size / 1024))} Ko · {formatDateTime(a.created_at)}</span></span>
                            <button type="button" className="btn-secondary btn-sm" onClick={() => download(a)} aria-label={t('gapa.att.download_name', { name: a.filename })}>{t('gapa.att.download')}</button>
                        </li>
                    ))}
                </ul>
                {can('accounting.post_manual') && (
                    <div className="mt-2">
                        <Field label={t('gapa.att.add')}>
                            <input type="file" className="input w-full" disabled={busy} accept=".pdf,.png,.jpg,.jpeg,.xlsx,.csv,.docx,.txt"
                                onChange={(e) => { upload(e.target.files?.[0]); e.target.value = ''; }} />
                        </Field>
                        <p className="mt-1 text-sm text-slate-600">{t('gapa.att.hint', { number })}</p>
                    </div>
                )}
            </section>
            {canAudit && (
                <section className="border border-slate-200 p-3" aria-labelledby={`gapa-aud-${entryId}`}>
                    <h4 id={`gapa-aud-${entryId}`} className="mb-2 font-semibold">{t('gapa.audit.title')}</h4>
                    {audit.error && <p className="text-sm text-red-700">{audit.error.message}</p>}
                    <Loading on={audit.loading} />
                    {audit.data && (
                        <>
                            <p className="mb-2 text-sm text-slate-600">{t('gapa.audit.created', { date: formatDateTime(audit.data.entry.created_at) })}{audit.data.entry.reversed ? ` · ${t('gapa.audit.reversed')}` : ''}</p>
                            {audit.data.trail.length === 0 ? <p className="text-sm text-slate-600">{t('gapa.audit.empty')}</p> : (
                                <ol className="space-y-2">
                                    {audit.data.trail.map((r) => (
                                        <li key={r.id} className="border-l-2 border-slate-300 pl-3 text-sm">
                                            <span className="font-medium">{t(`gapa.audit.action.${r.action}`) === `gapa.audit.action.${r.action}` ? r.action.replace(/_/g, ' ') : t(`gapa.audit.action.${r.action}`)}</span>
                                            <span className="block text-slate-500">{r.actor ?? t('gapa.audit.system')} · {formatDateTime(r.created_at)}</span>
                                            {r.payload && Object.keys(r.payload).length > 0 && (
                                                <span className="block break-all text-slate-600">{Object.entries(r.payload).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(' · ')}</span>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </>
                    )}
                </section>
            )}
        </div>
    );
}

// ---------------------------------------------------------------- Budget detail + group roll-up (row 13 / 21)

interface BudgetLine { id: number; account_code: string; department: string | null; month: number; amount: number }
interface BudgetDetail { id: number; name: string; year: number; status: string; lines: BudgetLine[] }

export function GapaBudgetDetail({ id, onClose }: { id: number; onClose: () => void }) {
    const t = useT();
    const b = useApi<BudgetDetail>(`accounting/core/budgets/${id}`, [id]);
    const grid = new Map<string, { account: string; department: string; months: number[] }>();
    (b.data?.lines ?? []).forEach((l) => {
        const k = `${l.account_code}|${l.department ?? ''}`;
        if (!grid.has(k)) grid.set(k, { account: l.account_code, department: l.department ?? '', months: Array(12).fill(0) });
        grid.get(k)!.months[l.month - 1] += Number(l.amount);
    });
    const rows = [...grid.values()];
    const months = Array.from({ length: 12 }, (_, i) => i + 1);
    const colTotals = months.map((m) => rows.reduce((s, r) => s + r.months[m - 1], 0));

    return (
        <section className="card mb-4 p-4" aria-labelledby="gapa-budget-detail">
            <div className="mb-3 flex flex-wrap items-start gap-2">
                <div className="mr-auto">
                    <h2 id="gapa-budget-detail" className="text-base font-semibold">{b.data ? t('gapa.budget.detail_title', { name: b.data.name, year: b.data.year }) : t('gapa.budget.detail')}</h2>
                    {b.data && <p className="text-sm text-slate-600">{t(`acc2.status.${b.data.status}`)} · {t('gapa.budget.lines', { n: b.data.lines.length })}</p>}
                </div>
                {rows.length > 0 && b.data && (
                    <CsvButton filename={`budget-${b.data.name}-${b.data.year}.csv`} header={['account', 'department', ...months.map(String), 'total']}
                        rows={rows.map((r) => [r.account, r.department, ...r.months, r.months.reduce((s, n) => s + n, 0)])} />
                )}
                <button type="button" className="btn-secondary" onClick={onClose}>{t('gapa.close')}</button>
            </div>
            <Banner error={b.error?.message} />
            <Loading on={b.loading} />
            {b.data && rows.length === 0 && <p className="text-sm text-slate-600">{t('gapa.budget.no_lines')}</p>}
            {rows.length > 0 && (
                <div className="table-wrap sticky-first">
                    <table className="table-auto w-full text-sm">
                        <caption className="sr-only">{t('gapa.budget.detail')}</caption>
                        <thead><tr className="text-slate-500"><th scope="col" className="p-2 text-left">{t('gapa.col.account')}</th><th scope="col" className="p-2 text-left">{t('gapa.budget.dept')}</th>{months.map((m) => <th key={m} scope="col" className="p-2 text-right">{m}</th>)}<th scope="col" className="p-2 text-right">{t('gapa.total')}</th></tr></thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={`${r.account}|${r.department}`} className="border-t border-slate-100">
                                    <td className="p-2 font-mono">{r.account}</td><td className="p-2">{r.department || '—'}</td>
                                    {r.months.map((n, i) => <td key={i} className="num p-2">{n ? formatNumber(n) : ''}</td>)}
                                    <td className="num p-2 font-semibold">{formatNumber(r.months.reduce((s, n) => s + n, 0))}</td>
                                </tr>
                            ))}
                            <tr className="border-t border-slate-300 font-semibold">
                                <td className="p-2">{t('gapa.total')}</td><td />
                                {colTotals.map((n, i) => <td key={i} className="num p-2">{formatNumber(n)}</td>)}
                                <td className="num p-2">{formatNumber(colTotals.reduce((s, n) => s + n, 0))}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

interface GT { budget: number; actual: number; forecast: number; budget_full: number }
interface Group { year: number; hotels: { hotel: { id: number; code: string; name: string }; budget: string; totals: { revenue: GT; expense: GT; result: GT } }[] }

/** Group roll-up of the approved budget of each hotel (needs accounting.consolidate and an all-hotels user). */
export function GapaBudgetGroup() {
    const t = useT();
    const [year, setYear] = useState(new Date().getFullYear());
    const [name, setName] = useState('');
    const g = useApi<Group>(`accounting/core/budgets/group?year=${year}${name ? `&name=${encodeURIComponent(name)}` : ''}`, [year, name]);
    const hotels = g.data?.hotels ?? [];
    const sum = (k: 'revenue' | 'expense' | 'result', f: keyof GT) => hotels.reduce((s, h) => s + Number(h.totals[k][f]), 0);
    // The roll-up is for group users (all hotels); a single-hotel user gets 403 and the panel stays hidden.
    if ((g.error as { status?: number } | null)?.status === 403) return null;

    return (
        <section className="card mb-4 p-4" aria-labelledby="gapa-budget-group">
            <div className="mb-3 grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:items-end [&>*]:min-w-0">
                <div className="sm:mr-auto">
                    <h2 id="gapa-budget-group" className="text-base font-semibold">{t('gapa.budget.group_title')}</h2>
                    <p className="text-sm text-slate-600">{t('gapa.budget.group_hint')}</p>
                </div>
                <Field label={t('acc2.budgets.year')}><input type="number" className="input w-full sm:w-28" value={year} onChange={(e) => setYear(Number(e.target.value))} /></Field>
                <Field label={t('gapa.budget.version')}><input className="input w-full sm:w-44" value={name} onChange={(e) => setName(e.target.value)} placeholder={t('gapa.budget.latest')} /></Field>
                {hotels.length > 0 && (
                    <CsvButton filename={`budget-group-${year}.csv`} header={['hotel', 'budget', 'revenue_budget', 'revenue_actual', 'expense_budget', 'expense_actual', 'result_budget', 'result_actual', 'result_forecast']}
                        rows={hotels.map((h) => [h.hotel.code, h.budget, h.totals.revenue.budget, h.totals.revenue.actual, h.totals.expense.budget, h.totals.expense.actual, h.totals.result.budget, h.totals.result.actual, h.totals.result.forecast])} />
                )}
            </div>
            <Banner error={g.error?.message} />
            <Loading on={g.loading} />
            {g.data && hotels.length === 0 && <EmptyState title={t('gapa.budget.group_empty_title')} description={t('gapa.budget.group_empty_text')} />}
            {hotels.length > 0 && (
                <RTable caption={t('gapa.budget.group_title')}
                    cols={[{ h: t('gapa.hotel') }, { h: t('gapa.budget.version') }, { h: t('gapa.budget.rev_budget'), r: true }, { h: t('gapa.budget.rev_actual'), r: true }, { h: t('gapa.budget.exp_budget'), r: true }, { h: t('gapa.budget.exp_actual'), r: true }, { h: t('gapa.budget.res_budget'), r: true }, { h: t('gapa.budget.res_actual'), r: true }, { h: t('gapa.budget.res_forecast'), r: true }]}
                    rows={[
                        ...hotels.map((h) => ({
                            key: h.hotel.id, cells: [`${h.hotel.code} · ${h.hotel.name}`, h.budget, M(h.totals.revenue.budget), M(h.totals.revenue.actual), M(h.totals.expense.budget), M(h.totals.expense.actual), M(h.totals.result.budget),
                                <span className={h.totals.result.actual < h.totals.result.budget ? 'text-red-700' : 'text-emerald-700'}>{M(h.totals.result.actual)}</span>, M(h.totals.result.forecast)],
                        })),
                        { key: 'total', cells: [<b>{t('gapa.group_total')}</b>, '', <b>{M(sum('revenue', 'budget'))}</b>, <b>{M(sum('revenue', 'actual'))}</b>, <b>{M(sum('expense', 'budget'))}</b>, <b>{M(sum('expense', 'actual'))}</b>, <b>{M(sum('result', 'budget'))}</b>, <b>{M(sum('result', 'actual'))}</b>, <b>{M(sum('result', 'forecast'))}</b>] },
                    ]} />
            )}
        </section>
    );
}

// ---------------------------------------------------------------- Bank statement import history (row 14 / 22)

interface Imp { id: number; filename: string | null; rows: number; skipped: number; from: string | null; to: string | null; opening_balance: number | null; closing_balance: number | null; created_at: string }

export function GapaImportHistory({ bankId }: { bankId: number | null }) {
    const t = useT();
    const list = useApi<Imp[]>(bankId ? `accounting/bank-accounts/${bankId}/imports` : null, [bankId]);
    const rows = list.data ?? [];
    return (
        <section className="card mb-4 p-4" aria-labelledby="gapa-imports">
            <div className="mb-3 flex flex-wrap items-end gap-2">
                <h2 id="gapa-imports" className="mr-auto text-base font-semibold">{t('gapa.imports.title')}</h2>
                {rows.length > 0 && (
                    <CsvButton filename={`bank-imports-${bankId}.csv`} header={['date', 'file', 'rows', 'skipped', 'from', 'to', 'opening', 'closing']}
                        rows={rows.map((r) => [r.created_at, r.filename, r.rows, r.skipped, r.from, r.to, r.opening_balance, r.closing_balance])} />
                )}
            </div>
            <Banner error={list.error?.message} />
            <Loading on={list.loading} />
            {list.data && rows.length === 0 && <EmptyState title={t('gapa.imports.empty_title')} description={t('gapa.imports.empty_text')} />}
            {rows.length > 0 && (
                <RTable caption={t('gapa.imports.title')}
                    cols={[{ h: t('gapa.imports.when') }, { h: t('gapa.imports.file') }, { h: t('gapa.imports.period') }, { h: t('gapa.imports.rows'), r: true }, { h: t('gapa.imports.skipped'), r: true }, { h: t('gapa.imports.opening'), r: true }, { h: t('gapa.imports.closing'), r: true }]}
                    rows={rows.map((r) => ({
                        key: r.id, cells: [formatDateTime(r.created_at), <span className="break-all">{r.filename ?? '—'}</span>,
                            r.from && r.to ? `${formatDate(r.from)} – ${formatDate(r.to)}` : '—', formatNumber(r.rows), formatNumber(r.skipped),
                            r.opening_balance === null ? '—' : M(r.opening_balance), r.closing_balance === null ? '—' : M(r.closing_balance)],
                    }))} />
            )}
        </section>
    );
}
