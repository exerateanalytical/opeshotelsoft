import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';
import { chime, setSoundOn, speak, unlock, useVoiceStatus, voices } from '@/lib/voice/audio';
import type { VoiceSettings } from '@/lib/voice/engine';

const CATEGORIES = ['housekeeping', 'maintenance', 'room_service', 'reception', 'concierge', 'laundry', 'other'];

export default function Settings() {
    const t = useT();
    const locale = useLocale();
    const perms = (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
    const canVoice = perms.includes('servicerequests.voice');
    const canManage = perms.includes('servicerequests.manage');
    const status = useVoiceStatus();
    const mine = useApi<{ settings: VoiceSettings; departments: string[] }>(canVoice ? 'service-requests/voice-settings' : null);
    const hotel = useApi<{ sla: Record<string, number>; defaults: Record<string, number>; voice_messages: boolean }>('service-requests/settings');
    const [s, setS] = useState<VoiceSettings | null>(null);
    const [sla, setSla] = useState<Record<string, number>>({});
    const [vm, setVm] = useState(true);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [, setTick] = useState(0);

    useEffect(() => { if (mine.data) setS(mine.data.settings); }, [mine.data]);
    useEffect(() => { if (hotel.data) { setSla(hotel.data.sla); setVm(hotel.data.voice_messages); } }, [hotel.data]);
    useEffect(() => { // voices load lazily
        const sy = window.speechSynthesis;
        const h = () => setTick((x) => x + 1);
        sy?.addEventListener('voiceschanged', h);
        return () => sy?.removeEventListener('voiceschanged', h);
    }, []);

    const fail = (e: unknown) => setMsg({ ok: false, text: e instanceof ApiError ? e.message : t('servicerequests.error.generic') });

    async function saveMine() {
        try {
            await api('service-requests/voice-settings', { method: 'PUT', body: JSON.stringify(s) });
            window.dispatchEvent(new Event('voice:settings'));
            setMsg({ ok: true, text: t('servicerequests.saved') });
        } catch (e) { fail(e); }
    }

    async function saveHotel() {
        try {
            await api('service-requests/settings', { method: 'PUT', body: JSON.stringify({ sla, voice_messages: vm }) });
            setMsg({ ok: true, text: t('servicerequests.saved') });
        } catch (e) { fail(e); }
    }

    async function test() {
        if (!s) return;
        unlock();
        if (s.chime) await chime(s.volume);
        await speak(t('servicerequests.voice.sample'), locale, s);
    }

    const set = <K extends keyof VoiceSettings>(k: K, v: VoiceSettings[K]) => s && setS({ ...s, [k]: v });
    const localVoices = voices().filter((v) => v.lang.toLowerCase().startsWith(locale));

    return (
        <AppLayout title={t('servicerequests.settings')} subtitle={t('servicerequests.voice.subtitle')}>
            <Head title={t('servicerequests.settings')} />
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            {canVoice && s && (
                <section className="card mb-6 grid max-w-2xl gap-4 p-4 text-sm">
                    <h2 className="font-semibold">{t('servicerequests.voice.section_mine')}</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="font-medium">{status.soundOn && status.unlocked ? t('servicerequests.voice.sound_on') : status.soundOn ? t('servicerequests.voice.sound_click') : t('servicerequests.voice.sound_off')}</span>
                        <button type="button" className="btn-secondary" onClick={() => setSoundOn(!status.soundOn)}>
                            {status.soundOn ? t('servicerequests.voice.disable') : t('servicerequests.voice.enable')}
                        </button>
                        <button type="button" className="btn-secondary" onClick={test}>{t('servicerequests.voice.test')}</button>
                    </div>
                    <p className="text-slate-600">{t('servicerequests.voice.browser_note')}</p>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={s.enabled} onChange={(e) => set('enabled', e.target.checked)} />{t('servicerequests.voice.enabled')}</label>
                    <fieldset>
                        <legend className="mb-1 font-medium">{t('servicerequests.voice.departments')}</legend>
                        <div className="flex flex-wrap gap-3">
                            {mine.data?.departments.map((d) => (
                                <label key={d} className="flex items-center gap-1">
                                    <input type="checkbox" checked={s.departments.includes(d)} onChange={(e) => set('departments', e.target.checked ? [...s.departments, d] : s.departments.filter((x) => x !== d))} />
                                    {t(`servicerequests.dept.${d}`)}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <label className="grid gap-1">{t('servicerequests.voice.repeat')}
                        <select className="input" value={s.repeat_seconds} onChange={(e) => set('repeat_seconds', Number(e.target.value))}>
                            {[0, 30, 60, 120, 300].map((n) => <option key={n} value={n}>{n === 0 ? t('servicerequests.voice.repeat_off') : t('servicerequests.voice.repeat_every', { seconds: n })}</option>)}
                        </select>
                    </label>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2"><input type="checkbox" checked={s.quiet_enabled} onChange={(e) => set('quiet_enabled', e.target.checked)} />{t('servicerequests.voice.quiet')}</label>
                        <input type="time" className="input" value={s.quiet_start} onChange={(e) => set('quiet_start', e.target.value)} aria-label={t('servicerequests.voice.quiet_from')} />
                        <input type="time" className="input" value={s.quiet_end} onChange={(e) => set('quiet_end', e.target.value)} aria-label={t('servicerequests.voice.quiet_to')} />
                    </div>
                    <label className="grid gap-1">{t('servicerequests.voice.volume')} ({Math.round(s.volume * 100)} %)
                        <input type="range" min={0} max={1} step={0.05} value={s.volume} onChange={(e) => set('volume', Number(e.target.value))} />
                    </label>
                    <label className="grid gap-1">{t('servicerequests.voice.rate')} ({s.rate.toFixed(2)})
                        <input type="range" min={0.5} max={1.5} step={0.05} value={s.rate} onChange={(e) => set('rate', Number(e.target.value))} />
                    </label>
                    <label className="grid gap-1">{t('servicerequests.voice.voice')}
                        <select className="input" value={s.voice} onChange={(e) => set('voice', e.target.value)}>
                            <option value="">{t('servicerequests.voice.voice_auto')}</option>
                            {localVoices.map((v) => <option key={v.name} value={v.name}>{v.name} ({v.lang})</option>)}
                        </select>
                    </label>
                    {localVoices.length === 0 && <p className="text-amber-700">{t('servicerequests.voice.no_voice', { lang: locale.toUpperCase() })}</p>}
                    <label className="flex items-center gap-2"><input type="checkbox" checked={s.chime} onChange={(e) => set('chime', e.target.checked)} />{t('servicerequests.voice.chime')}</label>
                    <div><button type="button" className="btn-primary" onClick={saveMine}>{t('servicerequests.save')}</button></div>
                </section>
            )}
            {hotel.data && (
                <section className="card grid max-w-2xl gap-3 p-4 text-sm">
                    <h2 className="font-semibold">{t('servicerequests.sla.title')}</h2>
                    <p className="text-slate-600">{t('servicerequests.sla.help')}</p>
                    {CATEGORIES.map((c) => (
                        <label key={c} className="flex items-center justify-between gap-3">
                            <span>{t(`servicerequests.cat.${c}`)} ({t(`servicerequests.dept.${c === 'maintenance' ? 'maintenance' : ['room_service'].includes(c) ? 'fnb' : ['housekeeping', 'laundry'].includes(c) ? 'housekeeping' : 'front_office'}`)})</span>
                            <span className="flex items-center gap-2">
                                <input type="number" min={1} max={1440} className="input w-24" disabled={!canManage} value={sla[c] ?? ''} onChange={(e) => setSla({ ...sla, [c]: Number(e.target.value) })} />
                                {t('servicerequests.sla.minutes')}
                            </span>
                        </label>
                    ))}
                    <label className="flex items-center gap-2"><input type="checkbox" disabled={!canManage} checked={vm} onChange={(e) => setVm(e.target.checked)} />{t('servicerequests.sla.voice_messages')}</label>
                    {canManage && <div><button type="button" className="btn-primary" onClick={saveHotel}>{t('servicerequests.save')}</button></div>}
                </section>
            )}
        </AppLayout>
    );
}
