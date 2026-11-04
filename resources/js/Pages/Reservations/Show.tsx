import AppLayout from '@/Layouts/AppLayout';
import CashierBar from '@/Components/CashierBar';
import EditStay from './Edit';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Undo2 } from 'lucide-react';
import { useState } from 'react';
import QueryStatus from '@/Components/ui/QueryStatus';
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
                <div className="btn-row">
                    <Link href="/reservations" className="btn-secondary"><ArrowLeft className="w-4 h-4" aria-hidden="true" /> {t('res.back')}</Link>
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

            {(message || (r && res.error) || (f && folio.error)) && (
                <div role="status" className={cn('mb-4 rounded-lg text-sm px-4 py-3', message?.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>
                    {message?.text ?? res.error?.message ?? folio.error?.message}
                </div>
            )}

            {!r ? <QueryStatus query={res} variant="detail" /> : !f ? <QueryStatus query={folio} variant="detail" /> : (
                <div className="grid lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 card overflow-hidden">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-4 border-b border-slate-200">
                            <h3 className="font-semibold text-slate-800">{t('res.show.folio', { number: f.number })}</h3>
                            <span className="text-xs px-2 py-0.5 rounded border border-slate-300 bg-slate-100 text-slate-700">{t(`res.folio_status.${f.status}`)}</span>
                            <span className="ml-auto text-sm text-slate-500">{t('res.show.balance')}</span>
                            <span className={cn('ml-2 text-lg font-semibold', f.balance > 0 ? 'text-red-700' : 'text-emerald-700')}>{formatCurrency(f.balance)}</span>
                        </div>
                        <div className="table-wrap"><table className="table-auto">
                            <thead><tr><th>{t('res.show.date')}</th><th>{t('res.show.code')}</th><th>{t('res.show.description')}</th><th className="text-right">{t('res.show.tax')}</th><th className="text-right">{t('res.amount')}</th><th></th></tr></thead>
                            <tbody>
                                {f.lines.length === 0 && <tr><td colSpan={6} className="text-center py-10 text-slate-500 text-sm">{t('res.show.no_postings')}</td></tr>}
                                {f.lines.map((l) => (
                                    <tr key={l.id} className={cn(l.reversed && 'text-slate-600 line-through')}>
                                        <td className="text-sm">{formatDate(l.business_date)}</td>
                                        <td className="font-mono text-xs">{l.code}</td>
                                        <td className="text-sm">{l.description}</td>
                                        <td className="text-right text-sm text-slate-500">{l.tax_amount ? formatCurrency(l.tax_amount) : ''}</td>
                                        <td className={cn('text-right text-sm', l.amount < 0 && 'text-emerald-700')}>{formatCurrency(l.amount)}</td>
                                        <td className="text-right">
                                            {f.status === 'open' && !l.reversed && l.type !== 'reversal' && (reversing?.id === l.id ? (
                                                <form className="flex flex-wrap gap-2 justify-end" onSubmit={async (e) => {
                                                    e.preventDefault();
                                                    if (await run(`rev-${l.id}`, `folios/${f.id}/lines/${l.id}/reverse`, { reason: reversing.reason }, t('res.show.line_reversed'))) setReversing(null);
                                                }}>
                                                    <input className="input w-44" required autoFocus aria-label={t('res.reason')} placeholder={t('res.reason')} value={reversing.reason}
                                                        onChange={(e) => setReversing({ id: l.id, reason: e.target.value })} />
                                                    <button className="btn-secondary btn-sm" disabled={busy === `rev-${l.id}`}>{t('res.show.reverse')}</button>
                                                    <button type="button" className="btn-secondary btn-sm" onClick={() => setReversing(null)}>{t('common.cancel')}</button>
                                                </form>
                                            ) : (
                                                <button type="button" title={t('res.show.reverse')} aria-label={t('res.show.reverse')} className="btn-ghost btn-icon text-slate-700" onClick={() => setReversing({ id: l.id, reason: '' })}>
                                                    <Undo2 className="w-4 h-4" aria-hidden="true" />
                                                </button>
                                            ))}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table></div>
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
                                <select className="select" required value={charge.charge_code} onChange={(e) => setCharge({ ...charge, charge_code: e.target.value })} aria-label={t('res.show.charge_code')}>
                                    <option value="" disabled>{t('res.show.charge_code')}</option>
                                    {chargeCodes.data?.filter((c) => c.code !== 'ROOM').map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}
                                </select>
                                <input className="input" type="number" min={1} required aria-label={t('res.show.amount_ttc')} placeholder={t('res.show.amount_ttc')} value={charge.amount}
                                    onChange={(e) => setCharge({ ...charge, amount: e.target.value })} />
                                <input className="input" aria-label={t('res.show.description_opt')} placeholder={t('res.show.description_opt')} value={charge.description}
                                    onChange={(e) => setCharge({ ...charge, description: e.target.value })} />
                                <button className="btn-secondary btn-lg w-full justify-center" disabled={busy === 'charge'}>{t('res.show.post_charge')}</button>
                            </form>

                            <form className="card px-5 py-4 space-y-3" onSubmit={async (e) => {
                                e.preventDefault();
                                if (await run('pay', `folios/${f.id}/payments`, { ...payment, amount: Number(payment.amount) }, t('res.show.payment_recorded')))
                                    setPayment({ method: 'CASH', amount: '', reference: '' });
                            }}>
                                <h3 className="font-semibold text-slate-800">{t('res.show.take_payment')}</h3>
                                <select className="select" value={payment.method} onChange={(e) => setPayment({ ...payment, method: e.target.value })}>
                                    {methods.data?.filter((m) => m.code !== 'CL').map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
                                </select>
                                <div className="flex flex-wrap gap-2">
                                    <input className="input min-w-0 flex-1" inputMode="numeric" type="number" min={1} required aria-label={t('res.show.amount_fcfa')} placeholder={t('res.show.amount_fcfa')} value={payment.amount}
                                        onChange={(e) => setPayment({ ...payment, amount: e.target.value })} />
                                    {f.balance > 0 && (
                                        <button type="button" className="btn-secondary btn-sm whitespace-nowrap"
                                            onClick={() => setPayment({ ...payment, amount: String(f.balance) })}>{t('res.show.full_balance')}</button>
                                    )}
                                </div>
                                <input className="input" aria-label={t('res.show.reference')} placeholder={t('res.show.reference')} value={payment.reference}
                                    onChange={(e) => setPayment({ ...payment, reference: e.target.value })} />
                                <button className="btn-primary btn-lg w-full justify-center" disabled={busy === 'pay'}>{t('res.show.record_payment')}</button>
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
