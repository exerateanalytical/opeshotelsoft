import { Loader2, Printer } from 'lucide-react';
import { useState } from 'react';
import { activeHotelId, ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** D-C: opens an operations or HR document (GET /api/v1/documents/ops/<kind>/<id>) as a PDF in a new tab. Same behaviour as PrintPdfButton. */
export default function OpsPrintButton({ path, label, className = 'btn-secondary inline-flex items-center gap-1 text-sm' }: { path: string; label?: string; className?: string }) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const open = async () => {
        setBusy(true);
        setErr(null);
        try {
            const hotel = activeHotelId();
            const res = await fetch(`/api/v1/documents/ops/${path}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new ApiError({ status: res.status, title: res.statusText, ...body });
            }
            const url = URL.createObjectURL(await res.blob());
            window.open(url, '_blank');
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (e) {
            setErr(e instanceof ApiError ? e.message : t('documents4.error'));
        } finally {
            setBusy(false);
        }
    };
    return (
        <span className="inline-flex flex-col">
            <button type="button" className={className} onClick={open} disabled={busy}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Printer className="h-4 w-4" />} {label ?? t('documents4.print')}
            </button>
            {err && <span className="text-sm text-red-600">{err}</span>}
        </span>
    );
}
