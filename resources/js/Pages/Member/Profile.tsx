import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';
import MemberLayout from './MemberLayout';
import { mapi, MemberApiError } from './api';

interface Data { first_name: string; last_name: string; email: string | null; phone: string | null; locale: 'fr' | 'en'; marketing_consent: boolean; member_number: string | null }

export default function Profile({ data }: { data: Data }) {
    const t = useT();
    const [consent, setConsent] = useState(data.marketing_consent);
    const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    async function run(fn: () => Promise<unknown>, ok: string, after?: () => void) {
        setBusy(true); setMsg(null);
        try { await fn(); setMsg({ ok: true, text: ok }); after?.(); } catch (e) { setMsg({ ok: false, text: e instanceof MemberApiError ? e.message : String(e) }); }
        setBusy(false);
    }

    return (
        <MemberLayout title={t('member.profile.title')}>
            <div className="card p-4 space-y-1">
                <p className="text-lg font-semibold">{data.first_name} {data.last_name}</p>
                {data.member_number && <p className="text-sm">{t('member.card.member_no')} {data.member_number}</p>}
                {data.email && <p className="text-sm">{data.email}</p>}
                {data.phone && <p className="text-sm">+{data.phone.replace(/^\+/, '')}</p>}
                <p className="text-sm text-slate-500">{t('member.profile.contact_help')}</p>
            </div>

            <div className="card p-4 space-y-3">
                <div className="flex gap-2" role="group" aria-label={t('member.profile.language')}>
                    {(['fr', 'en'] as const).map((l) => (
                        <button key={l} type="button" aria-pressed={data.locale === l} className={data.locale === l ? 'btn-primary' : 'btn-secondary'}
                            onClick={() => run(() => mapi('POST', '/account/profile', { locale: l }), t('member.profile.saved'), () => router.visit('/account/profile'))}>{l === 'fr' ? 'Français' : 'English'}</button>
                    ))}
                </div>
                <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-0.5 w-5 h-5 shrink-0 accent-navy-800" checked={consent} onChange={(e) => { setConsent(e.target.checked); run(() => mapi('POST', '/account/profile', { marketing_consent: e.target.checked }), t('member.profile.saved')); }} />
                    <span>{t('member.profile.consent')}</span>
                </label>
            </div>

            <form className="card p-4 space-y-3" onSubmit={(e) => { e.preventDefault(); run(() => mapi('POST', '/account/profile/password', pw), t('member.profile.password_changed'), () => setPw({ current_password: '', password: '', password_confirmation: '' })); }}>
                <p className="font-semibold">{t('member.profile.password')}</p>
                <label className="block text-sm">{t('member.profile.current_password')}<input type="password" autoComplete="current-password" className="input" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} /></label>
                <label className="block text-sm">{t('member.auth.new_password')}<input type="password" autoComplete="new-password" className="input" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} /></label>
                <label className="block text-sm">{t('member.auth.confirm_password')}<input type="password" autoComplete="new-password" className="input" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} /></label>
                <p className="text-sm text-slate-500">{t('member.auth.password_rule')}</p>
                <button className="btn-primary" disabled={busy || !pw.password}>{t('member.profile.change_password')}</button>
            </form>
            {msg && <p role="alert" className={`text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
        </MemberLayout>
    );
}
