import { CalendarClock } from 'lucide-react';
import { useLocale, useT } from '@/lib/i18n';
import MemberLayout from './MemberLayout';
import { fmtDate, money } from './api';
import { formatNumber } from '@/lib/utils';
import Alert from '@/Components/ui/Alert';

interface Line { id: number; date: string; kind: string; description: string; points: number; balance: number; expires_on: string | null }
interface Data {
    member: { points: number; points_value: number; tier: { name: string } } | null;
    from: string; to: string; opening: number; earned: number; spent: number; closing: number; lines: Line[]; expiring: number; expiring_on: string | null;
    earn: { spend_per_point: number; point_value: number; expiry_months: number; multiplier: number } | null;
}

export default function Points({ data }: { data: Data }) {
    const t = useT();
    const locale = useLocale();
    if (!data.member) {
        return <MemberLayout title={t('member.points.title')}><p className="card p-4">{t('member.card.none')}</p></MemberLayout>;
    }
    return (
        <MemberLayout title={t('member.points.title')}>
            <div className="card p-4">
                <p className="text-sm text-slate-500">{t('member.points.balance')}</p>
                <p className="text-3xl font-bold">{formatNumber(data.member.points, { locale })} <span className="text-lg font-medium">{t('member.points_unit')}</span></p>
                <p className="text-sm text-slate-600">≈ {money(data.member.points_value)}</p>
                {data.expiring > 0 && <Alert variant="warning" icon={CalendarClock} className="mt-2">{t('member.points.expiring', { points: formatNumber(data.expiring, { locale }), date: data.expiring_on ? fmtDate(data.expiring_on, locale) : '' })}</Alert>}
            </div>

            {data.earn && (
                <div className="card p-4 text-sm space-y-1">
                    <p className="font-semibold">{t('member.points.how')}</p>
                    <p>{t('member.points.how_earn', { spend: formatNumber(data.earn.spend_per_point, { locale }), mult: formatNumber(data.earn.multiplier, { locale }) })}</p>
                    <p>{t('member.points.how_use', { value: formatNumber(data.earn.point_value, { locale }) })}</p>
                    <p>{t('member.points.how_expiry', { months: data.earn.expiry_months })}</p>
                </div>
            )}

            <div className="card">
                <div className="flex items-center justify-between p-4 border-b border-slate-200">
                    <p className="font-semibold">{t('member.points.history')}</p>
                    <a className="btn-secondary" href={`/account/points/pdf?lang=${locale}`} target="_blank" rel="noreferrer">{t('member.points.pdf')}</a>
                </div>
                {data.lines.length === 0 && <p className="p-4 text-slate-600">{t('member.points.empty')}</p>}
                <ul className="divide-y divide-slate-100">
                    {data.lines.map((l) => (
                        <li key={l.id} className="flex items-center justify-between gap-3 px-4 py-3">
                            <div className="min-w-0">
                                <p className="truncate">{l.description}</p>
                                <p className="text-sm text-slate-500">{fmtDate(l.date, locale)}{l.expires_on && l.points > 0 ? ` · ${t('member.points.expires')} ${fmtDate(l.expires_on, locale)}` : ''}</p>
                            </div>
                            <p className={`font-semibold whitespace-nowrap ${l.points >= 0 ? 'text-emerald-700' : 'text-slate-700'}`}>{formatNumber(l.points, { locale, signed: true })}</p>
                        </li>
                    ))}
                </ul>
            </div>
        </MemberLayout>
    );
}
