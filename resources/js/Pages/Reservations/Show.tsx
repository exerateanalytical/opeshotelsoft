import AppLayout from '@/Layouts/AppLayout';
import CashierBar from '@/Components/CashierBar';
import EditStay from './Edit';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Loader2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { CheckOutPanel, RegistrationCardPanel, RoomMovePanel, RoutingPanel } from '@/Pages/FrontDesk/Partials/StayPanels';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Reservation {
    id: number;
    number: string;
    room_type_id: number;
    status: string;
    arrival: string;
    departure: string;
    adults: number;
    total_amount: number;
    company_id: number | null;
    guest: { first_name: string; last_name: string; vip: boolean; phone: string | null; email: string | null };
    room: { number: string } | null;
    room_type: { name: string };
    rate_plan: { code: string };
}

interface Line {
    id: number;
    business_date: string;
    type: 'charge' | 'payment' | 'refund' | 'reversal' | 'transfer';
    code: string;
    description: string;
    amount: number;
    tax_amount: number;
    reversed: boolean;
}

interface Folio { id: number; number: string; balance: number; status: string; lines: Line[] }
interface Code { code: string; name: string; active?: boolean }

export default function ReservationShow({ id }: { id: string }) {
    const t = useT();
    const res = useApi<Reservation>(`reservations/${id}`);
    const folio = useApi<Folio>(`reservations/${id}/folio`);
    const chargeCodes = useApi<Code[]>('setup/charge-codes');
    const methods = useApi<Code[]>('setup/payment-methods');
    const [charge, setCharge] = useState({ charge_code: '', amount: '', description: '' });
    const [payment, setPayment] = useState({ method: 'CASH', amount: '', reference: '' });
    const [busy, setBusy] = useState<string | null>(null);
    const [posted, setPosted] = useState(0);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [reversing, setReversing] = useState<{ id: number; reason: string } | null>(null);
    const notify = (ok: boolean, text: string) => setMessage({ ok, text });
    const refreshAll = () => (folio.reload(), res.reload(), setPosted((n) => n + 1));

    async function run(key: string, path: string, body: object, ok = t('res.show.done')) {
        setBusy(key);
        setMessage(null);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            setMessage({ ok: true, text: ok });
            folio.reload();
            res.reload();
            setPosted((n) => n + 1);
            return true;
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
            return false;
        } finally {
            setBusy(null);
        }
    }

    const r = res.data;
    const f = folio.data;
    const inHouse = r?.status === 'checked_in';

    return (
        <AppLayout
            title={r ? `${r.guest.first_name} ${r.guest.last_name}` : t('res.show.title')}
            subtitle={r ? `${r.number} · ${r.room ? t('res.show.room_n', { number: r.room.number }) : r.room_type.name} · ${formatDate(r.arrival)} → ${formatDate(r.departure)}` : ''}
            actions={
                <div className="flex gap-2">
                    <Link href="/reservations" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('res.back')}</Link>
                    {f && f.status !== 'open' && (
                        <button className="btn-secondary" disabled={busy === 'inv'} onClick={() => run('inv', `folios/${f.id}/invoice`, {}, t('res.show.invoice_issued'))}>
                            {t('res.show.issue_invoice')}
                        </button>
                    )}
                </div>
            }
        >
            <Head title={r?.number ?? t('res.show.title')} />
            <CashierBar onChange={folio.reload} refresh={posted} />
            {r && ['tentative', 'confirmed', 'checked_in'].includes(r.status) && (
                <div className="mb-6"><EditStay reservation={r} onSaved={() => (res.reload(), folio.reload())} /></div>
            )}

            {(message || res.error || folio.error) && (
                <div className={cn('mb-4 rounded-lg text-sm px-4 py-3', message?.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>
                    {message?.text ?? res.error?.message ?? folio.error?.message}
                </div>
            )}

            {!r || !f ? <Loader2 className="w-6 h-6 mx-auto mt-16 animate-spin text-slate-400" /> : (
                <div className="grid lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 card overflow-x-auto">
                        <div className="flex items-center px-5 py-4 border-b border-slate-100">
                            <h3 className="font-semibold text-slate-800">{t('res.show.folio', { number: f.number })}</h3>
                            <span className="ml-3 text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">{t(`res.folio_status.${f.status}`)}</span>
                            <span className="ml-auto text-sm text-slate-500">{t('res.show.balance')}</span>
                            <span className={cn('ml-2 text-lg font-semibold', f.balance > 0 ? 'text-red-600' : 'text-emerald-600')}>{formatCurrency(f.balance)}</span>
                        </div>
                        <table className="table-auto w-full">
                            <thead><tr><th>{t('res.show.date')}</th><th>{t('res.show.code')}</th><th>{t('res.show.description')}</th><th className="text-right">{t('res.show.tax')}</th><th className="text-right">{t('res.amount')}</th><th></th></tr></thead>
                            <tbody>
                                {f.lines.length === 0 && <tr><td colSpan={6} className="text-center py-10 text-slate-400 text-sm">{t('res.show.no_postings')}</td></tr>}
                                {f.lines.map((l) => (
                                    <tr key={l.id} className={cn(l.reversed && 'opacity-50 line-through')}>
                                        <td className="text-sm">{formatDate(l.business_date)}</td>
                                        <td className="font-mono text-xs">{l.code}</td>
                                        <td className="text-sm">{l.description}</td>
                                        <td className="text-right text-sm text-slate-500">{l.tax_amount ? formatCurrency(l.tax_amount) : ''}</td>
                                        <td className={cn('text-right text-sm', l.amount < 0 && 'text-emerald-700')}>{formatCurrency(l.amount)}</td>
                                        <td className="text-right">
                                            {f.status === 'open' && !l.reversed && l.type !== 'reversal' && (reversing?.id === l.id ? (
                                                <form className="flex gap-1 justify-end" onSubmit={async (e) => {
                                                    e.preventDefault();
                                                    if (await run(`rev-${l.id}`, `folios/${f.id}/lines/${l.id}/reverse`, { reason: reversing.reason }, t('res.show.line_reversed'))) setReversing(null);
                                                }}>
                                                    <input className="input w-36" required autoFocus placeholder={t('res.reason')} value={reversing.reason}
                                                        onChange={(e) => setReversing({ id: l.id, reason: e.target.value })} />
                                                    <button className="btn-secondary" disabled={busy === `rev-${l.id}`}>{t('res.show.reverse')}</button>
                                                    <button type="button" className="btn-secondary" onClick={() => setReversing(null)}>{t('common.cancel')}</button>
                                                </form>
                                            ) : (
                                                <button title={t('res.show.reverse')} className="text-slate-400 hover:text-red-600" onClick={() => setReversing({ id: l.id, reason: '' })}>
                                                    <Undo2 className="w-4 h-4" />
                                                </button>
                                            ))}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="space-y-6">
                    {inHouse && <CheckOutPanel id={id} notify={notify} onDone={refreshAll} />}
                    {f.status === 'open' && (
                        <div className="space-y-6">
                            <form className="card px-5 py-4 space-y-3" onSubmit={async (e) => {
                                e.preventDefault();
                                if (await run('charge', `folios/${f.id}/charges`, { ...charge, amount: Number(charge.amount) }, t('res.show.charge_posted')))
                                    setCharge({ charge_code: '', amount: '', description: '' });
                            }}>
                                <h3 className="font-semibold text-slate-800">{t('res.show.post_charge_title')}</h3>
                                <select className="input" required value={charge.charge_code} onChange={(e) => setCharge({ ...charge, charge_code: e.target.value })}>
                                    <option value="" disabled>{t('res.show.charge_code')}</option>
                                    {chargeCodes.data?.filter((c) => c.code !== 'ROOM').map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}
                                </select>
                                <input className="input" type="number" min={1} required placeholder={t('res.show.amount_ttc')} value={charge.amount}
                                    onChange={(e) => setCharge({ ...charge, amount: e.target.value })} />
                                <input className="input" placeholder={t('res.show.description_opt')} value={charge.description}
                                    onChange={(e) => setCharge({ ...charge, description: e.target.value })} />
                                <button className="btn-secondary w-full justify-center" disabled={busy === 'charge'}>{t('res.show.post_charge')}</button>
                            </form>

                            <form className="card px-5 py-4 space-y-3" onSubmit={async (e) => {
                                e.preventDefault();
                                if (await run('pay', `folios/${f.id}/payments`, { ...payment, amount: Number(payment.amount) }, t('res.show.payment_recorded')))
                                    setPayment({ method: 'CASH', amount: '', reference: '' });
                            }}>
                                <h3 className="font-semibold text-slate-800">{t('res.show.take_payment')}</h3>
                                <select className="input" value={payment.method} onChange={(e) => setPayment({ ...payment, method: e.target.value })}>
                                    {methods.data?.filter((m) => m.code !== 'CL').map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
                                </select>
                                <div className="flex gap-2">
                                    <input className="input" type="number" min={1} required placeholder={t('res.show.amount_fcfa')} value={payment.amount}
                                        onChange={(e) => setPayment({ ...payment, amount: e.target.value })} />
                                    {f.balance > 0 && (
                                        <button type="button" className="btn-secondary text-xs whitespace-nowrap"
                                            onClick={() => setPayment({ ...payment, amount: String(f.balance) })}>{t('res.show.full_balance')}</button>
                                    )}
                                </div>
                                <input className="input" placeholder={t('res.show.reference')} value={payment.reference}
                                    onChange={(e) => setPayment({ ...payment, reference: e.target.value })} />
                                <button className="btn-primary w-full justify-center" disabled={busy === 'pay'}>{t('res.show.record_payment')}</button>
                            </form>
                        </div>
                    )}
                    {inHouse && <RoomMovePanel id={id} notify={notify} onDone={refreshAll} />}
                    {inHouse && <RoutingPanel id={id} companyId={r.company_id} chargeCodes={chargeCodes.data ?? []} notify={notify} />}
                    {['checked_in', 'checked_out'].includes(r.status) && <RegistrationCardPanel id={id} editable={inHouse} notify={notify} />}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
