import { useEffect, useRef } from 'react';

/** Minimal drawn-signature canvas (mouse or touch). Reports a PNG data URL, or '' when cleared. */
export default function SignaturePad({ onChange, clearLabel, label }: { onChange: (dataUrl: string) => void; clearLabel: string; label: string }) {
    const ref = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);

    useEffect(() => {
        const c = ref.current!;
        const ctx = c.getContext('2d')!;
        ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827';
        const pos = (e: PointerEvent) => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * (c.width / r.width), (e.clientY - r.top) * (c.height / r.height)]; };
        const down = (e: PointerEvent) => { drawing.current = true; const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); c.setPointerCapture(e.pointerId); };
        const move = (e: PointerEvent) => { if (!drawing.current) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); };
        const up = () => { if (drawing.current) { drawing.current = false; onChange(c.toDataURL('image/png')); } };
        c.addEventListener('pointerdown', down); c.addEventListener('pointermove', move); c.addEventListener('pointerup', up);
        return () => { c.removeEventListener('pointerdown', down); c.removeEventListener('pointermove', move); c.removeEventListener('pointerup', up); };
    }, [onChange]);

    return (
        <div className="space-y-1">
            <span className="label">{label}</span>
            <canvas ref={ref} width={480} height={140} className="w-full touch-none rounded border border-slate-300 bg-white" aria-label={label} />
            <button type="button" className="btn-secondary" onClick={() => { const c = ref.current!; c.getContext('2d')!.clearRect(0, 0, c.width, c.height); onChange(''); }}>{clearLabel}</button>
        </div>
    );
}
