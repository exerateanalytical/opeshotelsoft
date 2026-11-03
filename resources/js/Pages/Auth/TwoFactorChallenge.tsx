import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2, ShieldCheck } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { LanguageSwitch } from '@/Components/layout/Topbar';

export default function TwoFactorChallenge() {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/two-factor-challenge');
    };

    return (
        <>
            <Head title={t('auth.2fa.title')} />
            <div className="min-h-screen flex items-center justify-center bg-navy-900 p-4">
                <div className="w-full max-w-[400px]">
                    <div className="flex justify-end mb-6"><LanguageSwitch dark /></div>
                    <div className="w-10 h-10 rounded-xl bg-brand-500 flex items-center justify-center mb-6">
                        <ShieldCheck className="w-5 h-5 text-navy-900" />
                    </div>
                    <h1 className="text-2xl font-bold text-white mb-1.5">{t('auth.2fa.title')}</h1>
                    <p className="text-slate-400 text-sm mb-8">{t('auth.2fa.text')}</p>
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <label className="label text-slate-300" htmlFor="code">{t('auth.2fa.code')}</label>
                            <input id="code" autoFocus autoComplete="one-time-code" inputMode="text" value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                className="input bg-navy-800 border-slate-600 text-white placeholder-slate-500 font-mono tracking-widest" />
                            {errors.code && <p className="text-red-400 text-sm mt-1">{errors.code}</p>}
                        </div>
                        <button type="submit" disabled={processing || !data.code} className="btn-accent w-full justify-center py-3">
                            {processing && <Loader2 className="w-4 h-4 animate-spin" />} {t('auth.2fa.verify')}
                        </button>
                    </form>
                    <Link href="/login" className="flex items-center justify-center min-h-11 text-sm text-yellow-500 hover:text-yellow-400 mt-4">{t('auth.2fa.back')}</Link>
                </div>
            </div>
        </>
    );
}
