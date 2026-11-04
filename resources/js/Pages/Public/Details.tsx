import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { money, t } from './i18n';
import PublicLayout, { Notice, publicApi, url, type PublicProps } from './PublicLayout';
import { nightCount, type Offer } from './Search';
import { storedMember } from './SearchForm';

export interface BookingResult { confirmation: string; reference?: string; hotel_code?: string; status: string; total: number; currency: string; deposit_required: number; manage_token: string }

function storedOffer(): { hotel: string; offer: Offer } | null {
    try { return JSON.parse(sessionStorage.getItem('opes_offer') ?? 'null'); } catch { return null; }
}

export default function Details(p: PublicProps) {
    const d = t(p.lang);
    const q = p.query;
    const chosen = storedOffer();
    const offer = chosen && String(chosen.offer.rate_plan.id) === q.rate_plan && String(chosen.offer.room_type.id) === q.room_type ? chosen.offer : null;
    const [g, setG] = useState({ first_name: '', last_name: '', email: '', phone: '', country: 'CM', special_requests: '' });
    const [terms, setTerms] = useState(false);
    const [marketing, setMarketing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const set = (k: keyof typeof g) => (e: { target: { value: string } }) => setG({ ...g, [k]: e.target.value });

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const { special_requests, ...guest } = g;
            const r = await publicApi<BookingResult>(p.group.slug, '/reservations', {
                hotel_code: q.hotel, room_type_id: Number(q.room_type), rate_plan_id: Number(q.rate_plan), arrival: q.arrival, departure: q.departure,
                adults: Number(q.adults ?? 1), children: Number(q.children ?? 0), guest: { ...guest, country: guest.country || null },
                special_requests: special_requests || null, member_number: storedMember().member || null, accept_terms: terms, consent_marketing: marketing,
            });
            try { sessionStorage.setItem(`opes_booking_${r.reference ?? r.confirmation}`, JSON.stringify({ ...r, last_name: g.last_name })); } catch { /* storage unavailable */ }
            router.visit(url(p, '/confirmation', { c: r.reference ?? r.confirmation }));
        } catch (err) {
            setError((err as Error).message);
            setBusy(false);
        }
    };

    const field = (k: keyof typeof g, label: string, type = 'text', required = true) => (
        <div>
            <label className="label" htmlFor={k}>{label}</label>
            <input id={k} type={type} className="input" value={g[k]} onChange={set(k)} required={required} />
        </div>
    );

    return (
        <PublicLayout p={p} title={d.yourDetails}>
            <div className="grid gap-6 md:grid-cols-3">
                <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-5 md:col-span-2">
                    {error && <Notice kind="error">{error}</Notice>}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('first_name', d.firstName)}
                        {field('last_name', d.lastName)}
                        {field('email', d.email, 'email')}
                        {field('phone', d.phone, 'tel')}
                        {field('country', d.country, 'text', false)}
                    </div>
                    <div>
                        <label className="label" htmlFor="special_requests">{d.requests}</label>
                        <textarea id="special_requests" className="input" rows={3} value={g.special_requests} onChange={set('special_requests')} />
                    </div>
                    {offer && (
                        <p className="text-sm text-slate-700"><strong>{d.policy}:</strong> {offer.rate_plan.refundable ? d.freeUntil(offer.rate_plan.cancellation_hours ?? 24) : d.nonRefundablePolicy}</p>
                    )}
                    <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={terms} onChange={(e) => setTerms(e.target.checked)} required className="mt-1" /> {d.acceptTerms}</label>
                    <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={marketing} onChange={(e) => setMarketing(e.target.checked)} className="mt-1" /> {d.marketing}</label>
                    <div className="flex flex-wrap gap-3">
                        <button type="submit" disabled={busy || !terms} className="btn-accent min-h-11 px-6">{d.confirmBooking}</button>
                        <button type="button" onClick={() => window.history.back()} className="btn-secondary">{d.back}</button>
                    </div>
                </form>
                <aside className="h-fit space-y-2 rounded-lg border border-slate-200 bg-white p-5">
                    <p className="text-sm text-slate-500">{q.arrival} → {q.departure}</p>
                    {offer && (
                        <>
                            <p className="text-lg font-semibold">{offer.room_type.name}</p>
                            <p className="text-sm text-slate-600">{offer.rate_plan.name}</p>
                            <p className="text-sm">{nightCount(offer)} {d.nights}</p>
                            <p className="border-t border-slate-200 pt-2 text-xl font-semibold">{d.total}: {money(offer.total, offer.currency, p.lang)}</p>
                            {offer.rate_plan.deposit_required && <p className="text-sm"><span className="badge-blue">{d.depositRequired}</span></p>}
                        </>
                    )}
                </aside>
            </div>
        </PublicLayout>
    );
}
