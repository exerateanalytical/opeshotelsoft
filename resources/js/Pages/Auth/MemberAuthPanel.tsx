import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useLocale, useT } from '@/lib/i18n';
import DemoSelect from '@/Components/auth/DemoSelect';
import { mapi, MemberApiError } from '../Member/api';

type Mode = 'password' | 'code' | 'code2' | 'register' | 'register2' | 'forgot' | 'forgot2';
interface Demo { n: number; name: string }

const field = 'input bg-navy-800 border-slate-500 text-white placeholder-slate-400 focus:border-brand-500';
const link = 'inline-flex items-center min-h-11 text-sm text-yellow-500 hover:text-yellow-400 underline-offset-2 hover:underline';

/** The "Member" tab of the login page: password or one-time-code sign-in, sign-up with a verified code, password reset. */
export default function MemberAuthPanel({ demo = [] }: { demo?: Demo[] }) {
    const t = useT();
    const locale = useLocale();
    const [mode, setMode] = useState<Mode>('password');
    const [f, setF] = useState({ identifier: '', password: '', code: '', first_name: '', last_name: '', password_confirmation: '', consent: false });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [info, setInfo] = useState('');
    const set = (k: keyof typeof f, v: string | boolean) => setF((x) => ({ ...x, [k]: v }));

    async function call(path: string, body: Record<string, unknown>, next?: Mode, notice?: string) {
        setBusy(true); setError(''); setInfo('');
        try {
            const r = await mapi<{ redirect?: string }>('POST', path, body);
            if (r.redirect) { router.visit(r.redirect); return; }
            if (next) setMode(next);
            if (notice) setInfo(notice);
        } catch (e) { setError(e instanceof MemberApiError ? e.message : String(e)); }
        setBusy(false);
    }
    const go = (m: Mode) => { setMode(m); setError(''); setInfo(''); };
    const id = f.identifier.trim();

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        switch (mode) {
            case 'password': return call('/account/login', { identifier: id, password: f.password });
            case 'code': return call('/account/login/code', { identifier: id }, 'code2', t('member.auth.code_sent'));
            case 'code2': return call('/account/login/code/verify', { identifier: id, code: f.code });
            case 'register': return call('/account/register', { contact: id, first_name: f.first_name, last_name: f.last_name, locale, marketing_consent: f.consent }, 'register2', t('member.auth.code_sent'));
            case 'register2': return call('/account/register/verify', { contact: id, code: f.code, password: f.password, password_confirmation: f.password_confirmation });
            case 'forgot': return call('/account/forgot', { identifier: id }, 'forgot2', t('member.auth.code_sent'));
            case 'forgot2': return call('/account/reset', { identifier: id, code: f.code, password: f.password, password_confirmation: f.password_confirmation }, 'password', t('member.auth.reset_done'));
        }
    };
    const needsId = ['password', 'code', 'register', 'forgot'].includes(mode);
    const needsCode = ['code2', 'register2', 'forgot2'].includes(mode);
    const needsPassword = ['password', 'register2', 'forgot2'].includes(mode);
    const confirmPassword = ['register2', 'forgot2'].includes(mode);

    return (
        <div>
            <h1 className="text-2xl font-bold text-white mb-1.5">{t(`member.auth.title.${mode.replace(/2$/, '')}`)}</h1>
            <p className="text-slate-400 text-sm mb-6">{t(`member.auth.subtitle.${mode}`)}</p>
            <form onSubmit={submit} className="space-y-4" noValidate>
                {mode === 'register' && (
                    <div className="grid grid-cols-2 gap-3">
                        <label className="block"><span className="label text-slate-300">{t('member.auth.first_name')}</span><input className={field} value={f.first_name} autoComplete="given-name" onChange={(e) => set('first_name', e.target.value)} /></label>
                        <label className="block"><span className="label text-slate-300">{t('member.auth.last_name')}</span><input className={field} value={f.last_name} autoComplete="family-name" onChange={(e) => set('last_name', e.target.value)} /></label>
                    </div>
                )}
                {needsId && (
                    <label className="block"><span className="label text-slate-300">{t('member.auth.identifier')}</span>
                        <input className={field} value={f.identifier} inputMode="email" autoComplete="username" placeholder={t('member.auth.identifier_ph')} onChange={(e) => set('identifier', e.target.value)} /></label>
                )}
                {needsCode && (
                    <label className="block"><span className="label text-slate-300">{t('member.auth.code')}</span>
                        <input className={field} value={f.code} inputMode="numeric" autoComplete="one-time-code" maxLength={6} onChange={(e) => set('code', e.target.value.replace(/\D/g, ''))} /></label>
                )}
                {needsPassword && (
                    <label className="block"><span className="label text-slate-300">{mode === 'password' ? t('auth.password') : t('member.auth.new_password')}</span>
                        <input type="password" className={field} value={f.password} autoComplete={mode === 'password' ? 'current-password' : 'new-password'} onChange={(e) => set('password', e.target.value)} /></label>
                )}
                {confirmPassword && (
                    <>
                        <label className="block"><span className="label text-slate-300">{t('member.auth.confirm_password')}</span>
                            <input type="password" className={field} value={f.password_confirmation} autoComplete="new-password" onChange={(e) => set('password_confirmation', e.target.value)} /></label>
                        <p className="text-sm text-slate-400">{t('member.auth.password_rule')}</p>
                    </>
                )}
                {mode === 'register' && (
                    <label className="flex items-start gap-2 text-sm text-slate-400"><input type="checkbox" className="mt-0.5 w-5 h-5 shrink-0 accent-brand-500" checked={f.consent} onChange={(e) => set('consent', e.target.checked)} />{t('member.profile.consent')}</label>
                )}
                {info && <p role="status" className="text-sm text-emerald-400">{info}</p>}
                {error && <p role="alert" className="text-sm text-red-400">{error}</p>}
                <button type="submit" disabled={busy} className="btn-accent w-full min-h-12 text-base">
                    {busy && <Loader2 className="w-4 h-4 animate-spin" />}{t(`member.auth.submit.${mode}`)}
                </button>
            </form>

            <div className="mt-3 flex flex-wrap gap-x-5">
                {mode !== 'password' && <button type="button" className={link} onClick={() => go('password')}>{t('member.auth.use_password')}</button>}
                {mode === 'password' && <button type="button" className={link} onClick={() => go('code')}>{t('member.auth.use_code')}</button>}
                {mode === 'password' && <button type="button" className={link} onClick={() => go('forgot')}>{t('auth.forgot')}</button>}
                {mode !== 'register' && mode !== 'register2' && <button type="button" className={link} onClick={() => go('register')}>{t('member.auth.create')}</button>}
            </div>

            {demo.length > 0 && (
                <div className="mt-8 pt-6 border-t border-slate-600">
                    <p className="text-sm font-semibold uppercase tracking-widest text-yellow-500 mb-1">{t('member.auth.demo_title')}</p>
                    <p className="text-sm text-slate-400 mb-4">{t('member.auth.demo_text')}</p>
                    <DemoSelect options={demo.map((a) => ({ value: String(a.n), label: a.name }))}
                        endpoint={(v) => `/demo-member-login/${v}`} label={t('member.auth.demo_select')} placeholder={t('member.auth.demo_placeholder')} />
                </div>
            )}
        </div>
    );
}
