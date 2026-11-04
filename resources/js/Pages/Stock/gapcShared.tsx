import { Loader2, Printer, Download } from 'lucide-react';
import { useState } from 'react';
import { activeHotelId, ApiError } from '@/lib/api';
import { useT } from '@/lib/i18n';

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Opens any API PDF (GET /api/v1/<path>) in a new tab. */
export function StockPdfButton({ path, label, className = 'btn-secondary' }: { path: string; label?: string; className?: string }) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const open = async () => {
        setBusy(true);
        setErr(null);
        try {
            const hotel = activeHotelId();
            const res = await fetch(`/api/v1/${path}`, {
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
            setErr(e instanceof ApiError ? e.message : t('gapc.print_failed'));
        } finally {
            setBusy(false);
        }
    };
    return (
        <span className="inline-flex flex-col">
            <button type="button" className={className} onClick={open} disabled={busy}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Printer className="h-4 w-4" aria-hidden="true" />} {label ?? t('gapc.print')}
            </button>
            {err && <span role="alert" className="text-sm text-red-700">{err}</span>}
        </span>
    );
}

export const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);

/** Opens a print-ready window with the given (already escaped) HTML body and starts the print dialog. */
export function printHtml(title: string, body: string) {
    const w = window.open('', '_blank');
    if (!w) return false;
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>body{font:14px/1.5 Arial,sans-serif;color:#111;margin:24px}h1{font-size:20px}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{border:1px solid #444;padding:6px 8px;text-align:left}td.n{text-align:right}.sig{margin-top:40px}</style></head><body>${body}</body></html>`);
    w.document.close();
    w.focus();
    w.print();
    return true;
}

/** Client-side CSV download (semicolon separated, UTF-8 with BOM so Excel opens accents correctly). */
export function downloadCsv(name: string, cols: string[], rows: Record<string, unknown>[]) {
    const cell = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const csv = [cols.map(cell).join(';'), ...rows.map((r) => cols.map((c) => cell(r[c])).join(';'))].join('\r\n');
    const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `${name}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

export function CsvButton({ onClick, busy }: { onClick: () => void; busy?: boolean }) {
    const t = useT();
    return (
        <button type="button" className="btn-secondary" onClick={onClick} disabled={busy}>
            {busy ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Download className="h-4 w-4" aria-hidden="true" />} {t('gapc.export_csv')}
        </button>
    );
}
