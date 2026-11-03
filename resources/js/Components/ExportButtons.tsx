import { activeHotelId } from '@/lib/api';
import { Download, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';

type Format = 'csv' | 'xlsx' | 'pdf';

interface Props {
    /** Report key from the export registry, e.g. "trial-balance", "stock-balances", "payroll-run". */
    report: string;
    /** The report's own query params (from, to, account, run, ...). Empty values are dropped. */
    params?: Record<string, string | number | null | undefined>;
    className?: string;
}

const LABELS: Record<Format, string> = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' };

/** CSV / Excel / PDF download buttons for any report in the generic exporter. */
export default function ExportButtons({ report, params = {}, className = '' }: Props) {
    const t = useT();
    const [busy, setBusy] = useState<Format | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function download(format: Format) {
        setBusy(format);
        setError(null);
        try {
            const q = new URLSearchParams({ format });
            Object.entries(params).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== '') q.set(k, String(v));
            });
            const hotelId = activeHotelId();
            const res = await fetch(`/api/v1/exports/${report}?${q}`, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', ...(hotelId ? { 'X-Hotel-Id': hotelId } : {}) },
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.detail ?? body.title ?? t('export.failed', { status: res.status }));
            }
            const blob = await res.blob();
            const name = res.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] ?? `${report}.${format}`;
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = name;
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            setError(e instanceof Error ? e.message : String(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`}>
            {(['csv', 'xlsx', 'pdf'] as Format[]).map((f) => (
                <button key={f} type="button" className="btn-secondary" disabled={busy !== null} onClick={() => download(f)}>
                    {busy === f ? <Loader2 className="w-4 h-4 animate-spin" /> : <Download className="w-4 h-4" />} {LABELS[f]}
                </button>
            ))}
            {error && <span className="text-sm text-red-600">{error}</span>}
        </div>
    );
}
