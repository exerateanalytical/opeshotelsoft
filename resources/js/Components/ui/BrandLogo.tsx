import { cn } from '@/lib/utils';

export const BRAND_NAME = 'OPES Hôtels Soft';

/** Smallest emblem file that stays sharp at 2x density. */
const emblemSrc = (px: number) => (px <= 32 ? '/brand/emblem-64.png' : px <= 64 ? '/brand/emblem-128.png' : '/brand/emblem.png');

type Variant = 'emblem' | 'wordmark' | 'lockup';

interface Props {
    variant?: Variant;
    /** Height in px for emblem/lockup icon; for wordmark the rendered width in px. */
    size?: number;
    /** Lockup text colour classes (default white for dark headers). */
    textClassName?: string;
    className?: string;
    alt?: string;
}

/**
 * Platform brand. No effects, no recolouring.
 * - emblem: icon only (fine on dark or light).
 * - wordmark: full logo, dark letters, ONLY on light backgrounds or a white plate.
 * - lockup: emblem + HTML text for dark headers.
 */
export default function BrandLogo({ variant = 'emblem', size, textClassName = 'text-white', className, alt = BRAND_NAME }: Props) {
    if (variant === 'wordmark') {
        const w = size ?? 240;
        return (
            <img src="/brand/logo-wordmark.png" alt={alt} width={w} height={Math.round(w * 439 / 1200)}
                className={cn('block h-auto max-w-full', className)} style={{ width: w }} />
        );
    }
    const h = size ?? 32;
    if (variant === 'emblem') {
        return <img src={emblemSrc(h)} alt={alt} width={h} height={h} className={cn('block shrink-0', className)} />;
    }
    return (
        <span className={cn('inline-flex items-center gap-2.5 min-w-0', className)}>
            <img src={emblemSrc(h)} alt="" aria-hidden="true" width={h} height={h} className="block shrink-0" />
            <span className={cn('font-bold leading-tight truncate', textClassName)}>{BRAND_NAME}</span>
        </span>
    );
}
