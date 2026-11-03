import AppLayout from '@/Layouts/AppLayout';
import CashierBar from '@/Components/CashierBar';
import { Head, Link } from '@inertiajs/react';
import { Loader2, Plus, Search, Undo2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Line {
    id: number;
    business_date: string;
    type: string;
    code: string;
    description: string;
    amount: number;
    tax_amount: number;
    reversed: boolean;
}
interface Folio {
    id: number;
    number: string;
    kind: string;
    name: string;
    status: string;
    balance: number;
    reservation_id: number | null;
    company_id: number | null;
    guest_id: number | null;
    created_at?: string;
    lines?: Line[];
}
interface Page<T> { data: T[] }
interface Code { code: string; name: string }
interface Company { id: number; name: string }

const KINDS = ['guest', 'master', 'house', 'company', 'walk_in', 'city_ledger'];

export default function Folios() {
    const t = useT();
    const lbl = (p: string, v: string) => (t(`${p}.${v}`) === `${p}.${v}` ? v.replace('_', ' ') : t(`${p}.${v}`));
    const [kind, setKind] = useState('');
    const [status, setStatus] = useState('open');
    const [company, setCompany] = useState('');
    const [q, setQ] = useState('');
    const [selected, setSelected] = useState<number | null>(null);
    const [opening, setOpening] = useState(false);
    const [posted, setPosted] = useState(0);

    const params = new URLSearchParams();
    if (kind) params.set('kind', kind);
    if (status) params.set('status', status);
    if (company) params.set('company_id', company);
    if (q.trim()) params.set('q', q.trim());
    const list = useApi<Page<Folio>>(`folios?${params.toString()}`, [posted]);
    const companies = useApi<Page<Company>>('companies?per_page=200');

    const rows = useMemo(() => {
        const term = q.trim().toLowerCase();
        return (list.data?.data ?? []).filter((f) =>
            (!kind || f.kind === kind) && (!status || f.status === status) && (!company || String(f.company_id) === company) &&
            (!term || f.number.toLowerCase().includes(term) || (f.name ?? '').toLowerCase().includes(term)));
    }, [list.data, kind, status, company, q]);

    const companyName = (id: number | null) => companies.data?.data.find((c) => c.id === id)?.name;

    return (
        <AppLayout
            title={t('billing.folios.title')}
            subtitle={t('billing.folios.subtitle')}
            actions={<button className="btn-primary" onClick={() => setOpening(!opening)}><Plus className="w-4 h-4" /> {t('billing.folios.open_folio')}</button>}
        >
            <Head title={t('billing.folios.title')} />
            <CashierBar onChange={() => setPosted((n) => n + 1)} refresh={posted} />

            {opening && <OpenFolioForm companies={companies.data?.data ?? []} onDone={(id) => { setOpening(false); setPosted((n) => n + 1); if (id) setSelected(id); }} />}

            <div className="card px-4 py-3 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <label className="relative">
                    <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input className="input pl-9" placeholder={t('billing.folios.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                </label>
                <select className="input" value={kind} onChange={(e) => setKind(e.target.value)}>
                    <option value="">{t('billing.folios.all_kinds')}</option>
                    {KINDS.map((k) => <option key={k} value={k}>{lbl('billing.kind', k)}</option>)}
                </select>
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('billing.folios.all_statuses')}</option>
                    <option value="open">{t('billing.status.open')}</option>
                    <option value="closed">{t('billing.status.closed')}</option>
                </select>
                <select className="input" value={company} onChange={(e) => setCompany(e.target.value)}>
                    <option value="">{t('billing.folios.all_companies')}</option>
                    {companies.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </div>

            {list.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{list.error.message}</div>}

            <div className={cn('grid gap-6', selected && 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]')}>
                <div className="card overflow-x-auto self-start">
                    <table className="table-auto w-full">
                        <thead><tr><th>{t('billing.folios.number')}</th><th>{t('billing.folios.kind')}</th><th>{t('billing.folios.name')}</th><th>{t('billing.folios.status')}</th><th className="text-right">{t('billing.folios.balance')}</th></tr></thead>
                        <tbody>
                            {list.loading && !list.data && <tr><td colSpan={5} className="py-10"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-400" /></td></tr>}
                            {list.data && rows.length === 0 && <tr><td colSpan={5} className="text-center py-10 text-slate-400 text-sm">{t('billing.folios.no_match')}</td></tr>}
                            {rows.map((f) => (
                                <tr key={f.id} onClick={() => setSelected(f.id)} className={cn('cursor-pointer hover:bg-slate-50', selected === f.id && 'bg-sky-50')}>
                                    <td className="font-mono text-sm">{f.number}</td>
                                    <td className="text-xs">{lbl('billing.kind', f.kind)}</td>
                                    <td className="text-sm">{f.name}{f.company_id && companyName(f.company_id) && <span className="block text-xs text-slate-400">{companyName(f.company_id)}</span>}</td>
                                    <td><span className={cn('text-xs px-2 py-0.5 rounded', f.status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>{lbl('billing.status', f.status)}</span></td>
                                    <td className={cn('text-right text-sm', f.balance > 0 && 'text-red-600')}>{formatCurrency(f.balance)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {selected && (
                    <FolioPanel key={selected} id={selected} refresh={posted} companies={companies.data?.data ?? []} openFolios={(list.data?.data ?? []).filter((f) => f.status === 'open' && f.id !== selected)}
                        onClose={() => setSelected(null)} onChange={() => setPosted((n) => n + 1)} />
                )}
            </div>
        </AppLayout>
    );
}

function OpenFolioForm({ companies, onDone }: { companies: Company[]; onDone: (id?: number) => void }) {
    const t = useT();
    const lbl = (p: string, v: string) => (t(`${p}.${v}`) === `${p}.${v}` ? v.replace('_', ' ') : t(`${p}.${v}`));
    const [form, setForm] = useState({ kind: 'house', name: '', company_id: '', reservation_id: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <form className="card px-5 py-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setError(null);
            try {
                const f = await api<Folio>('folios', { method: 'POST', body: JSON.stringify({
                    kind: form.kind, name: form.name,
                    company_id: form.company_id ? Number(form.company_id) : null,
                    reservation_id: form.reservation_id ? Number(form.reservation_id) : null,
                }) });
                onDone(f?.id);
            } catch (err) {
                setError(err instanceof ApiError ? err.message : String(err));
            } finally {
                setBusy(false);
            }
        }}>
            <label className="text-xs text-slate-500">{t('billing.folios.kind')}
                <select className="input mt-1" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                    <option value="house">{lbl('billing.kind', 'house')}</option>
                    <option value="master">{lbl('billing.kind', 'master')}</option>
                    <option value="company">{lbl('billing.kind', 'company')}</option>
                    <option value="walk_in">{lbl('billing.kind', 'walk_in')}</option>
                </select>
            </label>
            <label className="text-xs text-slate-500">{t('billing.folios.name')}
                <input className="input mt-1" required maxLength={150} placeholder={form.kind === 'house' ? t('billing.folios.name_house_ph') : t('billing.folios.name_ph')} value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </label>
            <label className="text-xs text-slate-500">{t('billing.folios.company_optional')}
                <select className="input mt-1" value={form.company_id} onChange={(e) => setForm({ ...form, company_id: e.target.value })}>
                    <option value="">—</option>
                    {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </label>
            <label className="text-xs text-slate-500">{t('billing.folios.reservation_optional')}
                <input className="input mt-1" type="number" min={1} value={form.reservation_id} onChange={(e) => setForm({ ...form, reservation_id: e.target.value })} />
            </label>
            <button className="btn-primary justify-center" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('billing.folios.open')}</button>
            {error && <div className="sm:col-span-2 lg:col-span-5 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{error}</div>}
        </form>
    );
}

function FolioPanel({ id, refresh, companies, openFolios, onClose, onChange }: {
    id: number; refresh: number; companies: Company[]; openFolios: Folio[]; onClose: () => void; onChange: () => void;
}) {
    const t = useT();
    const lbl = (p: string, v: string) => (t(`${p}.${v}`) === `${p}.${v}` ? v.replace('_', ' ') : t(`${p}.${v}`));
    const folio = useApi<Folio>(`folios/${id}`, [refresh]);
    const chargeCodes = useApi<Code[]>('setup/charge-codes');
    const methods = useApi<Code[]>('setup/payment-methods');
    const [charge, setCharge] = useState({ charge_code: '', amount: '', description: '' });
    const [payment, setPayment] = useState({ method: 'CASH', amount: '', reference: '' });
    const [transfer, setTransfer] = useState({ to_folio_id: '', amount: '', description: '' });
    const [ledgerCompany, setLedgerCompany] = useState('');
    const [reversing, setReversing] = useState<{ id: number; reason: string } | null>(null);
    const [tab, setTab] = useState<'charge' | 'payment' | 'transfer' | 'ledger'>('charge');
    const [busy, setBusy] = useState<string | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string; link?: string } | null>(null);

    async function run(key: string, path: string, body: object, ok = t('billing.folios.done')) {
        setBusy(key);
        setMessage(null);
        try {
            const res = await api<{ id?: number }>(path, { method: 'POST', body: JSON.stringify(body) });
            setMessage({ ok: true, text: ok, link: key === 'inv' && res?.id ? `/billing/invoices/${res.id}` : undefined });
            folio.reload();
            onChange();
            return true;
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
            return false;
        } finally {
            setBusy(null);
        }
    }

    const f = folio.data;
    const open = f?.status === 'open';

    return (
        <div className="card overflow-hidden self-start">
            <div className="flex items-center gap-2 flex-wrap px-5 py-4 border-b border-slate-100">
                <h3 className="font-semibold text-slate-800">{f ? `${f.number} · ${f.name}` : t('billing.folios.folio')}</h3>
                {f && <span className="text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">{lbl('billing.kind', f.kind)} · {lbl('billing.status', f.status)}</span>}
                {f?.reservation_id && <Link href={`/reservations/${f.reservation_id}`} className="text-xs text-sky-700 hover:underline">{t('billing.folios.reservation')}</Link>}
                <span className="ml-auto text-sm text-slate-500">{t('billing.folios.balance')}</span>
                {f && <span className={cn('text-lg font-semibold', f.balance > 0 ? 'text-red-600' : 'text-emerald-600')}>{formatCurrency(f.balance)}</span>}
                <button className="text-slate-400 hover:text-slate-700" title={t('billing.folios.close_panel')} onClick={onClose}><X className="w-4 h-4" /></button>
            </div>

            {(message || folio.error) && (
                <div className={cn('mx-5 mt-4 rounded-lg text-sm px-4 py-3', message?.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>
                    {message?.text ?? folio.error?.message}
                    {message?.link && <Link href={message.link} className="ml-2 underline">{t('billing.folios.view_invoice')}</Link>}
                </div>
            )}

            {!f ? <Loader2 className="w-6 h-6 mx-auto my-12 animate-spin text-slate-400" /> : (
                <>
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full">
                            <thead><tr><th>{t('billing.folios.date')}</th><th>{t('billing.folios.code')}</th><th>{t('billing.folios.description')}</th><th className="text-right">{t('billing.folios.tax')}</th><th className="text-right">{t('billing.folios.amount')}</th><th></th></tr></thead>
                            <tbody>
                                {(f.lines ?? []).length === 0 && <tr><td colSpan={6} className="text-center py-8 text-slate-400 text-sm">{t('billing.folios.no_postings')}</td></tr>}
                                {(f.lines ?? []).map((l) => (
                                    <tr key={l.id} className={cn(l.reversed && 'opacity-50 line-through')}>
                                        <td className="text-sm whitespace-nowrap">{formatDate(l.business_date)}</td>
                                        <td className="font-mono text-xs">{l.code}</td>
                                        <td className="text-sm">{l.description}</td>
                                        <td className="text-right text-sm text-slate-500">{l.tax_amount ? formatCurrency(l.tax_amount) : ''}</td>
                                        <td className={cn('text-right text-sm', l.amount < 0 && 'text-emerald-700')}>{formatCurrency(l.amount)}</td>
                                        <td className="text-right">
                                            {open && !l.reversed && l.type !== 'reversal' && (
                                                <button title={t('billing.folios.reverse')} className="text-slate-400 hover:text-red-600" onClick={() => setReversing({ id: l.id, reason: '' })}>
                                                    <Undo2 className="w-4 h-4" />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {reversing && (
                        <form className="mx-5 my-3 flex flex-col sm:flex-row gap-2" onSubmit={async (e) => {
                            e.preventDefault();
                            if (await run(`rev`, `folios/${f.id}/lines/${reversing.id}/reverse`, { reason: reversing.reason }, t('billing.folios.line_reversed'))) setReversing(null);
                        }}>
                            <input className="input" required autoFocus placeholder={t('billing.folios.reversal_reason')} value={reversing.reason} onChange={(e) => setReversing({ ...reversing, reason: e.target.value })} />
                            <button className="btn-primary" disabled={busy === 'rev'}>{t('billing.folios.reverse')}</button>
                            <button type="button" className="btn-secondary" onClick={() => setReversing(null)}>{t('common.cancel')}</button>
                        </form>
                    )}

                    <div className="border-t border-slate-100 px-5 py-4 space-y-3">
                        {open && (
                            <>
                                <div className="flex gap-1 flex-wrap">
                                    {(['charge', 'payment', 'transfer', 'ledger'] as const).map((tb) => (
                                        <button key={tb} type="button" onClick={() => setTab(tb)}
                                            className={cn('text-xs px-3 py-1.5 rounded', tab === tb ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600')}>
                                            {t(`billing.folios.tab.${tb}`)}
                                        </button>
                                    ))}
                                </div>

                                {tab === 'charge' && (
                                    <form className="space-y-3" onSubmit={async (e) => {
                                        e.preventDefault();
                                        if (await run('charge', `folios/${f.id}/charges`, { ...charge, amount: Number(charge.amount) }, t('billing.folios.charge_posted')))
                                            setCharge({ charge_code: '', amount: '', description: '' });
                                    }}>
                                        <select className="input" required value={charge.charge_code} onChange={(e) => setCharge({ ...charge, charge_code: e.target.value })}>
                                            <option value="" disabled>{t('billing.folios.charge_code')}</option>
                                            {chargeCodes.data?.filter((c) => c.code !== 'ROOM').map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}
                                        </select>
                                        <input className="input" type="number" min={1} required placeholder={t('billing.folios.amount_tax')} value={charge.amount} onChange={(e) => setCharge({ ...charge, amount: e.target.value })} />
                                        <input className="input" placeholder={t('billing.folios.description_optional')} value={charge.description} onChange={(e) => setCharge({ ...charge, description: e.target.value })} />
                                        <button className="btn-secondary w-full justify-center" disabled={busy === 'charge'}>{t('billing.folios.post_charge')}</button>
                                    </form>
                                )}

                                {tab === 'payment' && (
                                    <form className="space-y-3" onSubmit={async (e) => {
                                        e.preventDefault();
                                        if (await run('pay', `folios/${f.id}/payments`, { ...payment, amount: Number(payment.amount), reference: payment.reference || null }, t('billing.folios.payment_recorded')))
                                            setPayment({ method: 'CASH', amount: '', reference: '' });
                                    }}>
                                        <select className="input" value={payment.method} onChange={(e) => setPayment({ ...payment, method: e.target.value })}>
                                            {methods.data?.filter((m) => m.code !== 'CL').map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
                                        </select>
                                        <div className="flex gap-2">
                                            <input className="input" type="number" min={1} required placeholder={t('billing.folios.amount_fcfa')} value={payment.amount} onChange={(e) => setPayment({ ...payment, amount: e.target.value })} />
                                            {f.balance > 0 && <button type="button" className="btn-secondary text-xs whitespace-nowrap" onClick={() => setPayment({ ...payment, amount: String(f.balance) })}>{t('billing.folios.full_balance')}</button>}
                                        </div>
                                        <input className="input" placeholder={t('billing.folios.reference')} value={payment.reference} onChange={(e) => setPayment({ ...payment, reference: e.target.value })} />
                                        <button className="btn-primary w-full justify-center" disabled={busy === 'pay'}>{t('billing.folios.record_payment')}</button>
                                    </form>
                                )}

                                {tab === 'transfer' && (
                                    <form className="space-y-3" onSubmit={async (e) => {
                                        e.preventDefault();
                                        if (await run('xfer', `folios/${f.id}/transfer`, { to_folio_id: Number(transfer.to_folio_id), amount: Number(transfer.amount), description: transfer.description || null }, t('billing.folios.transferred')))
                                            setTransfer({ to_folio_id: '', amount: '', description: '' });
                                    }}>
                                        <select className="input" required value={transfer.to_folio_id} onChange={(e) => setTransfer({ ...transfer, to_folio_id: e.target.value })}>
                                            <option value="" disabled>{t('billing.folios.destination')}</option>
                                            {openFolios.map((o) => <option key={o.id} value={o.id}>{o.number} · {o.name} ({lbl('billing.kind', o.kind)})</option>)}
                                        </select>
                                        <input className="input" type="number" min={1} required placeholder={t('billing.folios.amount_fcfa')} value={transfer.amount} onChange={(e) => setTransfer({ ...transfer, amount: e.target.value })} />
                                        <input className="input" placeholder={t('billing.folios.description_optional')} value={transfer.description} onChange={(e) => setTransfer({ ...transfer, description: e.target.value })} />
                                        <button className="btn-secondary w-full justify-center" disabled={busy === 'xfer'}>{t('billing.folios.transfer')}</button>
                                        <p className="text-xs text-slate-400">{t('billing.folios.transfer_hint')}</p>
                                    </form>
                                )}

                                {tab === 'ledger' && (
                                    <form className="space-y-3" onSubmit={async (e) => {
                                        e.preventDefault();
                                        if (confirm(t('billing.folios.ledger_confirm')))
                                            await run('cl', `folios/${f.id}/city-ledger`, { company_id: Number(ledgerCompany || f.company_id) }, t('billing.folios.moved_ledger'));
                                    }}>
                                        <select className="input" required value={ledgerCompany || (f.company_id ? String(f.company_id) : '')} onChange={(e) => setLedgerCompany(e.target.value)}>
                                            <option value="" disabled>{t('billing.folios.company')}</option>
                                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                        </select>
                                        <button className="btn-secondary w-full justify-center" disabled={busy === 'cl'}>{t('billing.folios.move_ledger')}</button>
                                    </form>
                                )}
                            </>
                        )}

                        <div className="flex gap-2 flex-wrap pt-2">
                            {open && (
                                <button className="btn-secondary" disabled={busy === 'close'}
                                    onClick={() => confirm(t('billing.folios.close_confirm')) && run('close', `folios/${f.id}/close`, {}, t('billing.folios.closed'))}>
                                    {t('billing.folios.close')}
                                </button>
                            )}
                            <button className="btn-primary" disabled={busy === 'inv'} onClick={() => run('inv', `folios/${f.id}/invoice`, {}, t('billing.folios.invoice_issued'))}>
                                {busy === 'inv' && <Loader2 className="w-4 h-4 animate-spin" />} {t('billing.folios.issue_invoice')}
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
