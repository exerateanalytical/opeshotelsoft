import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import BrandLogo from '@/Components/ui/BrandLogo';
import { useT } from '@/lib/i18n';

/** Standalone layout for errors and other pages that must work without a session (guests, members, signed-out staff). */
export default function PublicErrorLayout({ title, children }: { title: string; children: ReactNode }) {
    const t = useT();
    return (
        <div className="min-h-dvh flex flex-col bg-[var(--c-surface-page)]">
            <Head title={title} />
            <a href="#main" className="skip-link">{t('ui.skip')}</a>
            <header className="bg-white border-b border-slate-300 pt-[var(--safe-top)]">
                <div className="mx-auto flex min-h-[56px] max-w-3xl items-center px-4">
                    <Link href="/" className="inline-flex items-center gap-2 min-h-[44px] font-semibold text-slate-900">
                        <BrandLogo variant="emblem" size={28} />
                        <span>OPES Hôtels Soft</span>
                    </Link>
                </div>
            </header>
            <main id="main" tabIndex={-1} className="flex-1 mx-auto w-full max-w-3xl px-4 py-10 focus:outline-none">{children}</main>
        </div>
    );
}
