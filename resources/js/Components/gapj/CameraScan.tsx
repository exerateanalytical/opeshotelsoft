import { useEffect, useRef, useState } from 'react';
import { useT } from '@/lib/i18n';

type Detector = { detect: (v: HTMLVideoElement) => Promise<{ rawValue: string }[]> };

/** "Scan with camera" button: BarcodeDetector + getUserMedia. Calls onCode with the first code read; the stream stops on close, success or unmount. Typed / hardware-scanner input stays available in the caller. */
export default function CameraScan({ onCode, className = '' }: { onCode: (code: string) => void; className?: string }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [msg, setMsg] = useState<string | null>(null);
    const video = useRef<HTMLVideoElement>(null);
    const cb = useRef(onCode);
    cb.current = onCode;
    const supported = typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia;

    useEffect(() => {
        if (!open || !supported) return;
        let stop = false;
        let stream: MediaStream | null = null;
        const Det = (window as unknown as { BarcodeDetector: new (o: { formats: string[] }) => Detector }).BarcodeDetector;
        const detector = new Det({ formats: ['qr_code', 'code_128', 'ean_13', 'ean_8', 'code_39'] });
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(async (s) => {
            if (stop) { s.getTracks().forEach((x) => x.stop()); return; }
            stream = s;
            if (video.current) { video.current.srcObject = s; await video.current.play().catch(() => undefined); }
            while (!stop && video.current) {
                const found = await detector.detect(video.current).catch(() => []);
                if (found.length) { cb.current(found[0].rawValue); setOpen(false); break; }
                await new Promise((r) => setTimeout(r, 300));
            }
        }).catch(() => { if (!stop) { setMsg(t('gapj.scan.denied')); setOpen(false); } });
        return () => { stop = true; stream?.getTracks().forEach((x) => x.stop()); };
    }, [open, supported]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!supported) return <p className={`text-sm text-slate-500 ${className}`}>{t('gapj.scan.unsupported')}</p>;
    return (
        <div className={className}>
            <button type="button" className="btn-secondary min-h-11 w-full" onClick={() => { setMsg(null); setOpen(!open); }}>{open ? t('gapj.scan.close') : t('gapj.scan.camera')}</button>
            {open && <video ref={video} className="mt-2 w-full rounded-lg bg-black" muted playsInline aria-label={t('gapj.scan.camera')} />}
            {msg && <p role="alert" className="mt-2 text-sm text-red-700">{msg}</p>}
        </div>
    );
}
