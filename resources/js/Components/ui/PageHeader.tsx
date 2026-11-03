import type { ReactNode } from 'react';

interface Props {
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    /** Render as the page h1. Leave true only when AppLayout is not already showing the title (it renders the h1 in the header). */
    heading?: boolean;
}

/** In-page header: title, subtitle and an actions row that wraps instead of overflowing. Inside AppLayout use heading={false} (renders an h2). */
export default function PageHeader({ title, subtitle, actions, heading = true }: Props) {
    return (
        <div className="page-header">
            <div className="min-w-0 flex-1">
                {heading
                    ? <h1 tabIndex={-1} data-page-title className="text-xl font-semibold text-slate-900 md:text-2xl">{title}</h1>
                    : <h2 className="text-xl font-semibold text-slate-900">{title}</h2>}
                {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
