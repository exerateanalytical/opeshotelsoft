import AppLayout from '@/Layouts/AppLayout';
import StockTabs from './StockTabs';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import CameraScan from '@/Components/gapj/CameraScan';
import EmptyState from '@/Components/ui/EmptyState';
import LoadingState from '@/Components/ui/LoadingState';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg } from './ControlShared';
import ScanTools from './ScanTools';

interface Resolved { type: 'item' | 'lot' | 'po'; item?: { code: string; name: string; unit: string }; lot?: { lot_number: string; expiry_date: string | null; quantity_remaining: number }; po?: { number: string; status: string } }

/** Camera scanning with the browser BarcodeDetector API; manual entry always works. */
export default function Scan() {
    const t = useT();
    const [code, setCode] = useState('');
    const [result, setResult] = useState<Resolved | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [searched, setSearched] = useState(false);

    async function lookup(value: string) {
        setError(null); setBusy(true);
        try { setResult(await api<Resolved>(`stock-ops/scan/resolve?code=${encodeURIComponent(value)}`)); } catch (e) { setResult(null); setError(errMsg(e)); }
        setBusy(false); setSearched(true);
    }

    return (
        <AppLayout title={t('stock3.scan.title')} subtitle={t('stock3.scan.subtitle')}>
            <Head title={t('stock3.scan.title')} />
            <StockTabs current="scan" />
            <div className="card max-w-md p-3 text-sm">
                <CameraScan className="mb-3" onCode={(c) => { setCode(c); lookup(c); }} />
                <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); lookup(code); }}>
                    <input className="input flex-1" aria-label={t('stock3.scan.manual')} value={code} onChange={(e) => setCode(e.target.value)} placeholder={t('stock3.scan.manual')} />
                    <button type="submit" className="btn-secondary" disabled={busy || !code.trim()}>{busy ? t('states.loading') : t('stock3.scan.lookup')}</button>
                </form>
                {busy && <LoadingState variant="detail" rows={2} className="mt-3" />}
                {!busy && !searched && !error && <EmptyState title={t('states3.empty.scan.title')} description={t('states3.empty.scan.text')} />}
                {error && (
                    <div className="mt-3 flex flex-wrap items-center gap-2 text-red-700" role="alert">
                        <span>{error}</span>
                        <button type="button" className="btn-secondary btn-sm" onClick={() => lookup(code)}>{t('states.retry')}</button>
                    </div>
                )}
                {result && (
                    <div className="mt-3">
                        <p className="font-medium">{t(`stock3.scan.type.${result.type}`)}</p>
                        {result.item && <p>{result.item.code} · {result.item.name} ({result.item.unit})</p>}
                        {result.lot && <p>{t('stock3.scan.lot', { lot: result.lot.lot_number, qty: result.lot.quantity_remaining })}{result.lot.expiry_date ? ` · ${result.lot.expiry_date}` : ''}</p>}
                        {result.po && <p>{result.po.number} · {result.po.status}</p>}
                    </div>
                )}
            </div>
            <ScanTools />
        </AppLayout>
    );
}
