import { Head, Link } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { LanguageSwitch } from '@/Components/layout/Topbar';

/** Staff password resets are handled by an administrator (there is no self-service staff reset flow). */
export default function ForgotPassword() {
    const t = useT();

    return (
        <>
            <Head title={t('auth.forgot')} />
            <main className="min-h-screen flex items-center justify-center bg-navy-900 p-4">
                <div className="w-full max-w-[400px]">
                    <div className="flex justify-end mb-6"><LanguageSwitch dark /></div>
                    <div className="w-10 h-10 rounded-xl bg-brand-500 flex items-center justify-center mb-6">
                        <KeyRound className="w-5 h-5 text-navy-900" />
                    </div>
                    <h1 className="text-2xl font-bold text-white mb-1.5">{t('auth.forgot')}</h1>
                    <p className="text-slate-300 text-sm mb-8">{t('auth.forgot.text')}</p>
                    <Link href="/login" className="inline-flex items-center min-h-11 text-sm text-yellow-500 hover:text-yellow-400">{t('auth.2fa.back')}</Link>
                </div>
            </main>
        </>
    );
}
