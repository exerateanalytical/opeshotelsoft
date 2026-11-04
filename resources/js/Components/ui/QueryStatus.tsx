import { errorKind } from '@/lib/apiErrors';
import ErrorState from './ErrorState';
import LoadingState, { type LoadingVariant } from './LoadingState';
import PermissionState from './PermissionState';

interface Props {
    /** The value returned by useApi. */
    query: { data: unknown; error: unknown; loading: boolean; reload: () => void };
    variant?: LoadingVariant;
    rows?: number;
    /** Role list for the 403 message. */
    roles?: string;
}

/**
 * Drop-in status line for a page that already renders its own content: shows the loading skeleton until the first data
 * arrives, the error state (with retry) when the first load fails, and a compact error above the stale data when a reload fails.
 * Renders nothing when all is well. Use <ApiState> when you want to replace the content instead.
 *
 *   <QueryStatus query={list} variant="table" />
 */
export default function QueryStatus({ query, variant = 'table', rows, roles }: Props) {
    const { data, loading, reload } = query;
    const error = query.error;
    if (data == null) {
        if (loading || !error) return loading ? <LoadingState variant={variant} rows={rows} className="mb-4" /> : null;
        if (errorKind(error) === 'forbidden') return <div className="card mb-4"><PermissionState roles={roles} /></div>;
        return <div className="card mb-4"><ErrorState error={error} onRetry={reload} /></div>;
    }
    if (error != null && !loading) return <div className="card mb-4"><ErrorState compact error={error} onRetry={reload} /></div>;
    return null;
}
