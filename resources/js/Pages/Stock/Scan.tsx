import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg } from './ControlShared';

interface Resolved { type: 'item' | 'lot' | 'po'; item?: { code: string; name: string; unit: string }; lot?: { lot_number: string; expiry_date: string | null; quantity_remaining: number }; po?: { number: string; status: string } }
type Detector = { detect: (v: HTMLVideoElement) => Promise<{ rawValue: string }[]> };

/** Camera scanning with the browser BarcodeDetector API; manual entry always works. */
export default function Scan() {
    const t = useT();
    const [code, setCode] = useState('');
    const [result, setResult] = useState<Resolved | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [camera, setCamera] = useState(false);
    const video = useRef<HTMLVideoElement>(null);
    const supported = typeof window !== 'undefined' && 'BarcodeDetector' in window;

    async function lookup(value: string) {
        setError(null);
        try { setResult(await api<Resolved>(`stock-ops/scan/resolve?code=${encodeURIComponent(value)}`)); } catch (e) { setResult(null); setError(errMsg(e)); }
    }

    useEffect(() => {
        if (!camera || !supported) return;
        let stop = false;
        let stream: MediaStream | null = null;
        const Detector = (window as unknown as { BarcodeDetector: new (o: { formats: string[] }) => Detector }).BarcodeDetector;
        const detector = new Detector({ formats: ['qr_code', 'code_128', 'ean_13', 'ean_8', 'code_39'] });
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(async (s) => {
            stream = s;
            if (video.current) { video.current.srcObject = s; await video.current.play(); }
            while (!stop && video.current) {
                const found = await detector.detect(video.current).catch(() => []);
                if (found.length) { setCode(found[0].rawValue); lookup(found[0].rawValue); setCamera(false); break; }
                await new Promise((r) => setTimeout(r, 300));
            }
        }).catch(() => { setError(t('stock3.scan.camera_denied')); setCamera(false); });
        return () => { stop = true; stream?.getTracks().forEach((x) => x.stop()); };
    }, [camera]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <AppLayout title={t('stock3.scan.title')} subtitle={t('stock3.scan.subtitle')}>
            <Head title={t('stock3.scan.title')} />
            <div className="card max-w-md p-3 text-sm">
                {supported ? (
                    <button type="button" className="btn-primary mb-3 w-full" onClick={() => setCamera(!camera)}>{camera ? t('stock3.scan.stop') : t('stock3.scan.start')}</button>
                ) : <p className="mb-3 text-slate-500">{t('stock3.scan.unsupported')}</p>}
                {camera && <video ref={video} className="mb-3 w-full" muted playsInline />}
                <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); lookup(code); }}>
                    <input className="input flex-1" aria-label={t('stock3.scan.manual')} value={code} onChange={(e) => setCode(e.target.value)} placeholder={t('stock3.scan.manual')} />
                    <button type="submit" className="btn-secondary">{t('stock3.scan.lookup')}</button>
                </form>
                {error && <p className="mt-3 text-red-700">{error}</p>}
                {result && (
                    <div className="mt-3">
                        <p className="font-medium">{t(`stock3.scan.type.${result.type}`)}</p>
                        {result.item && <p>{result.item.code} · {result.item.name} ({result.item.unit})</p>}
                        {result.lot && <p>{t('stock3.scan.lot', { lot: result.lot.lot_number, qty: result.lot.quantity_remaining })}{result.lot.expiry_date ? ` · ${result.lot.expiry_date}` : ''}</p>}
                        {result.po && <p>{result.po.number} · {result.po.status}</p>}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
