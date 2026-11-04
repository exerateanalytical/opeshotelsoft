import { Link } from '@inertiajs/react';
import { Phone, MapPin, Star } from 'lucide-react';
import { useState } from 'react';
import { useLocale, useT } from '@/lib/i18n';
import { formatNumber } from '@/lib/utils';
import MemberLayout from './MemberLayout';

interface Hotel { code: string; name: string; city: string | null; country: string | null; address: string | null; phone: string | null; stars: number | null; description: string | null; photo?: string | null }
interface Data { accepted: boolean; earn: { spend_per_point: number; point_value: number } | null; perks: string[]; hotels: Hotel[] }

/** Up to two initials of the hotel name (skips small words), for the flat monogram tile. */
const monogram = (name: string) => {
    const words = name.split(/\s+/).filter((w) => w.length > 2 || /^[A-Z]/.test(w));
    return (words.length ? words : name.split(/\s+/)).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
};

/** Fixed 16:9 frame: the photo when there is one (cover, never stretched), otherwise a flat tile with the initials and the city. */
function HotelImage({ h, alt }: { h: Hotel; alt: string }) {
    const [failed, setFailed] = useState(false);
    if (h.photo && !failed) {
        return <img src={h.photo} alt={alt} loading="lazy" onError={() => setFailed(true)} className="aspect-video w-full rounded-md object-cover" />;
    }
    return (
        <div className="flex aspect-video w-full flex-col items-center justify-center rounded-md bg-navy-900 text-white" role="img" aria-label={alt}>
            <span className="text-4xl font-bold tracking-wide">{monogram(h.name)}</span>
            {h.city && <span className="mt-1 text-sm text-slate-200">{h.city}</span>}
        </div>
    );
}

export default function Hotels({ data }: { data: Data }) {
    const t = useT();
    const locale = useLocale();
    return (
        <MemberLayout title={t('member.hotels.title')}>
            <p className="text-slate-700">{t('member.hotels.intro')}</p>
            {data.earn && <p className="card p-4 text-sm">{t('member.hotels.earn', { spend: formatNumber(data.earn.spend_per_point, { locale }), value: formatNumber(data.earn.point_value, { locale }) })}</p>}
            {data.perks.length > 0 && (
                <div className="card p-4 text-sm">
                    <p className="font-semibold mb-1">{t('member.hotels.perks')}</p>
                    <ul className="list-disc pl-5">{data.perks.map((p) => <li key={p}>{t(`member.benefit.${p}`) === `member.benefit.${p}` ? p.replace(/_/g, ' ') : t(`member.benefit.${p}`)}</li>)}</ul>
                </div>
            )}
            {data.hotels.map((h) => (
                <div key={h.code} className="card p-4 space-y-3">
                    <HotelImage h={h} alt={t('member.hotels.photo_alt', { name: h.name })} />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-semibold text-lg">{h.name}</p>
                        <p className="flex items-center gap-1.5 text-sm text-slate-700">
                            <Star className="h-4 w-4" aria-hidden="true" />
                            {h.stars ? t(h.stars === 1 ? 'member.hotels.stars_one' : 'member.hotels.stars_other', { n: h.stars }) : t('member.hotels.unrated')}
                        </p>
                    </div>
                    {h.description && <p className="text-sm text-slate-700">{h.description}</p>}
                    <p className="text-sm flex items-center gap-2"><MapPin className="w-4 h-4" aria-hidden="true" />{[h.address, h.city].filter(Boolean).join(', ')}</p>
                    {h.phone && <p className="text-sm flex items-center gap-2"><Phone className="w-4 h-4" aria-hidden="true" /><a href={`tel:${h.phone}`}>{h.phone}</a></p>}
                    <Link href={`/account/book?hotel=${h.code}`} className="btn-primary inline-flex">{t('member.hotels.book_here')}</Link>
                </div>
            ))}
        </MemberLayout>
    );
}
