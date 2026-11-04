import { BedDouble, Boxes, CalendarDays, ClipboardList, CreditCard, FileText, Gift, Inbox, MessageSquare, SearchX, Users, UserRound, Wrench, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import EmptyState from './EmptyState';

export type EmptyKind =
    | 'reservations' | 'rooms' | 'messages' | 'maintenance' | 'loyalty' | 'invoices' | 'stock' | 'events'
    | 'guests' | 'payments' | 'tasks' | 'staff' | 'generic';

const ICON: Record<EmptyKind, LucideIcon> = {
    reservations: CalendarDays, rooms: BedDouble, messages: MessageSquare, maintenance: Wrench, loyalty: Gift, invoices: FileText,
    stock: Boxes, events: CalendarDays, guests: UserRound, payments: CreditCard, tasks: ClipboardList, staff: Users, generic: Inbox,
};

/** Kinds that have a suggested first action label (states.empty.<kind>.action). */
const HAS_ACTION: EmptyKind[] = ['reservations', 'rooms', 'messages', 'maintenance', 'loyalty', 'invoices', 'stock', 'events', 'guests', 'staff'];

/** Empty data page: icon, one explanatory line and the next action (build the button with EmptyAction or your own). */
export function EmptyFor({ kind, action }: { kind: EmptyKind; action?: ReactNode }) {
    const t = useT();
    return <EmptyState icon={ICON[kind]} title={t(`states.empty.${kind}.title`)} description={t(`states.empty.${kind}.text`)} action={action} />;
}

/** The standard primary button for an empty state: label comes from states.empty.<kind>.action. */
export function EmptyAction({ kind, href, onClick }: { kind: EmptyKind; href?: string; onClick?: () => void }) {
    const t = useT();
    if (!HAS_ACTION.includes(kind)) return null;
    const label = t(`states.empty.${kind}.action`);
    return href ? <a href={href} className="btn-primary">{label}</a> : <button type="button" className="btn-primary" onClick={onClick}>{label}</button>;
}

/** Nothing matches the search or filters: offers Reset filters. */
export function NoResults({ onReset }: { onReset?: () => void }) {
    const t = useT();
    return (
        <EmptyState
            icon={SearchX}
            title={t('states.empty.results.title')}
            description={t('states.empty.results.text')}
            action={onReset ? <button type="button" className="btn-secondary" onClick={onReset}>{t('states.reset_filters')}</button> : undefined}
        />
    );
}
