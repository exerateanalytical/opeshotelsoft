import { useState, type FormEvent } from 'react';
import { t } from './i18n';
import PublicLayout, { Notice, publicApi, type PublicProps } from './PublicLayout';

const L = {
    fr: {
        title: 'Votre avis sur votre séjour', rating: 'Note', comment: 'Commentaire', send: 'Envoyer mon avis', thanks: 'Merci, votre avis a bien été reçu.',
        reference: 'Référence de réservation', referenceHelp: 'Les 8 caractères indiqués sur votre confirmation. Si votre lien vient d’un message, elle est déjà remplie.',
        hotel: 'Hôtel du séjour', hotelPick: 'Choisir l’hôtel',
        numberOnly: 'Vous avez saisi un numéro de confirmation. Ce numéro peut exister dans plusieurs hôtels : choisissez l’hôtel de votre séjour, ou saisissez la référence de réservation (8 caractères) de votre confirmation.',
        ambiguous: 'Ce numéro correspond à plusieurs réservations. Choisissez l’hôtel de votre séjour ou saisissez la référence de réservation de votre confirmation.',
    },
    en: {
        title: 'Your review of your stay', rating: 'Rating', comment: 'Comment', send: 'Send my review', thanks: 'Thank you, your review was received.',
        reference: 'Booking reference', referenceHelp: 'The 8 characters shown on your confirmation. If you came from a message link, it is already filled in.',
        hotel: 'Hotel of your stay', hotelPick: 'Choose the hotel',
        numberOnly: 'You entered a confirmation number. The same number can exist in several hotels: choose the hotel of your stay, or enter the booking reference (8 characters) from your confirmation.',
        ambiguous: 'This number matches several bookings. Choose the hotel of your stay or enter the booking reference from your confirmation.',
    },
};

/** A booking reference is the unique public code: 8 letters and digits (never a short numbered confirmation like RES-00012). */
const looksLikeReference = (v: string) => /^[A-Za-z0-9]{8}$/.test(v.replace(/[^A-Za-z0-9]/g, '')) && !/[-/]/.test(v);

/** Review page: booking reference (link) or confirmation number + hotel, then last name, rating and comment. */
export default function Review(p: PublicProps & { hotels?: { code: string; name: string }[] }) {
    const d = t(p.lang);
    const x = L[p.lang] ?? L.fr;
    const [f, setF] = useState({ confirmation: p.query.c ?? '', hotel: p.query.h ?? '', last_name: '', rating: 5, comment: '' });
    const [error, setError] = useState('');
    const [needHotel, setNeedHotel] = useState(false);
    const [done, setDone] = useState(false);
    const [busy, setBusy] = useState(false);
    const hotels = p.hotels ?? [];
    const typedNumberOnly = f.confirmation.trim() !== '' && !looksLikeReference(f.confirmation.trim());
    const showHotel = hotels.length > 1 && (typedNumberOnly || needHotel);

    const submit = async (e: FormEvent) => {
        e.preventDefault(); setBusy(true); setError('');
        try {
            await publicApi(p.group.slug, '/manage/review', { confirmation: f.confirmation.trim(), hotel: f.hotel || null, last_name: f.last_name.trim(), rating: f.rating, comment: f.comment || null });
            setDone(true);
        } catch (err) {
            const code = (err as Error & { code?: string }).code;
            if (code === 'ambiguous_booking') { setNeedHotel(true); setError(x.ambiguous); } else { setError((err as Error).message); }
        }
        setBusy(false);
    };

    return (
        <PublicLayout p={p} title={x.title}>
            <div className="max-w-xl space-y-4">
                {done ? <Notice kind="ok">{x.thanks}</Notice> : (
                    <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
                        {error && <Notice kind="error">{error}</Notice>}
                        <div>
                            <label className="label" htmlFor="c">{x.reference}</label>
                            <input id="c" className="input font-mono uppercase" value={f.confirmation} onChange={(e) => setF({ ...f, confirmation: e.target.value })} required aria-describedby="c-help" />
                            <p id="c-help" className="mt-1 text-sm text-slate-600">{x.referenceHelp}</p>
                            {typedNumberOnly && <p className="mt-1 text-sm text-slate-700">{x.numberOnly}</p>}
                        </div>
                        {showHotel && (
                            <div><label className="label" htmlFor="h">{x.hotel}</label>
                                <select id="h" className="select" value={f.hotel} onChange={(e) => setF({ ...f, hotel: e.target.value })} required>
                                    <option value="">{x.hotelPick}</option>
                                    {hotels.map((h) => <option key={h.code} value={h.code}>{h.name}</option>)}
                                </select></div>
                        )}
                        <div><label className="label" htmlFor="l">{d.lastName}</label><input id="l" className="input" value={f.last_name} onChange={(e) => setF({ ...f, last_name: e.target.value })} required /></div>
                        <div><label className="label" htmlFor="r">{x.rating}</label>
                            <select id="r" className="select" value={f.rating} onChange={(e) => setF({ ...f, rating: +e.target.value })}>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n} / 5</option>)}</select></div>
                        <div><label className="label" htmlFor="m">{x.comment}</label><textarea id="m" rows={4} maxLength={1000} className="input" value={f.comment} onChange={(e) => setF({ ...f, comment: e.target.value })} /></div>
                        <button type="submit" disabled={busy} className="btn-primary min-h-11 px-5">{x.send}</button>
                    </form>
                )}
            </div>
        </PublicLayout>
    );
}
