import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Languages, ShieldCheck, UserCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { setLocale, useLocale, useT, type Locale } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface TwoFactorState {
    enabled: boolean;
    required: boolean;
    pending: boolean;
    secret: string | null;
    otpauth: string | null;
    remaining_codes: number;
    recovery_codes: string[] | null;
}

interface Props {
    profile: { name: string; email: string; roles: string[] };
    twoFactor: TwoFactorState;
}

function FieldError({ msg }: { msg?: string }) {
    return msg ? <p className="text-xs text-red-600 mt-1">{msg}</p> : null;
}

function TwoFactorCard({ state }: { state: TwoFactorState }) {
    const t = useT();
    const confirm = useForm({ code: '' });
    const disable = useForm({ current_password: '' });
    const opts = { preserveScroll: true };

    return (
        <div className="card p-5 space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="flex items-center gap-2 font-semibold text-slate-900"><ShieldCheck className="w-4 h-4" /> {t('profile.2fa.title')}</h2>
                <span className={state.enabled ? 'badge-green' : 'badge-slate'}>{state.enabled ? t('profile.2fa.on') : t('profile.2fa.off')}</span>
                {state.required && <span className="badge-yellow">{t('profile.2fa.required')}</span>}
            </div>

            {state.recovery_codes && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <h3 className="text-sm font-semibold text-amber-900">{t('profile.2fa.recovery_title')}</h3>
                    <p className="text-sm text-amber-800 mt-1 mb-3">{t('profile.2fa.recovery_text')}</p>
                    <div className="grid grid-cols-2 gap-2 font-mono text-sm text-slate-900">
                        {state.recovery_codes.map((c) => <span key={c}>{c}</span>)}
                    </div>
                </div>
            )}

            {!state.enabled && !state.pending && (
                <>
                    <p className="text-sm text-slate-600">{t('profile.2fa.intro')}</p>
                    <button type="button" className="btn-primary" onClick={() => router.post('/profile/two-factor', {}, opts)}>{t('profile.2fa.start')}</button>
                </>
            )}

            {state.pending && state.secret && (
                <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); confirm.post('/profile/two-factor/confirm', { ...opts, onSuccess: () => confirm.reset() }); }}>
                    <p className="text-sm text-slate-600">{t('profile.2fa.scan')}</p>
                    <div>
                        <span className="label">{t('profile.2fa.secret')}</span>
                        <p className="font-mono text-sm text-slate-900 break-all bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 select-all">{state.secret.match(/.{1,4}/g)?.join(' ')}</p>
                    </div>
                    {state.otpauth && (
                        <div>
                            <span className="label">{t('profile.2fa.uri')}</span>
                            <a href={state.otpauth} className="text-sm text-blue-700 break-all hover:underline">{state.otpauth}</a>
                        </div>
                    )}
                    <div className="max-w-xs">
                        <label className="label" htmlFor="totp">{t('profile.2fa.code')}</label>
                        <input id="totp" className="input w-full font-mono" inputMode="numeric" autoComplete="one-time-code" maxLength={6}
                            value={confirm.data.code} onChange={(e) => confirm.setData('code', e.target.value)} />
                        <FieldError msg={confirm.errors.code} />
                    </div>
                    <button type="submit" className="btn-primary" disabled={confirm.processing || confirm.data.code.length < 6}>{t('profile.2fa.confirm')}</button>
                </form>
            )}

            {state.enabled && (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-sm text-slate-600">{t('profile.2fa.remaining', { count: state.remaining_codes })}</p>
                        <button type="button" className="btn-secondary btn-sm" onClick={() => router.post('/profile/two-factor/recovery-codes', {}, opts)}>{t('profile.2fa.regenerate')}</button>
                    </div>
                    {!state.required && (
                        <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); disable.delete('/profile/two-factor', { ...opts, onSuccess: () => disable.reset() }); }}>
                            <div className="w-full sm:w-64">
                                <label className="label" htmlFor="disable-pw">{t('profile.2fa.disable_password')}</label>
                                <input id="disable-pw" type="password" autoComplete="current-password" className="input w-full"
                                    value={disable.data.current_password} onChange={(e) => disable.setData('current_password', e.target.value)} />
                                <FieldError msg={disable.errors.current_password} />
                            </div>
                            <button type="submit" className="btn-secondary" disabled={disable.processing || !disable.data.current_password}>{t('profile.2fa.disable')}</button>
                        </form>
                    )}
                </div>
            )}
        </div>
    );
}

export default function ProfileIndex({ profile, twoFactor }: Props) {
    const t = useT();
    const locale = useLocale();
    const flash = usePage<PageProps>().props.flash;
    const info = useForm({ name: profile.name });
    const pw = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveInfo = (e: React.FormEvent) => {
        e.preventDefault();
        info.post('/profile', { preserveScroll: true });
    };
    const savePw = (e: React.FormEvent) => {
        e.preventDefault();
        pw.post('/profile/password', { preserveScroll: true, onSuccess: () => pw.reset() });
    };

    return (
        <AppLayout title={t('profile.title')} subtitle={profile.email}>
            <Head title={t('profile.title')} />
            <div className="max-w-2xl space-y-6">
                {flash?.success && <div className="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm px-4 py-3">{flash.success}</div>}
                {flash?.error && <div className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">{flash.error}</div>}

                <form onSubmit={saveInfo} className="card p-5 space-y-4">
                    <h2 className="flex items-center gap-2 font-semibold text-slate-900"><UserCircle className="w-4 h-4" /> {t('profile.account')}</h2>
                    <div>
                        <label className="label" htmlFor="profile-name">{t('profile.name')}</label>
                        <input id="profile-name" className="input w-full" value={info.data.name} onChange={(e) => info.setData('name', e.target.value)} />
                        <FieldError msg={info.errors.name} />
                    </div>
                    <div>
                        <label className="label">{t('profile.email')}</label>
                        <p className="text-sm text-slate-800 break-all">{profile.email}</p>
                    </div>
                    <div>
                        <label className="label">{t('profile.roles')}</label>
                        <div className="flex flex-wrap gap-1.5">
                            {profile.roles.length === 0 && <span className="text-sm text-slate-500">{t('common.none')}</span>}
                            {profile.roles.map((r) => <span key={r} className="badge-slate">{r}</span>)}
                        </div>
                    </div>
                    <button type="submit" className="btn-primary" disabled={info.processing}>{t('common.save')}</button>
                </form>

                <div className="card p-5 space-y-3">
                    <h2 className="flex items-center gap-2 font-semibold text-slate-900"><Languages className="w-4 h-4" /> {t('profile.language')}</h2>
                    <select className="input w-full sm:w-64" value={locale} onChange={(e) => setLocale(e.target.value as Locale)} aria-label={t('profile.language')}>
                        <option value="fr">Français</option>
                        <option value="en">English</option>
                    </select>
                </div>

                <TwoFactorCard state={twoFactor} />

                <form onSubmit={savePw} className="card p-5 space-y-4">
                    <h2 className="flex items-center gap-2 font-semibold text-slate-900"><KeyRound className="w-4 h-4" /> {t('profile.password.title')}</h2>
                    <div>
                        <label className="label" htmlFor="pw-current">{t('profile.password.current')}</label>
                        <input id="pw-current" type="password" autoComplete="current-password" className="input w-full" value={pw.data.current_password} onChange={(e) => pw.setData('current_password', e.target.value)} />
                        <FieldError msg={pw.errors.current_password} />
                    </div>
                    <div className="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label className="label" htmlFor="pw-new">{t('profile.password.new')}</label>
                            <input id="pw-new" type="password" autoComplete="new-password" className="input w-full" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} />
                            <FieldError msg={pw.errors.password} />
                        </div>
                        <div>
                            <label className="label" htmlFor="pw-confirm">{t('profile.password.confirm')}</label>
                            <input id="pw-confirm" type="password" autoComplete="new-password" className="input w-full" value={pw.data.password_confirmation} onChange={(e) => pw.setData('password_confirmation', e.target.value)} />
                        </div>
                    </div>
                    <button type="submit" className="btn-primary" disabled={pw.processing}>{t('profile.password.title')}</button>
                </form>
            </div>
        </AppLayout>
    );
}
