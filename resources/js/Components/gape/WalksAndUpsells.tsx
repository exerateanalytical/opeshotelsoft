import { useState } from 'react';
import { Link } from '@inertiajs/react';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, useApi } from '@/lib/api';
import { useCan } from '@/lib/useCan';
import { useT } from '@/lib/i18n';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { errText } from './blob';
import { type UpsellOffer, useRoomTypeNames } from './ReservationExtras';

type Notify = (ok: boolean, text: string) => void;
interface Walk { id: number; reservation_id: number; partner_hotel: string | null; compensation: number; note: string | null; created_at: string }
interface Arrival { id: number; number: string; arrival: string; guest: { first_name: string; last_name: string } | null; room_type: { code: string } | null }
interface Page<T> { data: T[] }

const today = () => new Date().toISOString().slice(0, 10);

/** Table 2 row 5: walk a confirmed arrival to a partner hotel, and the walk history. */
export function WalksSection({ notify, defaults }: { notify: Notify; defaults: { partner: string; compensation: string } }) {
    const t = useT();
    const can = useCan();
    const [date, setDate] = useState(today());
    const arrivals = useApi<Page<Arrival>>(`reservations?status=confirmed&arriving=${date}&per_page=100`, [date]);
    const walks = useApi<Page<Walk>>('revenue/walks');
    const [walk, setWalk] = useState<{ r: Arrival; partner: string; compensation: string; note: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const mayWalk = can('revenue.upsell', 'revenue.manage', 'frontdesk.manage');

    return (
        <section className="card space-y-3 p-4" aria-labelledby="gape-walk-h">
            <h2 id="gape-walk-h" className="font-medium">{t('gape.walk.title')}</h2>
            <p className="text-sm text-slate-600">{t('gape.walk.help')}</p>
            <Field label={t('gape.walk.date')} className="sm:w-56"><input type="date" className="input" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <QueryStatus query={arrivals} variant="list" rows={2} />
            {arrivals.data && arrivals.data.data.length === 0 && <p className="text-sm text-slate-500">{t('gape.walk.no_arrivals')}</p>}
            <ul className="divide-y divide-slate-100">
                {arrivals.data?.data.map((r) => (
                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span><Link className="font-mono text-navy-600 underline underline-offset-2" href={`/reservations/${r.id}`}>{r.number}</Link> · {r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : '-'} · {r.room_type?.code}</span>
                        {mayWalk && <button type="button" className="btn-secondary btn-sm" onClick={() => setWalk({ r, partner: defaults.partner, compensation: defaults.compensation, note: '' })}>{t('gape.walk.action')}</button>}
                    </li>
                ))}
            </ul>

            <h3 className="pt-2 text-sm font-medium">{t('gape.walk.history')}</h3>
            <QueryStatus query={walks} variant="list" rows={2} />
            {walks.data && walks.data.data.length === 0 && <EmptyState title={t('gape.walk.none')} description={t('gape.walk.none_text')} />}
            {walks.data && walks.data.data.length > 0 && (
                <div className="table-wrap"><table className="table-auto table-cards">
                    <thead><tr><th>{t('gape.walk.when')}</th><th>{t('gape.walk.reservation')}</th><th>{t('gape.walk.partner')}</th><th className="text-right">{t('gape.walk.compensation')}</th><th>{t('gape.walk.note')}</th></tr></thead>
                    <tbody>
                        {walks.data.data.map((w) => (
                            <tr key={w.id}>
                                <td data-label={t('gape.walk.when')}>{formatDateTime(w.created_at)}</td>
                                <td data-label={t('gape.walk.reservation')}><Link className="text-navy-600 underline underline-offset-2" href={`/reservations/${w.reservation_id}`}>#{w.reservation_id}</Link></td>
                                <td data-label={t('gape.walk.partner')}>{w.partner_hotel ?? '-'}</td>
                                <td data-label={t('gape.walk.compensation')} className="num">{formatCurrency(w.compensation)}</td>
                                <td data-label={t('gape.walk.note')}>{w.note ?? ''}</td>
                            </tr>
                        ))}
                    </tbody>
                </table></div>
            )}

            <Modal open={!!walk} onClose={() => setWalk(null)} title={walk ? t('gape.walk.modal', { number: walk.r.number }) : ''} formId="gape-walk-form"
                footer={<>
                    <button type="button" className="btn-secondary" onClick={() => setWalk(null)}>{t('gape.cancel')}</button>
                    <button type="submit" form="gape-walk-form" className="btn-danger" disabled={busy}>{t('gape.walk.confirm')}</button>
                </>}>
                {walk && (
                    <form id="gape-walk-form" className="space-y-3" onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        try {
                            await api(`revenue/reservations/${walk.r.id}/walk`, { method: 'POST', body: JSON.stringify({ partner_hotel: walk.partner || null, compensation: walk.compensation === '' ? null : +walk.compensation, note: walk.note || null }) });
                            notify(true, t('gape.walk.done', { number: walk.r.number }));
                            setWalk(null);
                            arrivals.reload();
                            walks.reload();
                        } catch (err) { notify(false, errText(err)); } finally { setBusy(false); }
                    }}>
                        <p className="text-sm text-slate-600">{t('gape.walk.warning')}</p>
                        <Field label={t('gape.walk.partner')}><input className="input" maxLength={150} value={walk.partner} onChange={(e) => setWalk({ ...walk, partner: e.target.value })} /></Field>
                        <Field label={t('gape.walk.compensation')}><input className="input" type="number" min={0} value={walk.compensation} onChange={(e) => setWalk({ ...walk, compensation: e.target.value })} /></Field>
                        <Field label={t('gape.walk.note')}><textarea className="textarea" rows={3} maxLength={1000} value={walk.note} onChange={(e) => setWalk({ ...walk, note: e.target.value })} /></Field>
                    </form>
                )}
            </Modal>
        </section>
    );
}

/** Table 2 row 6: every upsell offer across the hotel, filterable, with accept and decline. */
export function UpsellOffersSection({ notify }: { notify: Notify }) {
    const t = useT();
    const can = useCan();
    const [status, setStatus] = useState('offered');
    const offers = useApi<Page<UpsellOffer & { created_at: string }>>(`revenue/upsell-offers${status ? `?status=${status}` : ''}`, [status]);
    const name = useRoomTypeNames();
    const [busy, setBusy] = useState<number | null>(null);
    const mayAct = can('revenue.upsell', 'revenue.manage');
    const go = async (o: UpsellOffer, action: 'accept' | 'decline') => {
        setBusy(o.id);
        try {
            await api(`revenue/upsell-offers/${o.id}/${action}`, { method: 'POST', body: '{}' });
            notify(true, t(action === 'accept' ? 'gape.upsell.accepted' : 'gape.upsell.declined'));
            offers.reload();
        } catch (e) { notify(false, errText(e)); } finally { setBusy(null); }
    };

    return (
        <section className="card space-y-3 p-4" aria-labelledby="gape-up-h">
            <h2 id="gape-up-h" className="font-medium">{t('gape.upsell.list_title')}</h2>
            <Field label={t('gape.upsell.status')} className="sm:w-56">
                <select className="select" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{t('gape.all')}</option>
                    {['offered', 'accepted', 'declined', 'expired'].map((s) => <option key={s} value={s}>{t(`gape.upsell.status_${s}`)}</option>)}
                </select>
            </Field>
            <QueryStatus query={offers} variant="list" rows={2} />
            {offers.data && offers.data.data.length === 0 && <p className="text-sm text-slate-500">{t('gape.upsell.none')}</p>}
            {offers.data && offers.data.data.length > 0 && (
                <div className="table-wrap"><table className="table-auto table-cards">
                    <thead><tr><th>{t('gape.walk.reservation')}</th><th>{t('gape.upsell.upgrade')}</th><th>{t('gape.upsell.stage')}</th><th className="text-right">{t('gape.upsell.total')}</th><th>{t('gape.upsell.status')}</th><th><span className="sr-only">{t('gape.actions')}</span></th></tr></thead>
                    <tbody>
                        {offers.data.data.map((o) => (
                            <tr key={o.id}>
                                <td data-label={t('gape.walk.reservation')}><Link className="text-navy-600 underline underline-offset-2" href={`/reservations/${o.reservation_id}`}>#{o.reservation_id}</Link> <span className="text-slate-500">{formatDate(o.created_at)}</span></td>
                                <td data-label={t('gape.upsell.upgrade')}>{name(o.from_room_type_id)} → {name(o.to_room_type_id)}</td>
                                <td data-label={t('gape.upsell.stage')}>{t(`gape.upsell.stage_${o.stage}`)}</td>
                                <td data-label={t('gape.upsell.total')} className="num">{formatCurrency(o.total)}</td>
                                <td data-label={t('gape.upsell.status')}>{t(`gape.upsell.status_${o.status}`)}</td>
                                <td>{mayAct && o.status === 'offered' && (
                                    <span className="btn-row justify-end">
                                        <button type="button" className="btn-primary btn-sm" disabled={busy !== null} onClick={() => go(o, 'accept')}>{t('gape.upsell.accept')}</button>
                                        <button type="button" className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => go(o, 'decline')}>{t('gape.upsell.decline')}</button>
                                    </span>
                                )}</td>
                            </tr>
                        ))}
                    </tbody>
                </table></div>
            )}
        </section>
    );
}
