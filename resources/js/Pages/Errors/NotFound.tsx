import ErrorScreen from '@/Components/ui/ErrorScreen';

/** 404 for staff (inside the app shell) and for guests (public layout). */
export default function NotFound({ requestId }: { requestId?: string | null }) {
    return <ErrorScreen status={404} requestId={requestId} />;
}
