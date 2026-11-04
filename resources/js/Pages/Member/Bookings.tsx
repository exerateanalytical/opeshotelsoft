import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale, useT } from '@/lib/i18n';
import ConfirmSheet from './ConfirmSheet';
import MemberLayout from './MemberLayout';
import { fmtDate, mapi, MemberApiError, money } from './api';

interface Summary { number: string; reference?: string; status: string; arrival: string; departure: string; nights: number; total_amount: number; currency: string; hotel: { name: string; city: string } | null; room_type: string | null; room: string | null; can_cancel: boolean }
interface Detail extends Summary {
    modify: { allowed: boolean; reason: string | null; extend_only: boolean };
    cancel: { allowed: boolean; fee: number; currency: string };
    balance: { due: number; currency: string };
    documents: { invoices: { id: number; number: string; kind: string; total: number }[]; receipts: { id: number; number: string }[] };
}
interface Data { upcoming: Summary[]; past: Summary[] }

function Booking({ s }: { s: Summary }) {
    const t = useT();
    const locale = useLocale();
    const [open, setOpen] = useState(false);
    const [d, setD] = useState<Detail | null>(null);
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [dates, setDates] = useState({ arrival: s.arrival, departure: s.departure });
    const [quote, setQuote] = useState<{ difference: number; new_total: number } | null>(null);

    async function load() {
        setOpen(!open);
        if (!d) setD(await mapi<Detail>('GET', `/account/bookings/${s.reference ?? s.number}`).catch(() => null));
    }
    async function run<T>(fn: () => Promise<T>, done?: (r: T) => void) {
        setBusy(true); setMsg(null);
        try { const r = await fn(); done?.(r); } catch (e) { setMsg({ ok: false, text: e instanceof MemberApiError ? e.message : String(e) }); }
        setBusy(false);
    }
    const modify = (apply: boolean) => run(() => mapi<{ difference: number; new_total: number }>('POST', `/account/bookings/${s.reference ?? s.number}/modify`, { ...dates, apply }), (r) => {
        if (apply) { router.reload(); } else setQuote(r);
    });
    const [askCancel, setAskCancel] = useState(false);
    const cancel = () => run(() => mapi('POST', `/account/bookings/${s.reference ?? s.number}/cancel`), () => router.reload());
    const pay = () => run(() => mapi<{ url: string }>('POST', `/account/bookings/${s.reference ?? s.number}/pay`), (r) => { window.location.href = r.url; });

    return (
        <div className="card">
            <button type="button" onClick={load} aria-expanded={open} className="w-full text-left p-4 flex justify-between gap-3">
                <span>
                    <span className="block font-semibold">{s.hotel?.name} <span className="font-normal text-slate-500">· {s.hotel?.city}</span></span>
                    <span className="block text-sm">{fmtDate(s.arrival, locale)} → {fmtDate(s.departure, locale)} · {t('member.nights', { count: s.nights })}</span>
                    <span className="block text-sm text-slate-500">{s.reference ?? s.number}{s.room_type ? ` · ${s.room_type}` : ''}{s.room ? ` · ${t('member.room')} ${s.room}` : ''}</span>
                </span>
                <span className="text-right shrink-0">
                    <span className={`badge ${s.status === 'cancelled' ? 'badge-red' : s.status === 'checked_in' ? 'badge-green' : 'badge-blue'}`}>{t(`member.status.${s.status}`)}</span>
                    <span className="block text-sm mt-1">{money(s.total_amount, s.currency)}</span>
                </span>
            </button>
            {open && d && (
                <div className="border-t border-slate-200 p-4 space-y-3">
                    {d.balance.due > 0 && s.status !== 'cancelled' && <button type="button" className="btn-primary" disabled={busy} onClick={pay}>{t('member.bookings.pay', { amount: money(d.balance.due, d.balance.currency) })}</button>}

                    {d.modify.allowed ? (
                        <div className="space-y-2">
                            <p className="font-semibold text-sm">{t('member.bookings.modify')}</p>
                            {d.modify.extend_only && <p className="text-sm text-slate-600">{t('member.bookings.extend_only')}</p>}
                            <div className="grid grid-cols-2 gap-2">
                                <label className="text-sm">{t('member.arrival')}<input type="date" className="input" value={dates.arrival} onChange={(e) => { setDates({ ...dates, arrival: e.target.value }); setQuote(null); }} /></label>
                                <label className="text-sm">{t('member.departure')}<input type="date" className="input" value={dates.departure} onChange={(e) => { setDates({ ...dates, departure: e.target.value }); setQuote(null); }} /></label>
                            </div>
                            <div className="flex gap-2 items-center flex-wrap">
                                <button type="button" className="btn-secondary" disabled={busy} onClick={() => modify(false)}>{t('member.bookings.quote')}</button>
                                {quote && <button type="button" className="btn-primary" disabled={busy} onClick={() => modify(true)}>{t('member.bookings.apply', { diff: `${quote.difference > 0 ? '+' : ''}${money(quote.difference, s.currency)}` })}</button>}
                            </div>
                        </div>
                    ) : s.can_cancel && <p className="text-sm text-slate-600">{t(`member.bookings.modify_closed.${d.modify.reason ?? 'status'}`)}</p>}

                    {d.cancel.allowed && <button type="button" className="btn-danger" disabled={busy} onClick={() => setAskCancel(true)}>{t('member.bookings.cancel')}</button>}

                    {(d.documents.invoices.length > 0 || d.documents.receipts.length > 0) && (
                        <div className="flex flex-wrap gap-2">
                            {d.documents.invoices.map((i) => <a key={i.id} className="btn-secondary" target="_blank" rel="noreferrer" href={`/account/invoices/${i.id}/pdf`}>{t(i.kind === 'credit_note' ? 'member.bookings.credit_note' : 'member.bookings.invoice')} {i.number}</a>)}
                            {d.documents.receipts.map((r) => <a key={r.id} className="btn-secondary" target="_blank" rel="noreferrer" href={`/account/receipts/${r.id}/pdf?lang=${locale}`}>{t('member.bookings.receipt')} {r.number}</a>)}
                        </div>
                    )}
                    {askCancel && <ConfirmSheet danger message={t('member.bookings.cancel_confirm', { fee: money(d?.cancel.fee ?? 0, s.currency) })} onCancel={() => setAskCancel(false)} onConfirm={() => { setAskCancel(false); cancel(); }} />}
                    {msg && <p role="alert" className={`text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
                </div>
            )}
        </div>
    );
}

export default function Bookings({ data }: { data: Data }) {
    const t = useT();
    return (
        <MemberLayout title={t('member.bookings.title')}>
            <h2 className="font-semibold">{t('member.bookings.upcoming')}</h2>
            {data.upcoming.length === 0 && <p className="text-slate-600">{t('member.bookings.none')}</p>}
            {data.upcoming.map((s) => <Booking key={s.reference ?? s.number} s={s} />)}
            {data.past.length > 0 && <h2 className="font-semibold pt-2">{t('member.bookings.past')}</h2>}
            {data.past.map((s) => <Booking key={s.reference ?? s.number} s={s} />)}
        </MemberLayout>
    );
}
