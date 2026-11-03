import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { LanguageSwitch } from '@/Components/layout/Topbar';
import MemberAuthPanel from './MemberAuthPanel';
import BrandLogo from '@/Components/ui/BrandLogo';
import DemoSelect from '@/Components/auth/DemoSelect';

interface DemoAccount { login: string; name: string; section: string }

export default function Login({ demoAccounts = [], memberDemoAccounts = [], tab: initialTab = 'staff' }: { demoAccounts?: DemoAccount[]; memberDemoAccounts?: { n: number; name: string }[]; tab?: 'staff' | 'member' }) {
    const t = useT();
    const [tab, setTab] = useState<'staff' | 'member'>(initialTab);
    const [showPass, setShowPass] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <>
            <Head title={t('auth.title')} />
            <div className="min-h-screen flex bg-navy-900">
                {/* Left panel — branding */}
                <div className="hidden lg:flex flex-col justify-between w-[480px] bg-navy-950 p-12 relative overflow-hidden">

                    <div className="relative">
                        <div className="mb-12">
                            <div className="inline-block rounded-lg bg-white p-4"><BrandLogo variant="wordmark" size={280} /></div>
                            <p className="text-slate-400 text-sm mt-3">{t('auth.tagline')}</p>
                        </div>

                        <h1 className="text-4xl font-bold text-white leading-tight mb-4">
                            {t('auth.hero')}
                        </h1>
                        <p className="text-slate-400 text-base leading-relaxed">
                            {t('auth.hero_text')}
                        </p>
                    </div>

                    {/* Hotel names */}
                    <div className="relative">
                        <p className="text-slate-400 text-xs font-semibold uppercase tracking-widest mb-4">{t('auth.trusted_by')}</p>
                        <div className="flex flex-col gap-2">
                            {['Krystal Palace Hotel', 'Akwa Palace Hotel', 'Guims Group', 'Hilton Subsidiaries'].map(n => (
                                <div key={n} className="flex items-center gap-2">
                                    <div className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                                    <span className="text-slate-300 text-sm">{n}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Right panel — form */}
                <div className="flex-1 flex items-center justify-center p-4 sm:p-6">
                    <div className="w-full max-w-[400px]">
                        <div className="flex justify-end mb-6"><LanguageSwitch dark /></div>
                        {/* Mobile logo */}
                        <div className="flex lg:hidden justify-center mb-8">
                            <div className="rounded-lg bg-white p-3"><BrandLogo variant="wordmark" size={220} /></div>
                        </div>

                        <div role="tablist" aria-label={t('member.auth.tabs')} className="grid grid-cols-2 gap-1 p-1 mb-6 rounded-lg bg-navy-800 border border-slate-600">
                            {(['staff', 'member'] as const).map((k) => (
                                <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
                                    className={`min-h-11 rounded-md text-sm font-semibold ${tab === k ? 'bg-brand-500 text-navy-900' : 'text-slate-300 active:bg-navy-700'}`}>
                                    {t(`member.auth.tab.${k}`)}
                                </button>
                            ))}
                        </div>
                        {tab === 'member' ? <MemberAuthPanel demo={memberDemoAccounts} /> : (<>
                        <h1 className="text-2xl font-bold text-white mb-1.5">{t('auth.welcome')}</h1>
                        <p className="text-slate-400 text-sm mb-8">{t('auth.subtitle')}</p>

                        <form onSubmit={submit} className="space-y-5">
                            {/* Email */}
                            <div>
                                <label htmlFor="login-email" className="label text-slate-300">{t('auth.email')}</label>
                                <input
                                    id="login-email"
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    placeholder="you@hotel.com"
                                    autoComplete="email"
                                    className={cn(
                                        'input bg-navy-800 border-slate-600 text-white placeholder-slate-400',
                                        'focus:border-yellow-500',
                                        errors.email && 'border-red-500',
                                    )}
                                />
                                {errors.email && <p className="text-red-400 text-sm mt-1">{errors.email}</p>}
                            </div>

                            {/* Password */}
                            <div>
                                <label htmlFor="login-password" className="label text-slate-300">{t('auth.password')}</label>
                                <div className="relative">
                                    <input
                                        id="login-password"
                                        type={showPass ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        placeholder="••••••••"
                                        autoComplete="current-password"
                                        className={cn(
                                            'input bg-navy-800 border-slate-600 text-white placeholder-slate-400 pr-12',
                                            'focus:border-yellow-500',
                                            errors.password && 'border-red-500',
                                        )}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPass(!showPass)}
                                        aria-label={t('auth.show_password')}
                                        className="absolute right-0 top-0 h-full min-w-11 inline-flex items-center justify-center text-slate-300 active:text-white"
                                    >
                                        {showPass ? <EyeOff className="w-5 h-5" aria-hidden="true" /> : <Eye className="w-5 h-5" aria-hidden="true" />}
                                    </button>
                                </div>
                                {errors.password && <p className="text-red-400 text-sm mt-1">{errors.password}</p>}
                            </div>

                            {/* Remember + forgot */}
                            <div className="flex items-center justify-between">
                                <label className="flex items-center gap-2 cursor-pointer min-h-11">
                                    <input
                                        type="checkbox"
                                        checked={data.remember}
                                        onChange={e => setData('remember', e.target.checked)}
                                        className="w-5 h-5 rounded border-slate-500 bg-navy-800 accent-brand-500"
                                    />
                                    <span className="text-sm text-slate-400">{t('auth.remember')}</span>
                                </label>
                                <a href="/forgot-password" className="inline-flex items-center min-h-11 text-sm text-yellow-500 hover:text-yellow-400">
                                    {t('auth.forgot')}
                                </a>
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={processing}
                                className="btn-accent w-full min-h-12 text-base"
                            >
                                {processing ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
                                {t('auth.submit')}
                            </button>
                        </form>

                        {demoAccounts.length > 0 && (
                            <div className="mt-8 pt-6 border-t border-slate-600">
                                <p className="text-sm font-semibold uppercase tracking-widest text-yellow-500 mb-1">{t('auth.demo.title')}</p>
                                <p className="text-sm text-slate-400 mb-4">{t('auth.demo.text')}</p>
                                <DemoSelect options={demoAccounts.map((a) => ({ value: a.login, label: a.name, group: a.section }))}
                                    endpoint={(v) => `/demo-login/${v}`} />
                            </div>
                        )}

                        </>)}
                        <p className="text-center text-xs text-slate-400 mt-8">
                            {t('auth.footer')}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
