import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import SignaturePad from '../Reservations/SignaturePad';
import { money, type Lang } from './i18n';
import { Notice, publicApi } from './PublicLayout';

/** R-B self-service cards for the manage-booking page: change dates, cancel fee, services, early/late, pay, digital check-in, review. */
const L = {
    fr: {
        change: 'Modifier les dates', preview: 'Voir le nouveau prix', apply: 'Confirmer le changement', newTotal: 'Nouveau total', diff: 'Différence',
        closed: { cutoff: 'Les modifications sont closes avant l\'arrivée. Contactez l\'hôtel.', disabled: 'Modification en ligne indisponible. Contactez l\'hôtel.', status: 'Cette réservation ne peut plus être modifiée.' } as Record<string, string>,
        extendOnly: 'Tarif non remboursable : prolongation uniquement.', cancelFee: "Frais si vous annulez maintenant", free: 'Gratuit',
        extras: 'Services et horaires', early: 'Arrivée anticipée', late: 'Départ tardif', service: 'Ajouter un service', buy: 'Ajouter', qty: 'Quantité', free0: 'avant',
        at: 'à', added: 'Ajouté à votre séjour.', pay: 'Régler le solde', payNow: 'Payer maintenant', due: 'Reste à payer',
        checkin: 'Enregistrement en ligne', idType: "Pièce d'identité", idNumber: 'Numéro', idExpiry: 'Expiration', nationality: 'Nationalité (ISO)', birth: 'Date de naissance', address: 'Adresse',
        eta: "Heure d'arrivée", photo: "Photo de la pièce", signature: 'Signature', clear: 'Effacer', accept: "J'ai lu la fiche de police et je l'accepte", send: 'Envoyer', received: 'Enregistrement reçu.',
        ready: 'Prêt pour votre arrivée', missing: 'Il manque', items: { id: 'pièce', card: 'signature', payment: 'acompte', room: 'chambre' } as Record<string, string>,
        review: 'Votre avis', rating: 'Note', comment: 'Commentaire', thanks: 'Merci pour votre avis.', passport: 'Passeport', national_id: "Carte d'identité", residence_permit: 'Titre de séjour', driving_licence: 'Permis',
        purchases: 'Vos achats', pending: 'sur la note à l\'arrivée',
    },
    en: {
        change: 'Change dates', preview: 'See new price', apply: 'Confirm change', newTotal: 'New total', diff: 'Difference',
        closed: { cutoff: 'Changes are closed before arrival. Please contact the hotel.', disabled: 'Online changes are unavailable. Please contact the hotel.', status: 'This booking can no longer be changed.' } as Record<string, string>,
        extendOnly: 'Non-refundable rate: extension only.', cancelFee: 'Fee if you cancel now', free: 'Free',
        extras: 'Services and timing', early: 'Early check-in', late: 'Late check-out', service: 'Add a service', buy: 'Add', qty: 'Quantity', free0: 'before',
        at: 'at', added: 'Added to your stay.', pay: 'Pay the balance', payNow: 'Pay now', due: 'Balance due',
        checkin: 'Online check-in', idType: 'ID document', idNumber: 'Number', idExpiry: 'Expiry', nationality: 'Nationality (ISO)', birth: 'Date of birth', address: 'Address',
        eta: 'Arrival time', photo: 'ID photo', signature: 'Signature', clear: 'Clear', accept: 'I have read the registration card and accept it', send: 'Send', received: 'Check-in received.',
        ready: 'Ready for your arrival', missing: 'Missing', items: { id: 'ID', card: 'signature', payment: 'deposit', room: 'room' } as Record<string, string>,
        review: 'Your review', rating: 'Rating', comment: 'Comment', thanks: 'Thank you for your review.', passport: 'Passport', national_id: 'National ID', residence_permit: 'Residence permit', driving_licence: 'Driving licence',
        purchases: 'Your purchases', pending: 'on your bill at arrival',
    },
};

interface Fee { amount: number; label: string; hour: number }
interface Opts {
    modify: { allowed: boolean; reason: string | null; extend_only: boolean };
    cancel: { allowed: boolean; fee: number };
    services: { code: string; name: string; price: number }[];
    early_checkin: { hour: number; fee: Fee }[]; late_checkout: { hour: number; fee: Fee }[];
    purchases: { id: number; label: string; amount: number; status: string }[];
    balance: { due: number; currency: string };
    readiness: { status: string; missing: string[] };
    review: { allowed: boolean; submitted: boolean };
}
export interface ManageBooking { number: string; arrival: string; departure: string; status: string; currency: string; manage_token: string }

export const Card = ({ id, title, children }: { id?: string; title: string; children: ReactNode }) => (
    <section id={id} className="scroll-mt-4 space-y-3 rounded-lg border border-slate-200 bg-white p-5">
        <h2 className="font-serif text-xl">{title}</h2>
        {children}
    </section>
);

const toDataUrl = (f: File) => new Promise<string>((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result)); r.onerror = rej; r.readAsDataURL(f); });

export default function ManageExperience({ b, slug, lang, onChanged, hidePay = false }: { b: ManageBooking; slug: string; lang: Lang; onChanged: () => void; hidePay?: boolean }) {
    const d = L[lang] ?? L.fr;
    const auth = { confirmation: b.number, token: b.manage_token };
    const [o, setO] = useState<Opts | null>(null);
    const [msg, setMsg] = useState(''); const [err, setErr] = useState(''); const [busy, setBusy] = useState(false);
    const [dates, setDates] = useState({ arrival: b.arrival, departure: b.departure });
    const [prev, setPrev] = useState<{ new_total: number; difference: number } | null>(null);
    const [svc, setSvc] = useState({ code: '', quantity: 1 });
    const [ci, setCi] = useState({ id_type: 'passport', id_number: '', id_expiry: '', nationality: '', birth_date: '', address: '', eta: '14:00', signature: '', id_photo: '', card_accepted: false });
    const [rv, setRv] = useState({ rating: 5, comment: '' });

    const onSig = useCallback((v: string) => setCi((c) => ({ ...c, signature: v })), []);
    const load = useCallback(() => publicApi<Opts>(slug, '/manage/options', auth).then(setO).catch(() => setO(null)), [slug, b.number, b.manage_token]);
    useEffect(() => { load(); }, [load]);

    const run = async (fn: () => Promise<void>) => {
        setBusy(true); setErr(''); setMsg('');
        try { await fn(); await load(); } catch (e) { setErr((e as Error).message); }
        setBusy(false);
    };
    const modify = (apply: boolean) => run(async () => {
        const r = await publicApi<{ new_total: number; difference: number }>(slug, '/manage/modify', { ...auth, ...dates, apply });
        if (apply) { setPrev(null); onChanged(); } else setPrev(r);
    });
    const timing = (kind: string, hour: number) => run(async () => {
        const time = `${String(hour).padStart(2, '0')}:00`;
        await publicApi(slug, '/manage/timing', { ...auth, kind, time, apply: true }); setMsg(d.added);
    });
    const addService = (e: FormEvent) => { e.preventDefault(); run(async () => { await publicApi(slug, '/manage/services', { ...auth, ...svc }); setMsg(d.added); }); };
    const payBalance = () => run(async () => { const r = await publicApi<{ url: string }>(slug, '/manage/pay', auth); window.location.href = r.url; });
    const sendCheckin = (e: FormEvent) => { e.preventDefault(); run(async () => {
        await publicApi(slug, '/manage/checkin', { ...auth, ...ci, id_expiry: ci.id_expiry || null, nationality: ci.nationality || null, birth_date: ci.birth_date || null, address: ci.address || null, signature: ci.signature || null, id_photo: ci.id_photo || null });
        setMsg(d.received);
    }); };
    const sendReview = (e: FormEvent) => { e.preventDefault(); run(async () => { await publicApi(slug, '/manage/review', { ...auth, ...rv }); setMsg(d.thanks); }); };

    if (!o) return null;
    const upcoming = ['tentative', 'confirmed'].includes(b.status);
    const btn = 'btn-primary min-h-11 px-5';

    return (
        <>
            {err && <div className="md:col-span-2"><Notice kind="error">{err}</Notice></div>}
            {msg && <div className="md:col-span-2"><Notice kind="ok">{msg}</Notice></div>}

            {upcoming && (
                <Card title={d.change}>
                    {o.modify.allowed ? (
                        <>
                            {o.modify.extend_only && <p className="text-sm text-slate-600">{d.extendOnly}</p>}
                            <div className="grid grid-cols-2 gap-3">
                                <input aria-label="arrival" type="date" className="input" value={dates.arrival} onChange={(e) => { setDates({ ...dates, arrival: e.target.value }); setPrev(null); }} />
                                <input aria-label="departure" type="date" className="input" value={dates.departure} onChange={(e) => { setDates({ ...dates, departure: e.target.value }); setPrev(null); }} />
                            </div>
                            {prev && <p className="text-base">{d.newTotal}: <strong>{money(prev.new_total, b.currency, lang)}</strong> ({d.diff}: {prev.difference >= 0 ? '+' : ''}{money(prev.difference, b.currency, lang)})</p>}
                            <div className="flex gap-3">
                                <button type="button" className="btn-secondary" disabled={busy} onClick={() => modify(false)}>{d.preview}</button>
                                {prev && <button type="button" className={btn} disabled={busy} onClick={() => modify(true)}>{d.apply}</button>}
                            </div>
                        </>
                    ) : <p className="text-sm">{d.closed[o.modify.reason ?? 'status']}</p>}
                    {o.cancel.allowed && <p className="text-sm"><strong>{d.cancelFee}:</strong> {o.cancel.fee > 0 ? money(o.cancel.fee, b.currency, lang) : d.free}</p>}
                </Card>
            )}

            {(o.services.length > 0 || o.early_checkin.length > 0 || o.late_checkout.length > 0) && (
                <Card title={d.extras}>
                    {[['early_checkin', d.early, o.early_checkin], ['late_checkout', d.late, o.late_checkout]].map(([kind, label, list]) => (
                        (list as Opts['early_checkin']).length > 0 && (
                            <div key={kind as string} className="space-y-1">
                                <p className="font-semibold">{label as string}</p>
                                {(list as Opts['early_checkin']).map((x) => (
                                    <div key={x.hour} className="flex items-center justify-between gap-2 text-base">
                                        <span>{kind === 'early_checkin' ? `${d.free0} ${x.hour}h` : `${d.at} ${x.hour}h`}: {money(x.fee.amount, b.currency, lang)}</span>
                                        <button type="button" className="btn-secondary" disabled={busy || o.purchases.some((p) => p.label.startsWith(x.fee.label))} onClick={() => timing(kind as string, x.hour)}>{d.buy}</button>
                                    </div>
                                ))}
                            </div>
                        )
                    ))}
                    {o.services.length > 0 && (
                        <form onSubmit={addService} className="flex flex-wrap items-end gap-2">
                            <div className="grow"><label className="label" htmlFor="svc">{d.service}</label>
                                <select id="svc" className="input" value={svc.code} onChange={(e) => setSvc({ ...svc, code: e.target.value })} required>
                                    <option value="" />{o.services.map((s) => <option key={s.code} value={s.code}>{s.name} - {money(s.price, b.currency, lang)}</option>)}
                                </select></div>
                            <div className="w-24"><label className="label" htmlFor="q">{d.qty}</label><input id="q" type="number" min={1} max={20} className="input" value={svc.quantity} onChange={(e) => setSvc({ ...svc, quantity: +e.target.value })} /></div>
                            <button type="submit" className={btn} disabled={busy}>{d.buy}</button>
                        </form>
                    )}
                    {o.purchases.length > 0 && (
                        <div><p className="font-semibold">{d.purchases}</p>
                            <ul className="text-sm">{o.purchases.map((p) => <li key={p.id}>{p.label}: {money(p.amount, b.currency, lang)}{p.status === 'pending' ? ` (${d.pending})` : ''}</li>)}</ul></div>
                    )}
                </Card>
            )}

            {!hidePay && o.balance.due > 0 && ['tentative', 'confirmed', 'checked_in'].includes(b.status) && (
                <Card id="pay" title={d.pay}>
                    <p className="text-base">{d.due}: <strong>{money(o.balance.due, b.currency, lang)}</strong></p>
                    <button type="button" className={btn} disabled={busy} onClick={payBalance}>{d.payNow}</button>
                </Card>
            )}

            {upcoming && (
                <Card id="checkin" title={d.checkin}>
                    <p className="text-sm"><span className={o.readiness.status === 'ready' ? 'badge-green' : 'badge-yellow'}>{o.readiness.status === 'ready' ? d.ready : `${d.missing}: ${o.readiness.missing.map((k) => d.items[k]).join(', ')}`}</span></p>
                    <form onSubmit={sendCheckin} className="grid gap-3 sm:grid-cols-2">
                        <div><label className="label" htmlFor="x-idt">{d.idType}</label>
                            <select id="x-idt" className="input" value={ci.id_type} onChange={(e) => setCi({ ...ci, id_type: e.target.value })}>
                                {(['passport', 'national_id', 'residence_permit', 'driving_licence'] as const).map((k) => <option key={k} value={k}>{d[k]}</option>)}
                            </select></div>
                        <div><label className="label" htmlFor="x-idn">{d.idNumber}</label><input id="x-idn" className="input" value={ci.id_number} onChange={(e) => setCi({ ...ci, id_number: e.target.value })} required /></div>
                        <div><label className="label" htmlFor="x-ide">{d.idExpiry}</label><input id="x-ide" type="date" className="input" value={ci.id_expiry} onChange={(e) => setCi({ ...ci, id_expiry: e.target.value })} /></div>
                        <div><label className="label" htmlFor="x-nat">{d.nationality}</label><input id="x-nat" maxLength={2} className="input" value={ci.nationality} onChange={(e) => setCi({ ...ci, nationality: e.target.value.toUpperCase() })} /></div>
                        <div><label className="label" htmlFor="x-bd">{d.birth}</label><input id="x-bd" type="date" className="input" value={ci.birth_date} onChange={(e) => setCi({ ...ci, birth_date: e.target.value })} /></div>
                        <div><label className="label" htmlFor="x-eta">{d.eta}</label><input id="x-eta" type="time" className="input" value={ci.eta} onChange={(e) => setCi({ ...ci, eta: e.target.value })} required /></div>
                        <div className="sm:col-span-2"><label className="label" htmlFor="x-adr">{d.address}</label><input id="x-adr" className="input" value={ci.address} onChange={(e) => setCi({ ...ci, address: e.target.value })} /></div>
                        <div className="sm:col-span-2"><label className="label" htmlFor="x-ph">{d.photo}</label>
                            <input id="x-ph" type="file" accept="image/*" capture="environment" className="input" onChange={async (e) => { const f = e.target.files?.[0]; setCi({ ...ci, id_photo: f ? await toDataUrl(f) : '' }); }} /></div>
                        <div className="sm:col-span-2"><SignaturePad label={d.signature} clearLabel={d.clear} onChange={onSig} /></div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={ci.card_accepted} onChange={(e) => setCi({ ...ci, card_accepted: e.target.checked })} required />{d.accept}</label>
                        <div className="sm:col-span-2"><button type="submit" className={btn} disabled={busy}>{d.send}</button></div>
                    </form>
                </Card>
            )}

            {o.review.allowed && !o.review.submitted && (
                <Card id="review" title={d.review}>
                    <form onSubmit={sendReview} className="space-y-3">
                        <div><label className="label" htmlFor="rt">{d.rating}</label>
                            <select id="rt" className="input" value={rv.rating} onChange={(e) => setRv({ ...rv, rating: +e.target.value })}>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n} / 5</option>)}</select></div>
                        <div><label className="label" htmlFor="rc">{d.comment}</label><textarea id="rc" rows={3} maxLength={1000} className="input" value={rv.comment} onChange={(e) => setRv({ ...rv, comment: e.target.value })} /></div>
                        <button type="submit" className={btn} disabled={busy}>{d.send}</button>
                    </form>
                </Card>
            )}
        </>
    );
}
