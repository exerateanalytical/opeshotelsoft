import { useState } from 'react';

/** Round profile photo with an initials fallback. */
export default function Avatar({ src, name, size = 32 }: { src?: string | null; name: string; size?: number }) {
    const [failed, setFailed] = useState(false);
    const initials = name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
    const style = { width: size, height: size, fontSize: Math.max(13, size * 0.38) };

    if (src && !failed) {
        return <img src={src} alt="" onError={() => setFailed(true)} style={style} className="shrink-0 rounded-full border border-slate-200 object-cover" />;
    }

    return <span style={style} className="inline-flex shrink-0 items-center justify-center rounded-full bg-navy-700 font-semibold text-white">{initials}</span>;
}
