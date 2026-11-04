import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { t } from './i18n';
import { url, type PublicProps } from './PublicLayout';

const iso = (d: Date) => d.toISOString().slice(0, 10);

export function storedMember(): { member: string; member_name: string } {
    try {
        const v = JSON.parse(sessionStorage.getItem('opes_member') ?? '{}');
        return { member: v.member ?? '', member_name: v.member_name ?? '' };
    } catch {
        return { member: '', member_name: '' };
    }
}

/** Availability search form shared by the hotel list and search pages. */
export default function SearchForm({ p, hotels }: { p: PublicProps; hotels: { code: string; name: string }[] }) {
    const d = t(p.lang);
    const q = p.query;
    const today = new Date();
    const [f, setF] = useState({
        hotel: q.hotel ?? '', arrival: q.arrival ?? iso(new Date(today.getTime() + 86400000)),
        departure: q.departure ?? iso(new Date(today.getTime() + 2 * 86400000)), adults: q.adults ?? '2', children: q.children ?? '0',
        ...storedMember(),
    });
    const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const { member, member_name, ...rest } = f;
        // Member credentials stay out of the URL; kept for this tab only.
        try { sessionStorage.setItem('opes_member', JSON.stringify({ member, member_name })); } catch { /* storage unavailable */ }
        router.visit(url(p, '/search', rest));
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 md:grid-cols-6">
            <div className="col-span-2">
                <label className="label" htmlFor="hotel">{d.ourHotels}</label>
                <select id="hotel" className="select" value={f.hotel} onChange={set('hotel')}>
                    <option value="">—</option>
                    {hotels.map((h) => <option key={h.code} value={h.code}>{h.name}</option>)}
                </select>
            </div>
            <div>
                <label className="label" htmlFor="arrival">{d.checkIn}</label>
                <input id="arrival" type="date" className="input" min={iso(today)} value={f.arrival} onChange={set('arrival')} required />
            </div>
            <div>
                <label className="label" htmlFor="departure">{d.checkOut}</label>
                <input id="departure" type="date" className="input" min={f.arrival} value={f.departure} onChange={set('departure')} required />
            </div>
            <div>
                <label className="label" htmlFor="adults">{d.adults}</label>
                <input id="adults" type="number" min={1} max={8} className="input" value={f.adults} onChange={set('adults')} />
            </div>
            <div>
                <label className="label" htmlFor="children">{d.children}</label>
                <input id="children" type="number" min={0} max={6} className="input" value={f.children} onChange={set('children')} />
            </div>
            <div className="col-span-2 md:col-span-3">
                <label className="label" htmlFor="member">{d.memberNumber}</label>
                <input id="member" className="input" value={f.member} onChange={set('member')} />
            </div>
            <div className="col-span-2 md:col-span-2">
                <label className="label" htmlFor="member_name">{d.lastName}</label>
                <input id="member_name" className="input" value={f.member_name} onChange={set('member_name')} />
            </div>
            <div className="col-span-2 flex items-end md:col-span-1">
                <button type="submit" className="w-full btn-primary min-h-11 px-5">{d.search}</button>
            </div>
        </form>
    );
}
