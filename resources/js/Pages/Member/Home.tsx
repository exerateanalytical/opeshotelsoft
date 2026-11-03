import { Link } from '@inertiajs/react';
import { useLocale, useT } from '@/lib/i18n';
import MemberLayout, { MoreLinks } from './MemberLayout';
import { fmtDate, money } from './api';

interface Stay { number: string; status: string; arrival: string; departure: string; nights: number; hotel: { name: string; city: string } | null; room: string | null; room_type: string | null }
interface Data {
    name: string | null; full_name: string; can_join: boolean; current_stay: Stay | null; next_stay: Stay | null;
    member: { number: string; points: number; points_value: number; tier: { name: string }; next_tier: { name: string; points_needed: number } | null } | null;
}

function StayCard({ s, label }: { s: Stay; label: string }) {
    const t = useT();
    const locale = useLocale();
    return (
        <Link href="/account/bookings" className="card p-4 block">
            <p className="text-sm text-slate-500">{label}</p>
            <p className="font-semibold">{s.hotel?.name} <span className="text-slate-500 font-normal">· {s.hotel?.city}</span></p>
            <p className="text-sm">{fmtDate(s.arrival, locale)} → {fmtDate(s.departure, locale)} · {t('member.nights', { count: s.nights })}</p>
            {s.room && <p className="text-sm">{t('member.room')} {s.room}</p>}
        </Link>
    );
}

export default function Home({ data }: { data: Data }) {
    const t = useT();
    const m = data.member;
    return (
        <MemberLayout title={t('member.home.title')}>
            <h2 className="text-xl font-semibold">{t('member.home.hello', { name: data.name ?? '' })}</h2>

            {m ? (
                <Link href="/account/card" className="card p-4 block bg-navy-900 text-white border-navy-900">
                    <p className="text-sm text-slate-300">{t('member.home.tier')}</p>
                    <p className="text-2xl font-bold">{m.tier.name}</p>
                    <p className="mt-2 text-lg">{m.points.toLocaleString('fr-FR')} {t('member.points_unit')} <span className="text-slate-300 text-sm">≈ {money(m.points_value)}</span></p>
                    {m.next_tier && <p className="text-sm text-slate-300 mt-1">{t('member.home.next_tier', { name: m.next_tier.name, points: m.next_tier.points_needed })}</p>}
                </Link>
            ) : data.can_join && (
                <Link href="/account/card" className="card p-4 block"><p className="font-semibold">{t('member.home.join_title')}</p><p className="text-sm text-slate-600">{t('member.home.join_text')}</p></Link>
            )}

            {data.current_stay && <StayCard s={data.current_stay} label={t('member.home.current_stay')} />}
            {data.next_stay && <StayCard s={data.next_stay} label={t('member.home.next_stay')} />}
            {!data.current_stay && !data.next_stay && <p className="text-slate-600">{t('member.home.no_stay')}</p>}

            <div className="grid grid-cols-2 gap-2">
                <Link href="/account/book" className="btn-primary justify-center">{t('member.home.book')}</Link>
                <Link href="/account/chat" className="btn-secondary justify-center">{t('member.home.chat')}</Link>
                <Link href="/account/requests" className="btn-secondary justify-center">{t('member.home.request')}</Link>
                <Link href="/account/hotels" className="btn-secondary justify-center">{t('member.home.hotels')}</Link>
            </div>
            <MoreLinks />
        </MemberLayout>
    );
}
