import { usePage } from '@inertiajs/react';
import { WifiOff } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { useSyncStatus } from '@/lib/syncStatus';

/** Pages that keep working offline (they queue their writes), so no banner there. */
const OFFLINE_CAPABLE = [/^\/pos(\/|$)/];

/**
 * Shown under the top bar when the connection is lost on a page that needs one. Icon plus text, not colour alone.
 * Mounted by AppLayout; offline-capable screens are listed above.
 */
export default function OfflineBanner() {
    const t = useT();
    const { url } = usePage();
    const { online } = useSyncStatus();
    if (online || OFFLINE_CAPABLE.some((re) => re.test(url.split('?')[0]))) return null;
    return (
        <div role="status" className="flex items-start gap-2 border-b border-amber-700 bg-amber-50 px-4 py-2 text-sm text-amber-900">
            <WifiOff className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{t('states.offline_banner')}</span>
        </div>
    );
}
