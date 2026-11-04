import ErrorScreen, { type ErrorStatus } from '@/Components/ui/ErrorScreen';

/** Rendered by App\Platform\Http\ErrorPages for 403, 419, 429, 500 and 503 (404 uses Errors/NotFound). */
export default function Show({ status, requestId, retryAfter }: { status: ErrorStatus; requestId?: string | null; retryAfter?: number | null }) {
    return <ErrorScreen status={status} requestId={requestId} retryAfter={retryAfter} />;
}
