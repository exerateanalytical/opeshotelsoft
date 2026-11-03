import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale, useT } from '@/lib/i18n';
import ConfirmSheet from './ConfirmSheet';
import MemberLayout from './MemberLayout';
import { fmtDate, mapi, MemberApiError } from './api';

interface Data {
    can_join: boolean;
    member: { number: string; name: string; points: number; tier: { name: string; benefits: string[] }; next_tier: { name: string; points_needed: number } | null } | null;
    card: { id: number; card_number: string; kind: string; status: string; usable: boolean; expires_on: string | null; qr_svg: string } | null;
}

export default function Card({ data }: { data: Data }) {
    const t = useT();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [ask, setAsk] = useState<{ message: string; path: string; danger?: boolean } | null>(null);
    const m = data.member;
    const c = data.card;

    async function act(path: string) {
        setBusy(true); setError('');
        try { await mapi('POST', path); router.reload(); } catch (e) { setError(e instanceof MemberApiError ? e.message : String(e)); }
        setBusy(false);
    }

    if (!m || !c) {
        return (
            <MemberLayout title={t('member.card.title')}>
                <div className="card p-4 space-y-3">
                    <p>{t('member.card.none')}</p>
                    {data.can_join && <button type="button" className="btn-primary" disabled={busy} onClick={() => act('/account/card/join')}>{t('member.card.join')}</button>}
                    {error && <p role="alert" className="text-red-700 text-sm">{error}</p>}
                </div>
            </MemberLayout>
        );
    }
    const status = c.usable ? 'active' : c.status === 'active' ? 'expired' : c.status;
    return (
        <MemberLayout title={t('member.card.title')}>
            <div className="rounded-xl border border-navy-900 bg-navy-900 text-white p-5 space-y-4">
                <div className="flex justify-between items-start gap-3">
                    <div>
                        <p className="text-sm text-slate-300">{t('member.card.tier')}</p>
                        <p className="text-2xl font-bold">{m.tier.name}</p>
                    </div>
                    <span className={`px-2.5 py-0.5 rounded text-sm font-semibold ${status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'}`}>{t(`member.card.status.${status}`)}</span>
                </div>
                <div className="bg-white rounded-lg p-3 mx-auto w-fit" aria-label={t('member.card.qr')} dangerouslySetInnerHTML={{ __html: c.qr_svg.replace(/width="\d+" height="\d+"/, 'width="200" height="200"') }} />
                <div>
                    <p className="text-lg font-semibold">{m.name}</p>
                    <p className="font-mono tracking-wider">{c.card_number.replace(/(\d{4})(?=\d)/g, '$1 ')}</p>
                    <p className="text-sm text-slate-300">{t('member.card.member_no')} {m.number}{c.expires_on ? ` · ${t('member.card.expires')} ${fmtDate(c.expires_on, locale)}` : ''}</p>
                </div>
            </div>

            <div className="card p-4">
                <p className="font-semibold mb-2">{t('member.card.benefits')}</p>
                <ul className="list-disc pl-5 text-sm space-y-1">{m.tier.benefits.map((b) => <li key={b}>{t(`member.benefit.${b}`) === `member.benefit.${b}` ? b.replace(/_/g, ' ') : t(`member.benefit.${b}`)}</li>)}</ul>
                {m.next_tier && <p className="text-sm text-slate-600 mt-3">{t('member.home.next_tier', { name: m.next_tier.name, points: m.next_tier.points_needed })}</p>}
            </div>

            <div className="flex flex-wrap gap-2">
                <a className="btn-secondary" href={`/account/card/${c.id}/pdf?lang=${locale}`} target="_blank" rel="noreferrer">{t('member.card.pdf')}</a>
                {c.status === 'active' && <button type="button" className="btn-danger" disabled={busy} onClick={() => setAsk({ message: t('member.card.block_confirm'), path: `/account/card/${c.id}/block`, danger: true })}>{t('member.card.block')}</button>}
                <button type="button" className="btn-secondary" disabled={busy} onClick={() => setAsk({ message: t('member.card.replace_confirm'), path: `/account/card/${c.id}/replace` })}>{t('member.card.replace')}</button>
            </div>
            {c.status === 'blocked' && <p className="text-sm text-slate-600">{t('member.card.blocked_help')}</p>}
            {error && <p role="alert" className="text-red-700 text-sm">{error}</p>}
            {ask && <ConfirmSheet message={ask.message} danger={ask.danger} onCancel={() => setAsk(null)} onConfirm={() => { const p = ask.path; setAsk(null); act(p); }} />}
        </MemberLayout>
    );
}
