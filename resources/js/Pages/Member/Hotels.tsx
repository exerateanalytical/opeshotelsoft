import { Link } from '@inertiajs/react';
import { Phone, MapPin } from 'lucide-react';
import { useT } from '@/lib/i18n';
import MemberLayout from './MemberLayout';

interface Hotel { code: string; name: string; city: string | null; country: string | null; address: string | null; phone: string | null; stars: number | null; description: string | null }
interface Data { accepted: boolean; earn: { spend_per_point: number; point_value: number } | null; perks: string[]; hotels: Hotel[] }

export default function Hotels({ data }: { data: Data }) {
    const t = useT();
    return (
        <MemberLayout title={t('member.hotels.title')}>
            <p className="text-slate-700">{t('member.hotels.intro')}</p>
            {data.earn && <p className="card p-4 text-sm">{t('member.hotels.earn', { spend: data.earn.spend_per_point.toLocaleString('fr-FR'), value: data.earn.point_value })}</p>}
            {data.perks.length > 0 && (
                <div className="card p-4 text-sm">
                    <p className="font-semibold mb-1">{t('member.hotels.perks')}</p>
                    <ul className="list-disc pl-5">{data.perks.map((p) => <li key={p}>{t(`member.benefit.${p}`) === `member.benefit.${p}` ? p.replace(/_/g, ' ') : t(`member.benefit.${p}`)}</li>)}</ul>
                </div>
            )}
            {data.hotels.map((h) => (
                <div key={h.code} className="card p-4 space-y-2">
                    <p className="font-semibold text-lg">{h.name} {h.stars ? <span aria-label={`${h.stars} ${t('member.hotels.stars')}`} className="text-brand-800">{'★'.repeat(h.stars)}</span> : null}</p>
                    {h.description && <p className="text-sm text-slate-700">{h.description}</p>}
                    <p className="text-sm flex items-center gap-2"><MapPin className="w-4 h-4" />{[h.address, h.city].filter(Boolean).join(', ')}</p>
                    {h.phone && <p className="text-sm flex items-center gap-2"><Phone className="w-4 h-4" /><a href={`tel:${h.phone}`}>{h.phone}</a></p>}
                    <Link href={`/account/book?hotel=${h.code}`} className="btn-primary inline-flex">{t('member.hotels.book_here')}</Link>
                </div>
            ))}
        </MemberLayout>
    );
}
