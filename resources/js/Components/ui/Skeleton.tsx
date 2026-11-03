import { cn } from '@/lib/utils';

/** Static placeholder block (no animation, so no glow or pulse). Give it the size of the content it replaces to avoid layout shift. */
export default function Skeleton({ className }: { className?: string }) {
    return <div className={cn('skeleton h-4 w-full', className)} aria-hidden="true" />;
}

export function SkeletonRows({ rows = 5 }: { rows?: number }) {
    return (
        <div role="status" aria-busy="true" className="space-y-3 p-4">
            <span className="sr-only">…</span>
            {Array.from({ length: rows }, (_, i) => <Skeleton key={i} className="h-6" />)}
        </div>
    );
}
