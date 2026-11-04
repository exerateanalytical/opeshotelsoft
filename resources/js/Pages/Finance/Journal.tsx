import { useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { api, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Account, Banner, errMsg, Field, JournalEntry, Loading, Page, today } from './shared';
import { useT } from '@/lib/i18n';
import { uploadFile } from './ab3Shared';
import PrintPdfButton from '@/Components/PrintPdfButton';
import { GapaEntryPanel } from './gapaParts';

interface Line { account: string; debit: string; credit: string; description: string; department: string }
const emptyLine = (): Line => ({ account: '', debit: '', credit: '', description: '', department: '' });
const num = (s: string) => (s ? parseInt(s, 10) || 0 : 0);

export default function Journal() {
    const t = useT();
    const [journal, setJournal] = useState('');
    const [account, setAccount] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    const p = new URLSearchParams({ page: String(page) });
    if (journal) p.set('journal', journal);
    if (account) p.set('account', account);
    if (from) p.set('from', from);
    if (to) p.set('to', to);
    const list = useApi<Page<JournalEntry>>(`accounting/entries?${p}`);
    const journals = useApi<{ code: string; name: string }[]>('accounting/journals');
    const accounts = useApi<Account[]>('accounting/accounts');

    return (
        <div>
            <Banner error={error ?? list.error?.message} success={ok} />
            <div className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 sm:flex sm:flex-wrap sm:items-end [&>*]:min-w-0">
                <Field label={t('fin.col.journal')}><select className="select w-full sm:w-44" value={journal} onChange={(e) => { setJournal(e.target.value); setPage(1); }}>
                    <option value="">{t('fin.journal.all_journals')}</option>
                    {(journals.data ?? []).map((j) => <option key={j.code} value={j.code}>{j.code} · {j.name}</option>)}
                </select></Field>
                <Field label={t('fin.col.account')}><input className="input w-full sm:w-32" value={account} onChange={(e) => { setAccount(e.target.value); setPage(1); }} /></Field>
                <Field label={t('fin.from')}><input type="date" className="input w-full" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} /></Field>
                <Field label={t('fin.to')}><input type="date" className="input w-full" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} /></Field>
                <button className="btn-primary sm:col-span-2 sm:ml-auto" onClick={() => setCreating(true)}><Plus className="inline h-4 w-4" /> {t('fin.journal.manual_entry')}</button>
            </div>

            {creating && (
                <EntryForm accounts={accounts.data ?? []} onClose={() => setCreating(false)}
                    onDone={(n) => { setCreating(false); setOk(t('fin.journal.entry_posted', { number: n })); setError(null); list.reload(); }} />
            )}
            {selected && (
                <EntryDetail id={selected} onClose={() => setSelected(null)}
                    onReversed={(n) => { setOk(t('fin.journal.reversal_posted', { number: n })); setError(null); list.reload(); }} />
            )}

            <div className="card overflow-x-auto">
                <Loading on={list.loading} />
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.col.date')}</th><th className="p-2">{t('fin.col.number')}</th><th className="p-2">{t('fin.col.journal')}</th><th className="p-2">{t('fin.col.description')}</th><th className="p-2 text-right">{t('fin.col.total')}</th><th className="p-2">{t('fin.col.status')}</th></tr></thead>
                    <tbody>
                        {list.data?.data.map((e) => (
                            <tr key={e.id} className="cursor-pointer border-t border-slate-100 hover:bg-slate-50" onClick={() => setSelected(e.id)}>
                                <td className="p-2 whitespace-nowrap">{formatDate(e.date)}</td>
                                <td className="p-2 font-mono">{e.number}</td>
                                <td className="p-2">{e.journal_code}</td>
                                <td className="p-2">{e.description}</td>
                                <td className="p-2 text-right whitespace-nowrap">{formatCurrency(e.total)}</td>
                                <td className="p-2 text-xs">{e.reversed ? <span className="text-amber-700">{t('fin.journal.reversed')}</span> : e.reverses_entry_id ? t('fin.journal.reversal') : e.status}</td>
                            </tr>
                        ))}
                        {list.data && list.data.data.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-slate-500">{t('fin.journal.empty')}</td></tr>}
                    </tbody>
                </table>
            </div>
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center justify-end gap-2 text-sm">
                    <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                    <span>{list.data.current_page} / {list.data.last_page}</span>
                    <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                </div>
            )}
        </div>
    );
}

function EntryDetail({ id, onClose, onReversed }: { id: number; onClose: () => void; onReversed: (n: string) => void }) {
    const t = useT();
    const entry = useApi<JournalEntry>(`accounting/entries/${id}`);
    const [reason, setReason] = useState('');
    const [date, setDate] = useState('');
    const [showReverse, setShowReverse] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function reverse(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true); setError(null);
        try {
            const r = await api<JournalEntry>(`accounting/entries/${id}/reverse`, { method: 'POST', body: JSON.stringify({ reason, date: date || null }) });
            setShowReverse(false);
            entry.reload();
            onReversed(r.number);
        } catch (err) { setError(errMsg(err)); } finally { setBusy(false); }
    }

    const e = entry.data;
    return (
        <div className="card mb-4 p-4">
            <div className="mb-3 flex items-start justify-between gap-2">
                <div>
                    <h3 className="font-semibold">{e ? `${e.number} · ${e.journal_code}` : t('fin.journal.entry')}</h3>
                    {e && <p className="text-sm text-slate-500">{formatDate(e.date)} — {e.description} {e.source_type && `(${e.source_type})`}</p>}
                </div>
                <button onClick={onClose} className="btn-ghost btn-icon btn-sm" aria-label={t('ui.nav.close')}><X className="h-5 w-5" /></button>
            </div>
            <Banner error={error ?? entry.error?.message} />
            <Loading on={entry.loading} />
            {e && (
                <>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.col.account')}</th><th className="p-2">{t('fin.col.description')}</th><th className="p-2">{t('fin.journal.partner')}</th><th className="p-2">{t('fin.journal.dept')}</th><th className="p-2 text-right">{t('fin.col.debit')}</th><th className="p-2 text-right">{t('fin.col.credit')}</th></tr></thead>
                            <tbody>
                                {e.lines.map((l) => (
                                    <tr key={l.id} className="border-t border-slate-100">
                                        <td className="p-2 font-mono">{l.account_code}</td>
                                        <td className="p-2">{l.description}</td>
                                        <td className="p-2 text-xs">{l.partner_type ? `${l.partner_type} #${l.partner_id}` : ''}</td>
                                        <td className="p-2 text-xs">{l.department}</td>
                                        <td className="p-2 text-right">{l.debit ? formatCurrency(l.debit) : ''}</td>
                                        <td className="p-2 text-right">{l.credit ? formatCurrency(l.credit) : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-3"><PrintPdfButton path={`journal-voucher/${e.id}`} label={t('documents3.print_voucher')} /></div>
                    <GapaEntryPanel entryId={e.id} number={e.number} />
                    {e.reversed ? (
                        <p className="mt-3 text-sm text-amber-700">{t('fin.journal.has_been_reversed')}</p>
                    ) : !showReverse ? (
                        <button className="btn-secondary mt-3" onClick={() => setShowReverse(true)}>{t('fin.journal.reverse')}</button>
                    ) : (
                        <form onSubmit={reverse} className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end [&>*]:min-w-0">
                            <Field label={t('fin.reason')}><input className="input w-full" required value={reason} onChange={(ev) => setReason(ev.target.value)} /></Field>
                            <Field label={t('fin.date_optional')}><input type="date" className="input w-full" value={date} onChange={(ev) => setDate(ev.target.value)} /></Field>
                            <div className="flex gap-2">
                                <button className="btn-primary" disabled={busy}>{t('fin.journal.post_reversal')}</button>
                                <button type="button" className="btn-secondary" onClick={() => setShowReverse(false)}>{t('common.cancel')}</button>
                            </div>
                        </form>
                    )}
                </>
            )}
        </div>
    );
}

function EntryForm({ accounts, onClose, onDone }: { accounts: Account[]; onClose: () => void; onDone: (n: string) => void }) {
    const t = useT();
    const [journal, setJournal] = useState('OD');
    const [date, setDate] = useState(today());
    const [description, setDescription] = useState('');
    const [lines, setLines] = useState<Line[]>([emptyLine(), emptyLine()]);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [files, setFiles] = useState<File[]>([]);

    const debit = lines.reduce((s, l) => s + num(l.debit), 0);
    const credit = lines.reduce((s, l) => s + num(l.credit), 0);
    const bothSides = lines.some((l) => num(l.debit) > 0 && num(l.credit) > 0);
    const valid = debit > 0 && debit === credit && !bothSides && lines.every((l) => l.account && (num(l.debit) > 0 || num(l.credit) > 0));
    const postable = accounts.filter((a) => a.postable && a.active);

    const set = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!valid) return;
        setBusy(true); setError(null);
        try {
            const body = {
                journal, date, description,
                lines: lines.map((l) => ({ account: l.account, debit: num(l.debit), credit: num(l.credit), description: l.description || null, department: l.department || null })),
            };
            let r: JournalEntry;
            if (files.length) {
                // Supporting documents go as multipart; the server reads `lines` as a JSON string.
                const fd = new FormData();
                fd.append('journal', journal); fd.append('date', date); fd.append('description', description);
                fd.append('lines', JSON.stringify(body.lines));
                files.forEach((f) => fd.append('attachments[]', f));
                r = await uploadFile<JournalEntry>('accounting/entries', fd);
            } else {
                r = await api<JournalEntry>('accounting/entries', { method: 'POST', body: JSON.stringify(body) });
            }
            onDone(r.number);
        } catch (err) { setError(errMsg(err)); } finally { setBusy(false); }
    }

    return (
        <form onSubmit={submit} className="card mb-4 p-4">
            <div className="mb-3 flex items-center justify-between"><h3 className="font-semibold">{t('fin.journal.manual_title')}</h3><button type="button" onClick={onClose} className="btn-ghost btn-icon btn-sm" aria-label={t('ui.nav.close')}><X className="h-5 w-5" /></button></div>
            <Banner error={error} />
            <div className="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-[8rem_10rem_1fr] [&>*]:min-w-0">
                <Field label={t('fin.col.journal')}><input className="input w-full" required value={journal} onChange={(e) => setJournal(e.target.value)} /></Field>
                <Field label={t('fin.col.date')}><input type="date" className="input w-full" required value={date} onChange={(e) => setDate(e.target.value)} /></Field>
                <Field label={t('fin.col.description')}><input className="input w-full" required maxLength={250} value={description} onChange={(e) => setDescription(e.target.value)} /></Field>
            </div>
            <datalist id="fin-accounts">{postable.map((a) => <option key={a.code} value={a.code}>{a.name}</option>)}</datalist>
            <div className="overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-1">{t('fin.col.account')}</th><th className="p-1">{t('fin.col.label')}</th><th className="p-1">{t('fin.journal.dept')}</th><th className="p-1">{t('fin.col.debit')}</th><th className="p-1">{t('fin.col.credit')}</th><th /></tr></thead>
                    <tbody>
                        {lines.map((l, i) => (
                            <tr key={i}>
                                <td className="p-1"><input list="fin-accounts" className="input w-28" value={l.account} onChange={(e) => set(i, { account: e.target.value })} /></td>
                                <td className="p-1"><input className="input w-40" value={l.description} onChange={(e) => set(i, { description: e.target.value })} /></td>
                                <td className="p-1"><input className="input w-24" value={l.department} onChange={(e) => set(i, { department: e.target.value })} /></td>
                                <td className="p-1"><input type="number" min={0} className="input w-28 text-right" value={l.debit} onChange={(e) => set(i, { debit: e.target.value, credit: e.target.value ? '' : l.credit })} /></td>
                                <td className="p-1"><input type="number" min={0} className="input w-28 text-right" value={l.credit} onChange={(e) => set(i, { credit: e.target.value, debit: e.target.value ? '' : l.debit })} /></td>
                                <td className="p-1">{lines.length > 2 && <button type="button" onClick={() => setLines(lines.filter((_, j) => j !== i))} className="btn-ghost btn-icon text-red-700" aria-label={t('common.delete')}><Trash2 className="h-4 w-4" /></button>}</td>
                            </tr>
                        ))}
                        <tr className="border-t border-slate-200 font-medium">
                            <td className="p-1" colSpan={3}><button type="button" className="btn-secondary btn-sm" onClick={() => setLines([...lines, emptyLine()])}>{t('fin.journal.add_line')}</button></td>
                            <td className="p-1 text-right">{formatCurrency(debit)}</td>
                            <td className="p-1 text-right">{formatCurrency(credit)}</td>
                            <td />
                        </tr>
                    </tbody>
                </table>
            </div>
            <div className="mt-3">
                <Field label={t('acc4.entry.attachments')}>
                    <input type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.xlsx,.csv,.docx,.txt" className="input w-full" onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 10))} />
                </Field>
                <p className="mt-1 text-sm text-slate-600">{t('acc4.entry.attachments_hint')}</p>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-3">
                <button className="btn-primary" disabled={!valid || busy}>{t('fin.journal.post_entry')}</button>
                {debit !== credit ? (
                    <span className="text-sm text-amber-700">{t('fin.journal.out_of_balance', { amount: formatCurrency(Math.abs(debit - credit)) })}</span>
                ) : bothSides ? (
                    <span className="text-sm text-amber-700">{t('fin.journal.both_sides')}</span>
                ) : debit > 0 ? <span className="text-sm text-emerald-700">{t('fin.journal.balanced')}</span> : null}
            </div>
        </form>
    );
}
