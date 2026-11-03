import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { money, t } from './i18n';
import PublicLayout, { Notice, publicApi, url, type PublicProps } from './PublicLayout';
import SearchForm, { storedMember } from './SearchForm';

export interface Offer {
    room_type: { id: number; code: string; name: string; max_adults: number; amenities: string[] | null };
    rate_plan: { id: number; code: string; name: string; meal_plan: string | null; refundable: boolean; cancellation_hours: number | null; members_only: boolean; deposit_required: boolean; deposit_percent: number | null };
    rooms_left: number; total: number; currency: string; nights: number;
}
interface Results { member: { name: string } | null; results: { hotel: { code: string; name: string; city: string | null }; offers: Offer[] }[] }

export default function Search(p: PublicProps) {
    const d = t(p.lang);
    const q = p.query;
    const [data, setData] = useState<Results | null>(null);
    const [hotels, setHotels] = useState<{ code: string; name: string }[]>([]);
    const [error, setError] = useState('');

    useEffect(() => {
        publicApi<{ hotels: { code: string; name: string }[] }>(p.group.slug, '/hotels').then((r) => setHotels(r.hotels)).catch(() => {});
    }, [p.group.slug]);

    useEffect(() => {
        if (!q.arrival || !q.departure) return;
        const m = storedMember();
        const params = new URLSearchParams({ arrival: q.arrival, departure: q.departure, adults: q.adults ?? '1', children: q.children ?? '0' });
        if (q.hotel) params.append('hotel_codes[]', q.hotel);
        if (m.member && m.member_name) { params.set('member_number', m.member); params.set('member_last_name', m.member_name); }
        setData(null);
        setError('');
        publicApi<Results>(p.group.slug, `/availability?${params}`).then(setData).catch((e: Error) => setError(e.message));
    }, [q.arrival, q.departure, q.adults, q.children, q.hotel]);

    const choose = (hotel: string, o: Offer) => {
        try { sessionStorage.setItem('opes_offer', JSON.stringify({ hotel, offer: o })); } catch { /* storage unavailable */ }
        router.visit(url(p, '/details', { hotel, room_type: o.room_type.id, rate_plan: o.rate_plan.id, arrival: q.arrival, departure: q.departure, adults: q.adults, children: q.children }));
    };

    return (
        <PublicLayout p={p} title={d.search}>
            <section className="mb-8 rounded-lg border border-[#e5dfd0] bg-white p-5"><SearchForm p={p} hotels={hotels} /></section>
            {error && <Notice kind="error">{error}</Notice>}
            {q.arrival && !data && !error && <p className="text-sm text-slate-500">{d.loading}</p>}
            {data?.member && <p className="mb-4"><span className="badge-yellow">{d.membersOnly}: {data.member.name}</span></p>}
            <div className="space-y-8">
                {data?.results.map(({ hotel, offers }) => (
                    <section key={hotel.code}>
                        <h2 className="mb-3 font-serif text-2xl">{hotel.name} <span className="text-base text-slate-500">{hotel.city}</span></h2>
                        {offers.length === 0 && <p className="text-sm text-slate-600">{d.noOffers}</p>}
                        <ul className="space-y-3">
                            {offers.map((o) => (
                                <li key={`${o.room_type.id}-${o.rate_plan.id}`} className="flex flex-col gap-3 rounded-lg border border-[#e5dfd0] bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="space-y-1">
                                        <p className="text-lg font-semibold">{o.room_type.name}</p>
                                        <p className="text-sm text-slate-600">{o.rate_plan.name}{o.rate_plan.meal_plan ? ` · ${o.rate_plan.meal_plan}` : ''}</p>
                                        <div className="flex flex-wrap gap-2">
                                            <span className={o.rate_plan.refundable ? 'badge-green' : 'badge-slate'}>{o.rate_plan.refundable ? d.refundable : d.nonRefundable}</span>
                                            {o.rate_plan.members_only && <span className="badge-yellow">{d.membersOnly}</span>}
                                            {o.rate_plan.deposit_required && <span className="badge-blue">{d.depositRequired}</span>}
                                            {o.rooms_left <= 3 && <span className="badge-red">{o.rooms_left} {d.roomsLeft}</span>}
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 sm:flex-col sm:items-end">
                                        <p className="text-right"><span className="block text-xl font-semibold">{money(o.total, o.currency, p.lang)}</span><span className="text-sm text-slate-500">{o.nights} {d.nights}</span></p>
                                        <button type="button" onClick={() => choose(hotel.code, o)} className="rounded-md bg-[#b8923a] px-5 py-2 text-base font-semibold text-white hover:bg-[#a07e2f]">{d.select}</button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </PublicLayout>
    );
}
