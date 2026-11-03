import { Link } from '@inertiajs/react';
import { money, t } from './i18n';
import type { BookingResult } from './Details';
import PublicLayout, { url, type PublicProps } from './PublicLayout';

export default function Confirmation(p: PublicProps) {
    const d = t(p.lang);
    const c = p.query.c ?? '';
    let b: (BookingResult & { last_name: string }) | null = null;
    try { b = JSON.parse(sessionStorage.getItem(`opes_booking_${c}`) ?? 'null'); } catch { b = null; }

    return (
        <PublicLayout p={p} title={d.confirmed}>
            <div className="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-6">
                <p className="text-sm text-slate-500">{d.confirmationNo}</p>
                <p className="font-mono text-3xl font-semibold tracking-wider text-brand-800">{c}</p>
                <p className="text-base">{d.keepNumber}</p>
                {b && (
                    <dl className="grid grid-cols-2 gap-2 text-base">
                        <dt className="text-slate-500">{d.status}</dt><dd>{d.statuses[b.status] ?? b.status}</dd>
                        <dt className="text-slate-500">{d.total}</dt><dd>{money(b.total, b.currency, p.lang)}</dd>
                        {b.deposit_required > 0 && (<><dt className="text-slate-500">{d.depositDue}</dt><dd>{money(b.deposit_required, b.currency, p.lang)}</dd></>)}
                    </dl>
                )}
                <Link href={url(p, '/manage', { c })} className="inline-block btn-primary min-h-11 px-5">
                    {b && b.deposit_required > 0 ? d.payDeposit : d.manage}
                </Link>
            </div>
        </PublicLayout>
    );
}
