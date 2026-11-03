import { Head, router, useForm } from '@inertiajs/react';
import { Hotel, Eye, EyeOff, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { LanguageSwitch } from '@/Components/layout/Topbar';

interface DemoAccount { login: string; name: string; section: string }

export default function Login({ demoAccounts = [] }: { demoAccounts?: DemoAccount[] }) {
    const t = useT();
    const [showPass, setShowPass] = useState(false);
    const [demoBusy, setDemoBusy] = useState<string | null>(null);
    const sections = [...new Set(demoAccounts.map((a) => a.section))];
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
            <div className="min-h-screen flex bg-[#0a1830]">
                {/* Left panel — branding */}
                <div className="hidden lg:flex flex-col justify-between w-[480px] bg-[#060f1e] p-12 relative overflow-hidden">

                    <div className="relative">
                        <div className="flex items-center gap-3 mb-12">
                            <div className="w-10 h-10 rounded-xl bg-[#c9a84c] flex items-center justify-center">
                                <Hotel className="w-5 h-5 text-white" />
                            </div>
                            <div>
                                <p className="text-white font-bold text-lg leading-tight">OPes Hotel Soft</p>
                                <p className="text-slate-500 text-xs">{t('auth.tagline')}</p>
                            </div>
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
                        <p className="text-slate-600 text-xs font-semibold uppercase tracking-widest mb-4">{t('auth.trusted_by')}</p>
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
                <div className="flex-1 flex items-center justify-center p-6">
                    <div className="w-full max-w-[400px]">
                        <div className="flex justify-end mb-6"><LanguageSwitch dark /></div>
                        {/* Mobile logo */}
                        <div className="flex lg:hidden items-center gap-3 mb-10 justify-center">
                            <div className="w-10 h-10 rounded-xl bg-[#c9a84c] flex items-center justify-center">
                                <Hotel className="w-5 h-5 text-white" />
                            </div>
                            <p className="text-white font-bold text-lg">OPes Hotel Soft</p>
                        </div>

                        <h2 className="text-2xl font-bold text-white mb-1.5">{t('auth.welcome')}</h2>
                        <p className="text-slate-400 text-sm mb-8">{t('auth.subtitle')}</p>

                        <form onSubmit={submit} className="space-y-5">
                            {/* Email */}
                            <div>
                                <label className="label text-slate-400">{t('auth.email')}</label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    placeholder="you@hotel.com"
                                    autoComplete="email"
                                    className={cn(
                                        'input bg-[#0f2044] border-[#1a3a6b] text-white placeholder-slate-500',
                                        'focus:border-yellow-500',
                                        errors.email && 'border-red-500',
                                    )}
                                />
                                {errors.email && <p className="text-red-400 text-xs mt-1">{errors.email}</p>}
                            </div>

                            {/* Password */}
                            <div>
                                <label className="label text-slate-400">{t('auth.password')}</label>
                                <div className="relative">
                                    <input
                                        type={showPass ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={e => setData('password', e.target.value)}
                                        placeholder="••••••••"
                                        autoComplete="current-password"
                                        className={cn(
                                            'input bg-slate-800 border-slate-700 text-white placeholder-slate-500 pr-10',
                                            'focus:border-yellow-500',
                                            errors.password && 'border-red-500',
                                        )}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPass(!showPass)}
                                        aria-label={t('auth.show_password')}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300"
                                    >
                                        {showPass ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                </div>
                                {errors.password && <p className="text-red-400 text-xs mt-1">{errors.password}</p>}
                            </div>

                            {/* Remember + forgot */}
                            <div className="flex items-center justify-between">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={data.remember}
                                        onChange={e => setData('remember', e.target.checked)}
                                        className="w-4 h-4 rounded border-slate-600 bg-slate-800 text-emerald-600"
                                    />
                                    <span className="text-sm text-slate-400">{t('auth.remember')}</span>
                                </label>
                                <a href="/forgot-password" className="text-sm text-yellow-500 hover:text-yellow-400 transition-colors">
                                    {t('auth.forgot')}
                                </a>
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={processing}
                                className="btn-primary w-full justify-center py-3 text-base"
                            >
                                {processing ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
                                {t('auth.submit')}
                            </button>
                        </form>

                        {demoAccounts.length > 0 && (
                            <div className="mt-8 pt-6 border-t border-[#1a3a6b]">
                                <p className="text-xs font-semibold uppercase tracking-widest text-yellow-500 mb-1">{t('auth.demo.title')}</p>
                                <p className="text-xs text-slate-500 mb-4">{t('auth.demo.text')}</p>
                                {sections.map((section) => (
                                    <div key={section} className="mb-3">
                                        <p className="text-xs uppercase tracking-wide text-slate-500 mb-1.5">{section}</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {demoAccounts.filter((a) => a.section === section).map((a) => (
                                                <button key={a.login} type="button" disabled={demoBusy !== null}
                                                    onClick={() => router.post(`/demo-login/${a.login}`, {}, {
                                                        onStart: () => setDemoBusy(a.login), onFinish: () => setDemoBusy(null),
                                                    })}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs text-slate-200 bg-[#0f2044] border border-[#1a3a6b] hover:border-yellow-500 hover:text-white transition-colors disabled:opacity-50">
                                                    {demoBusy === a.login && <Loader2 className="w-3 h-3 animate-spin" />}{a.name}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        <p className="text-center text-xs text-slate-600 mt-8">
                            {t('auth.footer')}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
