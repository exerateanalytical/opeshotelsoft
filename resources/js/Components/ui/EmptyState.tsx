import { Inbox } from 'lucide-react';
import type { ElementType, ReactNode } from 'react';
import { useT } from '@/lib/i18n';

interface Props { title?: string; description?: string; icon?: ElementType; action?: ReactNode }

/** Empty list or search result: outline icon, one sentence, optional primary action. */
export default function EmptyState({ title, description, icon: Icon = Inbox, action }: Props) {
    const t = useT();
    return (
        <div className="flex flex-col items-center px-4 py-12 text-center">
            <Icon className="mb-3 h-10 w-10 text-slate-500" aria-hidden="true" />
            <p className="text-base font-semibold text-slate-900">{title ?? t('ui.empty.title')}</p>
            {description && <p className="mt-1 max-w-md text-sm text-slate-500">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
