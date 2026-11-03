import { Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { ButtonHTMLAttributes, ElementType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'accent';
type Size = 'sm' | 'md' | 'lg';

interface Props extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
    variant?: Variant;
    size?: Size;
    /** Shows a spinner and disables the button while an action runs. */
    loading?: boolean;
    /** Lucide icon component, rendered at 16px before the label. */
    icon?: ElementType;
    /** Renders an Inertia link styled as a button. */
    href?: string;
    /** Icon-only button: children become the accessible name and are visually hidden. */
    iconOnly?: boolean;
    children?: ReactNode;
}

const VARIANT: Record<Variant, string> = {
    primary: 'btn-primary', secondary: 'btn-secondary', danger: 'btn-danger', ghost: 'btn-ghost', accent: 'btn-accent',
};

/** The one button. Navy primary with white text, outline secondary, red danger, quiet ghost, gold accent (navy text). */
export default function Button({ variant = 'primary', size = 'md', loading, icon: Icon, href, iconOnly, className, children, disabled, type = 'button', ...rest }: Props) {
    const cls = cn(VARIANT[variant], size === 'sm' && 'btn-sm', size === 'lg' && 'btn-lg', iconOnly && 'btn-icon', className);
    const inner = (
        <>
            {loading ? <Loader2 className="h-4 w-4 motion-safe:animate-spin" aria-hidden="true" /> : Icon && <Icon className="h-4 w-4" aria-hidden="true" />}
            {iconOnly ? <span className="sr-only">{children}</span> : children}
        </>
    );
    if (href && !disabled) return <Link href={href} className={cls}>{inner}</Link>;
    return (
        <button type={type} className={cls} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
            {inner}
        </button>
    );
}
