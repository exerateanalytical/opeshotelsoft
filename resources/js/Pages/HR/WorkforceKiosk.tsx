import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

const hhmm = (d: Date) => d.toLocaleTimeString(document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' });

/**
 * GAPI: full-screen PIN clock-in for the hotel's shared tablet (permission workforce.kiosk).
 * Flow: pick your name, type your PIN, tap In or Out. The screen resets itself a few seconds after each result
 * and after a minute without touch. Kiosk punches skip the geofence (server rule for method "pin"); a photo is
 * asked for only when the hotel requires one.
 */
interface Board { pin_enabled: boolean; photo_required: boolean; staff: { number: string; name: string }[]; recent: { name: string; direction: 'in' | 'out'; at: string }[] }
type Result = { ok: true; text: string } | { ok: false; text: string };

const RESET_AFTER_MS = 6000;
const IDLE_MS = 60000;

function deviceId(): string | undefined {
    try {
        let d = localStorage.getItem('wf_kiosk_device');
        if (!d) { d = crypto.randomUUID(); localStorage.setItem('wf_kiosk_device', d); }
        return d;
    } catch { return undefined; }
}

export default function WorkforceKiosk() {
    const t = useT();
    const board = useApi<Board>('workforce/kiosk/board');
    const [who, setWho] = useState<{ number: string; name: string } | null>(null);
    const [pin, setPin] = useState('');
    const [filter, setFilter] = useState('');
    const [photo, setPhoto] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<Result | null>(null);
    const [now, setNow] = useState(() => new Date());
    const idle = useRef<number | undefined>(undefined);

    const reset = useCallback(() => { setWho(null); setPin(''); setFilter(''); setPhoto(undefined); setResult(null); }, []);

    useEffect(() => { const i = window.setInterval(() => setNow(new Date()), 1000); return () => window.clearInterval(i); }, []);
    useEffect(() => { if (!result) return; const h = window.setTimeout(reset, RESET_AFTER_MS); return () => window.clearTimeout(h); }, [result, reset]);
    const touch = () => { window.clearTimeout(idle.current); idle.current = window.setTimeout(reset, IDLE_MS); };
    useEffect(() => () => window.clearTimeout(idle.current), []);

    const staff = useMemo(() => {
        const q = filter.trim().toLowerCase();
        return (board.data?.staff ?? []).filter((s) => !q || s.name.toLowerCase().includes(q) || s.number.toLowerCase().includes(q));
    }, [board.data, filter]);

    const errorText = (e: unknown): string => {
        if (e instanceof ApiError) {
            const type = e.problem.type ?? '';
            if (e.status === 423 || type.endsWith('pin-locked')) return t('gapi.kiosk.err_locked');
            if (type.endsWith('pin-invalid')) return t('gapi.kiosk.err_pin');
            if (type.endsWith('pin-disabled')) return t('gapi.kiosk.err_disabled');
            if (type.endsWith('unknown-employee') || e.status === 404) return t('gapi.kiosk.err_unknown');
            if (type.endsWith('photo-required')) return t('gapi.kiosk.err_photo');
            if (e.status === 429) return t('gapi.kiosk.err_throttle');
            return e.message;
        }
        return t('gapi.kiosk.err_network');
    };

    const punch = async (dir: 'in' | 'out') => {
        if (!who || pin.length < 4 || busy) return;
        setBusy(true);
        try {
            const r = await api<{ employee: string; anomalies: { kind: string }[] }>(`workforce/kiosk/${dir}`, { method: 'POST', body: JSON.stringify({ number: who.number, pin, photo, device_id: deviceId() }) });
            const time = hhmm(new Date());
            const late = r.anomalies.some((a) => a.kind === 'late');
            setResult({ ok: true, text: t(dir === 'in' ? 'gapi.kiosk.ok_in' : 'gapi.kiosk.ok_out', { name: r.employee, time }) + (late ? ` ${t('gapi.kiosk.late')}` : '') });
            board.reload();
        } catch (e) {
            setResult({ ok: false, text: errorText(e) });
        } finally { setPin(''); setBusy(false); }
    };

    const pickPhoto = (f?: File) => { if (!f) { setPhoto(undefined); return; } const rd = new FileReader(); rd.onload = () => setPhoto(String(rd.result)); rd.readAsDataURL(f); };
    const key = (d: string) => { touch(); setPin((p) => (d === 'del' ? p.slice(0, -1) : d === 'clr' ? '' : p.length < 8 ? p + d : p)); };
    const needPhoto = !!board.data?.photo_required;

    return (
        <div className="flex min-h-dvh flex-col bg-slate-50 text-slate-900" onPointerDown={touch}>
            <Head title={t('gapi.kiosk.title')} />
            <header className="flex flex-wrap items-center justify-between gap-3 bg-[var(--c-chrome,#0f2742)] px-6 py-4 text-white">
                <h1 className="text-2xl font-semibold">{t('gapi.kiosk.title')}</h1>
                <p className="num text-3xl font-semibold" aria-live="off">{hhmm(now)}</p>
            </header>

            <main className="mx-auto grid w-full max-w-5xl flex-1 gap-6 p-4 md:grid-cols-[2fr_1fr] md:p-6">
                <section aria-labelledby="kiosk-step" className="card p-4 md:p-6">
                    <div aria-live="assertive" role="status">
                        {result && (
                            <div className={`mb-4 rounded-lg border-2 p-6 text-center text-2xl font-semibold ${result.ok ? 'border-green-700 bg-green-50 text-green-900' : 'border-red-700 bg-red-50 text-red-900'}`}>
                                {result.text}
                                <p className="mt-2 text-base font-normal">{t('gapi.kiosk.resetting')}</p>
                                <button type="button" className="btn-secondary btn-lg mt-3" onClick={reset}>{t('gapi.kiosk.done')}</button>
                            </div>
                        )}
                    </div>

                    {board.loading && !board.data && <p className="text-lg">{t('gapi.kiosk.loading')}</p>}
                    {board.error && (
                        <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-lg text-red-900">
                            {board.error.status === 403 ? t('gapi.kiosk.err_forbidden') : board.error.message}
                            <button type="button" className="btn-secondary btn-lg ml-3" onClick={board.reload}>{t('gapi.retry')}</button>
                        </div>
                    )}
                    {board.data && !board.data.pin_enabled && <p role="alert" className="text-lg text-red-800">{t('gapi.kiosk.pin_off')}</p>}

                    {board.data && board.data.pin_enabled && !result && !who && (
                        <>
                            <h2 id="kiosk-step" className="mb-3 text-xl font-semibold">{t('gapi.kiosk.step_name')}</h2>
                            <input className="input mb-4 w-full text-lg" aria-label={t('gapi.kiosk.search')} placeholder={t('gapi.kiosk.search')} value={filter} onChange={(e) => setFilter(e.target.value)} />
                            {staff.length === 0
                                ? <p className="text-lg text-slate-700">{board.data.staff.length === 0 ? t('gapi.kiosk.no_staff') : t('gapi.kiosk.no_match')}</p>
                                : <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">{staff.map((s) => (
                                    <li key={s.number}><button type="button" className="btn-secondary h-20 w-full text-lg" onClick={() => { setWho(s); setPin(''); touch(); }}>
                                        <span className="flex flex-col items-center"><span>{s.name}</span><span className="text-sm text-slate-500">{s.number}</span></span></button></li>))}</ul>}
                        </>
                    )}

                    {who && !result && (
                        <>
                            <h2 id="kiosk-step" className="mb-1 text-xl font-semibold">{t('gapi.kiosk.step_pin', { name: who.name })}</h2>
                            <button type="button" className="btn-ghost mb-3" onClick={reset}>{t('gapi.kiosk.not_me')}</button>
                            <p className="mb-4 text-center font-mono text-4xl tracking-[0.5em]" aria-label={t('gapi.kiosk.pin_len', { n: pin.length })}>{pin ? '•'.repeat(pin.length) : '—'}</p>
                            <div className="mx-auto grid max-w-sm grid-cols-3 gap-3" role="group" aria-label={t('gapi.kiosk.keypad')}>
                                {['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clr', '0', 'del'].map((d) => (
                                    <button key={d} type="button" className="btn-secondary h-16 text-2xl" onClick={() => key(d)} aria-label={d === 'del' ? t('gapi.kiosk.del') : d === 'clr' ? t('gapi.kiosk.clear') : d}>
                                        {d === 'del' ? '⌫' : d === 'clr' ? t('gapi.kiosk.clear') : d}</button>))}
                            </div>
                            {needPhoto && (
                                <label className="mx-auto mt-4 block max-w-sm text-base">{t('gapi.kiosk.photo')}
                                    <input type="file" accept="image/*" capture="user" className="input mt-1 w-full" onChange={(e) => pickPhoto(e.target.files?.[0])} /></label>
                            )}
                            <div className="mx-auto mt-6 grid max-w-sm grid-cols-2 gap-3">
                                <button type="button" className="btn-primary h-20 text-2xl" disabled={busy || pin.length < 4 || (needPhoto && !photo)} onClick={() => punch('in')}>{t('gapi.kiosk.in')}</button>
                                <button type="button" className="btn-accent h-20 text-2xl" disabled={busy || pin.length < 4 || (needPhoto && !photo)} onClick={() => punch('out')}>{t('gapi.kiosk.out')}</button>
                            </div>
                        </>
                    )}
                </section>

                <aside className="card p-4" aria-labelledby="kiosk-recent">
                    <h2 id="kiosk-recent" className="mb-3 text-lg font-semibold">{t('gapi.kiosk.recent')}</h2>
                    {board.data && board.data.recent.length === 0 && <p className="text-slate-700">{t('gapi.kiosk.no_recent')}</p>}
                    <ul className="divide-y divide-slate-100">{board.data?.recent.map((p, i) => (
                        <li key={i} className="flex items-center justify-between gap-2 py-2 text-base">
                            <span>{p.name}</span>
                            <span className="flex items-center gap-2"><span className={p.direction === 'in' ? 'badge-green' : 'badge-slate'}>{t(`gapi.kiosk.${p.direction}`)}</span><span className="num text-slate-700">{hhmm(new Date(p.at))}</span></span>
                        </li>))}</ul>
                </aside>
            </main>
        </div>
    );
}
