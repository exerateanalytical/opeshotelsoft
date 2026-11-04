import { Link, usePage } from '@inertiajs/react';
import { Ban, Clock, FileQuestion, Hourglass, ServerCrash, WrenchIcon, type LucideIcon } from 'lucide-react';
import BrandLogo from '@/Components/ui/BrandLogo';
import AppLayout from '@/Layouts/AppLayout';
import PublicErrorLayout from '@/Layouts/PublicErrorLayout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export type ErrorStatus = 403 | 404 | 419 | 429 | 500 | 503;

const ICON: Record<ErrorStatus, LucideIcon> = { 403: Ban, 404: FileQuestion, 419: Clock, 429: Hourglass, 500: ServerCrash, 503: WrenchIcon };

/**
 * Full-page error screen: brand, plain explanation, one primary action and a support hint.
 * Inside the staff shell for signed-in users, inside the standalone public layout for guests and members.
 */
export default function ErrorScreen({ status, requestId, retryAfter }: { status: ErrorStatus; requestId?: string | null; retryAfter?: number | null }) {
    const t = useT();
    const signedIn = !!usePage<PageProps>().props.auth?.user;
    const Icon = ICON[status];
    const home = signedIn ? '/dashboard' : '/';
    const primary = (() => {
        const label = t(`states.page.${status}.action`);
        if (status === 404) return <Link href={home} className="btn-primary">{signedIn ? label : t('states.go_public_home')}</Link>;
        if (status === 403) return <button type="button" className="btn-primary" onClick={() => window.history.back()}>{label}</button>;
        return <button type="button" className="btn-primary" onClick={() => window.location.reload()}>{label}</button>;
    })();
    const secondary = status === 404 ? null : <Link href={home} className="btn-secondary">{signedIn ? t('states.go_home') : t('states.go_public_home')}</Link>;
    const card = (
        <div className="card mx-auto max-w-lg space-y-4 p-8 text-center">
            <BrandLogo variant="emblem" size={48} className="mx-auto" />
            <Icon className="mx-auto h-8 w-8 text-slate-600" aria-hidden="true" />
            <p className="text-sm font-semibold text-slate-600">{status}</p>
            {/* Signed-in pages already have the top bar title as h1; guests have no other heading. */}
            {signedIn
                ? <h2 className="text-lg font-semibold text-slate-900">{t(`states.page.${status}.title`)}</h2>
                : <h1 tabIndex={-1} data-page-title className="text-lg font-semibold text-slate-900">{t(`states.page.${status}.title`)}</h1>}
            <p className="text-sm text-slate-700">{t(`states.page.${status}.text`)}</p>
            {status === 429 && retryAfter ? <p className="text-sm text-slate-700">{t('states.err.throttle.wait', { seconds: retryAfter })}</p> : null}
            <div className="flex flex-wrap justify-center gap-2">{primary}{secondary}</div>
            {status !== 404 && <p className="text-xs text-slate-600">{t('states.support_hint')}</p>}
            {requestId && <p className="font-mono text-xs text-slate-600">{t('states.request_id', { id: requestId })}</p>}
        </div>
    );
    const title = t(`states.page.${status}.title`);
    if (signedIn) return <AppLayout title={title}><div className="mt-10">{card}</div></AppLayout>;
    return <PublicErrorLayout title={title}><div className="mt-10">{card}</div></PublicErrorLayout>;
}
