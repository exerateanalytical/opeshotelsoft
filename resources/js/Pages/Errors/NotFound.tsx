import { Head, Link } from '@inertiajs/react';
import { Compass } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { useT } from '@/lib/i18n';

export default function NotFound() {
    const t = useT();
    return (
        <AppLayout title={t('errors.not_found.title')}>
            <Head title={t('errors.not_found.head')} />
            <div className="card max-w-lg mx-auto mt-10 p-8 text-center space-y-4">
                <Compass className="w-10 h-10 mx-auto text-slate-400" />
                <h2 className="text-lg font-semibold text-slate-900">{t('errors.not_found.heading')}</h2>
                <p className="text-sm text-slate-500">{t('errors.not_found.text')}</p>
                <Link href="/dashboard" className="btn-primary inline-flex">{t('errors.not_found.back')}</Link>
            </div>
        </AppLayout>
    );
}
