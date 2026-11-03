import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { t } from './i18n';
import PublicLayout, { Notice, publicApi, url, type PublicProps } from './PublicLayout';
import SearchForm from './SearchForm';

export interface PublicHotel {
    id: number; code: string; name: string; city: string | null; country: string; stars: number | null; phone: string | null;
    address: string | null; check_in_time: string; description: string | null; photos: string[];
    room_types: { id: number; name: string; description: string | null; max_adults: number }[];
}

export default function Hotels(p: PublicProps) {
    const d = t(p.lang);
    const [hotels, setHotels] = useState<PublicHotel[] | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        publicApi<{ hotels: PublicHotel[] }>(p.group.slug, '/hotels').then((r) => setHotels(r.hotels)).catch(() => setError(d.error));
    }, [p.group.slug]);

    return (
        <PublicLayout p={p} title={d.ourHotels}>
            <section className="mb-8 rounded-lg border border-slate-200 bg-white p-5">
                <SearchForm p={p} hotels={hotels ?? []} />
            </section>
            {error && <Notice kind="error">{error}</Notice>}
            {!hotels && !error && <p className="text-sm text-slate-500">{d.loading}</p>}
            <div className="grid gap-6 md:grid-cols-2">
                {hotels?.map((h) => (
                    <article key={h.id} className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                        {h.photos[0] && <img src={h.photos[0]} alt={h.name} className="h-48 w-full object-cover" />}
                        <div className="space-y-2 p-5">
                            <h2 className="font-serif text-2xl">{h.name}</h2>
                            <p className="text-sm text-slate-600">
                                {[h.address, h.city].filter(Boolean).join(', ')}
                                {h.stars ? <span className="ml-2 text-brand-800">{'★'.repeat(h.stars)}</span> : null}
                            </p>
                            {h.description && <p className="text-base text-slate-700">{h.description}</p>}
                            <p className="text-sm text-slate-600">{h.room_types.map((r) => r.name).join(' · ')}</p>
                            <Link href={url(p, '/search', { hotel: h.code })} className="inline-block btn-accent min-h-11 px-6">
                                {d.book}
                            </Link>
                        </div>
                    </article>
                ))}
            </div>
        </PublicLayout>
    );
}
