import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';
import { WorkforceTabs, wpost, msg } from './WorkforceTabs';

function deviceId(): string | undefined {
    try {
        let d = localStorage.getItem('wf_device');
        if (!d) { d = crypto.randomUUID(); localStorage.setItem('wf_device', d); }
        return d;
    } catch { return undefined; }
}

export default function WorkforceClock() {
    const t = useT();
    const [out, setOut] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [photo, setPhoto] = useState<string | undefined>();
    const clock = (dir: 'in' | 'out') => {
        setError(null); setOut(null);
        const send = async (pos?: GeolocationPosition) => {
            try {
                const r = (await wpost(`workforce/clock/${dir}`, { lat: pos?.coords.latitude, lng: pos?.coords.longitude, accuracy: pos ? Math.round(pos.coords.accuracy) : undefined, photo, device_id: deviceId() })) as { anomalies: { kind: string }[] };
                setOut(t(`workforce.clock.done_${dir}`) + (r.anomalies.length ? ` (${r.anomalies.map((a) => t(`workforce.anomaly.${a.kind}`)).join(', ')})` : ''));
            } catch (e) { setError(msg(e)); }
        };
        if (!navigator.geolocation) { send(); return; }
        navigator.geolocation.getCurrentPosition(send, () => send(), { enableHighAccuracy: true, timeout: 8000 });
    };
    const pick = (f?: File) => { if (!f) { setPhoto(undefined); return; } const rd = new FileReader(); rd.onload = () => setPhoto(String(rd.result)); rd.readAsDataURL(f); };
    return (
        <AppLayout title={t('workforce.clock.title')} subtitle={t('workforce.clock.subtitle')}>
            <Head title={t('workforce.clock.title')} />
            <WorkforceTabs active="/hr/workforce/clock" />
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            {out && <div className="mb-4 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm">{out}</div>}
            <div className="card max-w-md space-y-3 p-4 text-sm">
                <label className="block">{t('workforce.clock.photo')}<input type="file" accept="image/*" capture="user" className="input w-full" onChange={(e) => pick(e.target.files?.[0])} /></label>
                <div className="flex gap-3"><button className="btn-primary flex-1 py-3" onClick={() => clock('in')}>{t('workforce.clock.in')}</button><button className="btn-secondary flex-1 py-3" onClick={() => clock('out')}>{t('workforce.clock.out')}</button></div>
            </div>
        </AppLayout>
    );
}
