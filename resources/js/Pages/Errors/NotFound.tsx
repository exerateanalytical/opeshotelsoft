import { Link, usePage } from '@inertiajs/react';
import BrandLogo from '@/Components/ui/BrandLogo';
import AppLayout from '@/Layouts/AppLayout';
import PublicErrorLayout from '@/Layouts/PublicErrorLayout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function NotFound() {
    const t = useT();
    const signedIn = !!usePage<PageProps>().props.auth?.user;
    const card = (home: string) => (
        <div className="card max-w-lg mx-auto p-8 text-center space-y-4">
            <BrandLogo variant="emblem" size={48} className="mx-auto" />
            <h1 tabIndex={-1} data-page-title className="text-lg font-semibold text-slate-900">{t('errors.not_found.heading')}</h1>
            <p className="text-sm text-slate-500">{t('errors.not_found.text')}</p>
            <Link href={home} className="btn-primary inline-flex">{t('errors.not_found.back')}</Link>
        </div>
    );
    if (signedIn) return <AppLayout title={t('errors.not_found.title')}><div className="mt-10">{card('/dashboard')}</div></AppLayout>;
    return <PublicErrorLayout title={t('errors.not_found.head')}>{card('/')}</PublicErrorLayout>;
}
