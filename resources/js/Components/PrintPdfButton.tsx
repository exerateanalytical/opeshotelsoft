import { Loader2, Printer } from 'lucide-react';
import { useState } from 'react';
import { activeHotelId, ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** D-B: opens a money document (GET /api/v1/documents/money/<kind>/<id>) as a PDF in a new tab. */
export default function PrintPdfButton({ path, label, className = 'btn-secondary inline-flex items-center gap-1 text-xs' }: { path: string; label?: string; className?: string }) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const open = async () => {
        setBusy(true);
        setErr(null);
        try {
            const hotel = activeHotelId();
            const res = await fetch(`/api/v1/documents/money/${path}`, {
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
            setErr(e instanceof ApiError ? e.message : t('documents3.error'));
        } finally {
            setBusy(false);
        }
    };
    return (
        <span className="inline-flex flex-col">
            <button type="button" className={className} onClick={open} disabled={busy}>
                {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Printer className="h-3.5 w-3.5" />} {label ?? t('documents3.print')}
            </button>
            {err && <span className="text-xs text-red-600">{err}</span>}
        </span>
    );
}
