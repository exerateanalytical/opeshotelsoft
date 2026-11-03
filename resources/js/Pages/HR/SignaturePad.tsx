import { useEffect, useRef } from 'react';

/** Finger / mouse signature drawn on a canvas; reports a PNG data URL (null when cleared). */
export default function SignaturePad({ onChange, clearLabel }: { onChange: (png: string | null) => void; clearLabel: string }) {
    const ref = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const dirty = useRef(false);

    useEffect(() => {
        const c = ref.current!;
        const ratio = window.devicePixelRatio || 1;
        c.width = c.offsetWidth * ratio;
        c.height = c.offsetHeight * ratio;
        const ctx = c.getContext('2d')!;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#0f172a';
    }, []);

    const pos = (e: React.PointerEvent) => {
        const r = ref.current!.getBoundingClientRect();
        return [e.clientX - r.left, e.clientY - r.top] as const;
    };
    const down = (e: React.PointerEvent) => {
        ref.current!.setPointerCapture(e.pointerId);
        drawing.current = true;
        const ctx = ref.current!.getContext('2d')!;
        ctx.beginPath();
        ctx.moveTo(...pos(e));
    };
    const move = (e: React.PointerEvent) => {
        if (!drawing.current) return;
        const ctx = ref.current!.getContext('2d')!;
        ctx.lineTo(...pos(e));
        ctx.stroke();
        dirty.current = true;
    };
    const up = () => {
        if (!drawing.current) return;
        drawing.current = false;
        if (dirty.current) onChange(ref.current!.toDataURL('image/png'));
    };
    const clear = () => {
        const c = ref.current!;
        c.getContext('2d')!.clearRect(0, 0, c.width, c.height);
        dirty.current = false;
        onChange(null);
    };

    return (
        <div>
            <canvas ref={ref} onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up}
                className="h-40 w-full touch-none rounded-lg border border-slate-500 bg-white" role="img" aria-label="Signature" />
            <button type="button" onClick={clear} className="mt-1 inline-flex items-center min-h-11 px-1 text-sm text-slate-600 underline">{clearLabel}</button>
        </div>
    );
}
