import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { FileText, Image, PenLine, Sparkles } from 'lucide-react';
import Field from '@/Components/ui/Field';
import { api, useApi } from '@/lib/api';
import { useCan } from '@/lib/useCan';
import { useT } from '@/lib/i18n';
import { formatCurrency } from '@/lib/utils';
import { errText, openApiFile } from './blob';

type Notify = (ok: boolean, text: string) => void;

export interface UpsellOffer { id: number; reservation_id: number; from_room_type_id: number; to_room_type_id: number; stage: string; price_per_night: number; nights: number; total: number; status: string; channel: string }
interface RoomType { id: number; name: string }

export function useRoomTypeNames() {
    const types = useApi<{ data: RoomType[] }>('room-types?per_page=100');
    return (id: number) => types.data?.data.find((x) => x.id === id)?.name ?? `#${id}`;
}

const STATUS_BADGE: Record<string, string> = { offered: 'badge-blue', accepted: 'badge-green', declined: 'badge-slate', expired: 'badge-slate' };

/** Row 6: make, accept and decline room-upgrade offers for one reservation. */
export function UpsellPanel({ id, status, notify, onDone }: { id: string; status: string; notify: Notify; onDone: () => void }) {
    const t = useT();
    const can = useCan();
    const offers = useApi<{ data: UpsellOffer[] }>(`revenue/upsell-offers?reservation_id=${id}`);
    const name = useRoomTypeNames();
    const [stage, setStage] = useState(status === 'checked_in' ? 'check_in' : 'booking');
    const [busy, setBusy] = useState<string | null>(null);
    if (!can('revenue.upsell', 'revenue.manage', 'revenue.view')) return null;
    const mayAct = can('revenue.upsell', 'revenue.manage');
    const open = ['tentative', 'confirmed', 'checked_in'].includes(status);

    const go = async (key: string, path: string, body: object, ok: string) => {
        setBusy(key);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            notify(true, ok);
            offers.reload();
            onDone();
        } catch (e) { notify(false, errText(e)); } finally { setBusy(null); }
    };
    const rows = offers.data?.data ?? [];

    return (
        <section className="card px-5 py-4 space-y-3" aria-labelledby="gape-upsell-h">
            <h3 id="gape-upsell-h" className="font-semibold text-slate-800">{t('gape.upsell.title')}</h3>
            {mayAct && open && (
                <div className="flex flex-wrap items-end gap-2">
                    <Field label={t('gape.upsell.stage')} className="min-w-0 flex-1">
                        <select className="select" value={stage} onChange={(e) => setStage(e.target.value)}>
                            {['booking', 'pre_arrival', 'check_in'].map((s) => <option key={s} value={s}>{t(`gape.upsell.stage_${s}`)}</option>)}
                        </select>
                    </Field>
                    <button type="button" className="btn-secondary" disabled={busy === 'offer'}
                        onClick={() => go('offer', `revenue/reservations/${id}/upsell-offers`, { stage }, t('gape.upsell.made'))}>
                        <Sparkles className="h-4 w-4" aria-hidden="true" /> {t('gape.upsell.make')}
                    </button>
                </div>
            )}
            {offers.error && <p className="text-sm text-red-700">{offers.error.message}</p>}
            {offers.data && rows.length === 0 && <p className="text-sm text-slate-500">{t('gape.upsell.none')}</p>}
            <ul className="space-y-2">
                {rows.map((o) => (
                    <li key={o.id} className="rounded-md border border-slate-200 p-3 text-sm space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span>{name(o.from_room_type_id)} → <strong>{name(o.to_room_type_id)}</strong></span>
                            <span className={STATUS_BADGE[o.status] ?? 'badge-slate'}>{t(`gape.upsell.status_${o.status}`)}</span>
                        </div>
                        <div className="text-slate-600">{t('gape.upsell.price', { night: formatCurrency(o.price_per_night), nights: o.nights, total: formatCurrency(o.total) })}</div>
                        {mayAct && o.status === 'offered' && (
                            <div className="btn-row">
                                <button type="button" className="btn-primary btn-sm" disabled={busy !== null} onClick={() => go(`a${o.id}`, `revenue/upsell-offers/${o.id}/accept`, {}, t('gape.upsell.accepted'))}>{t('gape.upsell.accept')}</button>
                                <button type="button" className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => go(`d${o.id}`, `revenue/upsell-offers/${o.id}/decline`, {}, t('gape.upsell.declined'))}>{t('gape.upsell.decline')}</button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

interface Prefs { floor?: string; view?: string; quiet?: boolean; together?: string }

/** Row 32: room preferences on the reservation (experience/reservations/{r}/preferences). */
export function PreferencesPanel({ id, prefs, editable, notify, onDone }: { id: string; prefs: Prefs | null; editable: boolean; notify: Notify; onDone: () => void }) {
    const t = useT();
    const can = useCan();
    const [f, setF] = useState({ floor: '', view: '', quiet: false, together: '' });
    const [busy, setBusy] = useState(false);
    useEffect(() => setF({ floor: prefs?.floor ?? '', view: prefs?.view ?? '', quiet: !!prefs?.quiet, together: prefs?.together ?? '' }), [prefs]);
    const mayEdit = editable && can('experience.manage');

    return (
        <form className="card px-5 py-4 space-y-3" aria-labelledby="gape-prefs-h" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            try {
                await api(`experience/reservations/${id}/preferences`, { method: 'PATCH', body: JSON.stringify({ floor: f.floor || null, view: f.view || null, quiet: f.quiet, together: f.together || null }) });
                notify(true, t('gape.prefs.saved'));
                onDone();
            } catch (err) { notify(false, errText(err)); } finally { setBusy(false); }
        }}>
            <h3 id="gape-prefs-h" className="font-semibold text-slate-800">{t('gape.prefs.title')}</h3>
            <fieldset disabled={!mayEdit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label={t('gape.prefs.floor')}><input className="input" maxLength={10} value={f.floor} onChange={(e) => setF({ ...f, floor: e.target.value })} /></Field>
                <Field label={t('gape.prefs.view')}><input className="input" maxLength={30} value={f.view} onChange={(e) => setF({ ...f, view: e.target.value })} /></Field>
                <Field label={t('gape.prefs.together')} hint={t('gape.prefs.together_hint')}><input className="input" maxLength={20} value={f.together} onChange={(e) => setF({ ...f, together: e.target.value })} /></Field>
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={f.quiet} onChange={(e) => setF({ ...f, quiet: e.target.checked })} /> {t('gape.prefs.quiet')}</label>
            </fieldset>
            {mayEdit && <button className="btn-secondary w-full justify-center" disabled={busy}>{t('gape.prefs.save')}</button>}
        </form>
    );
}

/** Row 8: open the ID photo, signature and printable registration card. */
export function RegistrationLinks({ id, notify }: { id: string; notify: Notify }) {
    const t = useT();
    const card = useApi<{ has_id_photo: boolean; has_signature: boolean }>(`reservations/${id}/registration-card`);
    const [busy, setBusy] = useState<string | null>(null);
    const open = async (key: string, path: string) => {
        setBusy(key);
        try { await openApiFile(path); } catch (e) { notify(false, errText(e)); } finally { setBusy(null); }
    };
    if (!card.data) return null;
    return (
        <section className="card px-5 py-4 space-y-3" aria-labelledby="gape-reg-h">
            <h3 id="gape-reg-h" className="font-semibold text-slate-800">{t('gape.reg.title')}</h3>
            <div className="btn-row">
                {card.data.has_id_photo && <button type="button" className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => open('p', `reservations/${id}/registration-card/id-photo`)}><Image className="h-4 w-4" aria-hidden="true" /> {t('gape.reg.id_photo')}</button>}
                {card.data.has_signature && <button type="button" className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => open('s', `reservations/${id}/registration-card/signature`)}><PenLine className="h-4 w-4" aria-hidden="true" /> {t('gape.reg.signature')}</button>}
                <button type="button" className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => open('c', `documents/registration-cards/${id}`)}><FileText className="h-4 w-4" aria-hidden="true" /> {t('gape.reg.print')}</button>
            </div>
            {!card.data.has_id_photo && !card.data.has_signature && <p className="text-sm text-slate-500">{t('gape.reg.no_files')}</p>}
        </section>
    );
}

interface GroupSummary { booking_ref: string; rooms: { id: number; number: string; status: string; guest: { first_name: string; last_name: string } | null; room: string | null }[]; total: number }

/** Row 32 (second endpoint): other rooms booked under the same booking reference. */
export function BookingGroup({ bookingRef, currentId }: { bookingRef: string; currentId: number }) {
    const t = useT();
    const can = useCan();
    const g = useApi<GroupSummary>(can('experience.view') ? `experience/bookings/${encodeURIComponent(bookingRef)}` : null);
    if (!g.data || g.data.rooms.length < 2) return null;
    return (
        <section className="card px-5 py-4 space-y-2" aria-labelledby="gape-grp-h">
            <h3 id="gape-grp-h" className="font-semibold text-slate-800">{t('gape.group.title', { ref: bookingRef })}</h3>
            <ul className="text-sm space-y-1">
                {g.data.rooms.map((r) => (
                    <li key={r.id} className="flex justify-between gap-2">
                        {r.id === currentId ? <span className="font-medium">{r.number}</span> : <Link className="text-navy-600 underline underline-offset-2" href={`/reservations/${r.id}`}>{r.number}</Link>}
                        <span className="text-slate-600">{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : ''}{r.room ? ` · ${r.room}` : ''}</span>
                    </li>
                ))}
            </ul>
            <p className="text-sm text-slate-600">{t('gape.group.total', { total: formatCurrency(g.data.total) })}</p>
        </section>
    );
}
