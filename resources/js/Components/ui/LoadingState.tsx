import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import Skeleton from './Skeleton';

export type LoadingVariant = 'list' | 'table' | 'cards' | 'detail' | 'form';

/**
 * Standard loading placeholder shaped like the content it replaces, so nothing jumps when data arrives.
 * Flat grey blocks: no shimmer, pulse or glow, so it is already calm for prefers-reduced-motion.
 */
export default function LoadingState({ variant = 'list', rows, className }: { variant?: LoadingVariant; rows?: number; className?: string }) {
    const t = useT();
    const n = rows ?? (variant === 'cards' ? 6 : variant === 'form' ? 4 : 5);
    return (
        <div role="status" aria-busy="true" aria-live="polite" className={cn('min-h-[8rem]', className)}>
            <span className="sr-only">{t('states.loading')}</span>
            {variant === 'list' && (
                <ul className="card divide-y divide-slate-200" aria-hidden="true">
                    {Array.from({ length: n }, (_, i) => (
                        <li key={i} className="flex items-center gap-3 p-4">
                            <Skeleton className="h-10 w-10 shrink-0 rounded-full" />
                            <div className="min-w-0 flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/3" /></div>
                        </li>
                    ))}
                </ul>
            )}
            {variant === 'table' && (
                <div className="card overflow-hidden" aria-hidden="true">
                    <div className="flex gap-4 border-b border-slate-200 p-4">{[1, 2, 3, 4].map((c) => <Skeleton key={c} className="h-4 flex-1" />)}</div>
                    {Array.from({ length: n }, (_, i) => (
                        <div key={i} className="flex gap-4 border-b border-slate-100 p-4 last:border-0">{[1, 2, 3, 4].map((c) => <Skeleton key={c} className="h-5 flex-1" />)}</div>
                    ))}
                </div>
            )}
            {variant === 'cards' && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-hidden="true">
                    {Array.from({ length: n }, (_, i) => (
                        <div key={i} className="card space-y-3 p-4"><Skeleton className="h-5 w-1/2" /><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-3/4" /><Skeleton className="h-9 w-28" /></div>
                    ))}
                </div>
            )}
            {variant === 'detail' && (
                <div className="space-y-4" aria-hidden="true">
                    <div className="card space-y-3 p-4"><Skeleton className="h-7 w-1/3" /><Skeleton className="h-4 w-1/2" /></div>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {[1, 2, 3].map((c) => <div key={c} className="card space-y-2 p-4"><Skeleton className="h-4 w-1/2" /><Skeleton className="h-7 w-2/3" /></div>)}
                    </div>
                    <div className="card space-y-3 p-4">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-5" />)}</div>
                </div>
            )}
            {variant === 'form' && (
                <div className="card space-y-5 p-4" aria-hidden="true">
                    {Array.from({ length: n }, (_, i) => <div key={i} className="space-y-2"><Skeleton className="h-4 w-32" /><Skeleton className="h-11 w-full" /></div>)}
                    <Skeleton className="h-11 w-32" />
                </div>
            )}
        </div>
    );
}
