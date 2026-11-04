import { useContext, type ReactNode } from 'react';
import { LayoutTitleContext } from '@/Layouts/AppLayout';
import Breadcrumbs, { type Crumb } from './Breadcrumbs';

interface Props {
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    /** Render as the page h1. Leave true only when AppLayout is not already showing the title (it renders the h1 in the header). */
    heading?: boolean;
    /** Breadcrumb trail above the title. `true` builds it from the menu and the URL. AppLayout already shows the automatic trail, so use this only outside AppLayout or with custom items. */
    breadcrumbs?: Crumb[] | boolean;
}

/** In-page header: title, subtitle and an actions row that wraps instead of overflowing. Inside AppLayout use heading={false} (renders an h2). */
export default function PageHeader({ title, subtitle, actions, heading = true, breadcrumbs }: Props) {
    const layoutTitle = useContext(LayoutTitleContext);
    const dup = !heading && layoutTitle !== undefined && layoutTitle === title; // phone: AppLayout already shows this title as the h1
    return (
        <div className="page-header">
            <div className="min-w-0 flex-1">
                {breadcrumbs && <Breadcrumbs items={Array.isArray(breadcrumbs) ? breadcrumbs : undefined} className="mb-1" />}
                {heading
                    ? <h1 tabIndex={-1} data-page-title className="text-xl font-semibold text-slate-900 md:text-2xl">{title}</h1>
                    : <h2 className={dup ? 'sr-only' : 'text-xl font-semibold text-slate-900'}>{title}</h2>}
                {subtitle && <p className="n1-prose hint mt-0.5">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
