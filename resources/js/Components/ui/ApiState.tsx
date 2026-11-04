import { Clock, WifiOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { errorKind } from '@/lib/apiErrors';
import { useT } from '@/lib/i18n';
import { useSyncStatus } from '@/lib/syncStatus';
import ErrorState from './ErrorState';
import LoadingState, { type LoadingVariant } from './LoadingState';
import PermissionState from './PermissionState';

interface QueryLike<T> {
    data: T | null;
    error: unknown;
    loading: boolean;
    reload: () => void;
    updatedAt?: Date | null;
}

interface Props<T> {
    /** The value returned by useApi. */
    query: QueryLike<T>;
    variant?: LoadingVariant;
    rows?: number;
    /** What to show when the call succeeded but there is nothing: usually <EmptyFor kind="..." action={...} /> or <NoResults onReset={...} />. */
    empty?: ReactNode;
    /** Decides "empty" from the data; defaults to an empty array, or an empty `data` array on a paginated envelope. */
    isEmpty?: (data: T) => boolean;
    /** Role list for the 403 message, for example "front desk managers". */
    roles?: string;
    /** Show "Last updated hh:mm" under the content. */
    showUpdated?: boolean;
    children: (data: T) => ReactNode;
}

const defaultEmpty = (d: unknown) => (Array.isArray(d) ? d.length === 0 : Array.isArray((d as { data?: unknown })?.data) && (d as { data: unknown[] }).data.length === 0);

/** "Last updated 14:32" for read-only data; also warns when the browser is offline and the data may be old. */
export function LastUpdated({ at }: { at?: Date | null }) {
    const t = useT();
    const online = useSyncStatus().online;
    if (!at) return null;
    const time = at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return (
        <p className="mt-3 flex items-center justify-end gap-1.5 text-xs text-slate-600">
            {online ? <Clock className="h-3.5 w-3.5" aria-hidden="true" /> : <WifiOff className="h-3.5 w-3.5" aria-hidden="true" />}
            {t('states.last_updated', { time })}
        </p>
    );
}

/**
 * One place for the four states of a data call: loading skeleton, error with retry (or a 403 message), empty, and content.
 * If a reload fails while older data is on screen, the data stays and a compact error appears above it.
 *
 *   const q = useApi<Page<Room>>('rooms');
 *   <ApiState query={q} variant="table" empty={<EmptyFor kind="rooms" />}>{(rooms) => <RoomsTable rows={rooms.data} />}</ApiState>
 */
export default function ApiState<T>({ query, variant = 'table', rows, empty, isEmpty = defaultEmpty, roles, showUpdated, children }: Props<T>) {
    const { data, loading, reload, updatedAt } = query;
    const error = query.error;
    if (data == null) {
        if (loading || !error) return <LoadingState variant={variant} rows={rows} />;
        if (errorKind(error) === 'forbidden') return <PermissionState roles={roles} />;
        return <ErrorState error={error} onRetry={reload} />;
    }
    if (isEmpty(data) && empty) return <>{empty}</>;
    return (
        <>
            {error != null && !loading && <div className="card mb-3"><ErrorState compact error={error} onRetry={reload} /></div>}
            {children(data)}
            {showUpdated && <LastUpdated at={updatedAt} />}
        </>
    );
}
