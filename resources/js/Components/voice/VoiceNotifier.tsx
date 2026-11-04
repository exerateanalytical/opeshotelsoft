import { Link, usePage } from '@inertiajs/react';
import { Volume2, VolumeX, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { api } from '@/lib/api';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { useLocale, useT } from '@/lib/i18n';
import { chime, markHeard, setSoundOn, speak, unlock, unlockOnFirstGesture, useVoiceStatus, voiceStatus } from '@/lib/voice/audio';
import { fetchFeed, inQuietHours, POLL_MS, stillRelevant, type FeedEvent, type VoiceSettings } from '@/lib/voice/engine';

interface Toast {
    key: string;
    text: string;
    kind: string;
}

interface Item {
    key: string;
    event: Pick<FeedEvent, 'kind' | 'status' | 'text' | 'speech'>;
}

/** Mounted once in the staff layout. Polls the feed, queues and speaks alerts, shows a toast for each. */
export default function VoiceNotifier() {
    const t = useT();
    const locale = useLocale();
    const props = usePage<{ auth?: { user?: { permissions?: string[] } | null }; property?: { id: number } | null }>().props;
    const allowed = props.auth?.user?.permissions?.includes('servicerequests.voice') ?? false;
    const hotelId = props.property?.id ?? null;
    const status = useVoiceStatus();
    const phone = useMediaQuery('(max-width: 1023px)'); // phone and tablet: control lives in the app bar
    const [slot, setSlot] = useState<HTMLElement | null>(null);
    useEffect(() => {
        setSlot(phone ? document.getElementById('voice-slot') : null);
    }, [phone, allowed]);
    const [toasts, setToasts] = useState<Toast[]>([]);
    const settings = useRef<VoiceSettings | null>(null);
    const cursor = useRef<number | null>(null);
    const queue = useRef<Item[]>([]);
    const playing = useRef(false);
    const seen = useRef(new Map<string, number>());
    const lastSpoken = useRef(new Map<number, number>());
    const pending = useRef<{ request_id: number; speech: string; text: string }[]>([]);

    const loadSettings = useCallback(() => {
        api<{ settings: VoiceSettings }>(`service-requests/voice-settings?locale=${locale}`).then((r) => (settings.current = r.settings)).catch(() => {});
    }, [locale]);

    const pump = useCallback(async () => {
        if (playing.current) return;
        playing.current = true;
        while (queue.current.length) {
            const item = queue.current.shift()!;
            const s = settings.current;
            const st = voiceStatus();
            if (!s || !s.enabled || !st.soundOn || !st.unlocked || !stillRelevant(item.event)) continue;
            if (s.quiet_enabled && inQuietHours(new Date(), s.quiet_start, s.quiet_end)) continue;
            if (s.chime) await chime(s.volume);
            const ok = await speak(item.event.speech, locale, s);
            if (ok) markHeard(item.event.text);
        }
        playing.current = false;
    }, [locale]);

    const enqueue = useCallback((key: string, event: Item['event'], requestId?: number | null) => {
        const now = Date.now();
        if (now - (seen.current.get(key) ?? 0) < 30000) return; // dedupe
        seen.current.set(key, now);
        if (requestId) lastSpoken.current.set(requestId, now);
        queue.current.push({ key, event });
        void pump();
    }, [pump]);

    useEffect(() => {
        if (!allowed) return;
        loadSettings();
        window.addEventListener('voice:settings', loadSettings);
        const stop = unlockOnFirstGesture();
        return () => {
            window.removeEventListener('voice:settings', loadSettings);
            stop();
        };
    }, [allowed, loadSettings]);

    useEffect(() => {
        if (!allowed) return;
        cursor.current = null;
        let busy = false;
        let alive = true;
        const tick = async () => {
            if (busy) return;
            busy = true;
            try {
                const r = await fetchFeed(cursor.current, locale);
                if (!alive) return;
                if (cursor.current !== null && settings.current?.enabled !== false) {
                    for (const e of r.events) {
                        if (!stillRelevant(e)) continue;
                        setToasts((x) => [...x.slice(-3), { key: `e${e.id}`, text: e.text, kind: e.kind }]);
                        setTimeout(() => setToasts((x) => x.filter((y) => y.key !== `e${e.id}`)), 12000);
                        enqueue(`e${e.id}`, e, e.request_id);
                    }
                }
                cursor.current = r.cursor;
                pending.current = r.pending;
            } catch {
                /* offline or signed out: try again on the next tick */
            } finally {
                busy = false;
            }
        };
        void tick();
        const poll = setInterval(tick, POLL_MS);
        // Repeat until acknowledged: every N seconds re-announce what is still new.
        const repeat = setInterval(() => {
            const s = settings.current;
            if (!s || !s.repeat_seconds) return;
            const now = Date.now();
            for (const p of pending.current) {
                const last = lastSpoken.current.get(p.request_id);
                if (last === undefined) lastSpoken.current.set(p.request_id, now);
                else if (now - last >= s.repeat_seconds * 1000) enqueue(`r${p.request_id}:${now}`, { kind: 'request', status: 'new', text: p.text, speech: p.speech }, p.request_id);
            }
        }, 5000);
        return () => {
            alive = false;
            clearInterval(poll);
            clearInterval(repeat);
        };
    }, [allowed, hotelId, locale, enqueue]);

    if (!allowed) return null;

    const active = status.soundOn && status.unlocked;
    const label = active ? t('servicerequests.voice.sound_on') : status.soundOn ? t('servicerequests.voice.sound_click') : t('servicerequests.voice.sound_off');
    const tone = active ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : status.soundOn ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-slate-300 bg-white text-slate-700';

    const toggle = () => (active ? setSoundOn(false) : (setSoundOn(true), unlock()));
    const detail = [active && status.lastHeard ? t('servicerequests.voice.last_heard', { text: status.lastHeard }) : null, status.noVoiceFor ? t('servicerequests.voice.no_voice', { lang: status.noVoiceFor.toUpperCase() }) : null].filter(Boolean).join('. ');
    const Icon = active ? Volume2 : VolumeX;
    // Phone: icon-only 44px control inside the app bar (never over page actions); state is visible by icon + border colour
    // and announced through a polite live region. Toasts drop in under the app bar.
    const phoneChip = slot && createPortal(
        <button type="button" onClick={toggle} title={label} aria-label={`${t('servicerequests.voice.sound_toggle')}: ${label}`} aria-pressed={active}
            className={`flex h-11 w-11 items-center justify-center rounded-lg border ${tone}`}>
            <Icon className="h-5 w-5" aria-hidden="true" />
            <span className="sr-only" role="status" aria-live="polite">{label}{detail ? `. ${detail}` : ''}</span>
        </button>,
        slot,
    );

    if (slot) {
        return (
            <>
                {phoneChip}
                <div className="fixed inset-x-3 md:left-auto md:w-96 top-[calc(var(--bar-top-h)+var(--safe-top)+8px)] z-[70] flex flex-col items-stretch gap-2">
                    {toasts.map((x) => (
                        <Link key={x.key} href="/service-requests" className={`flex min-h-11 items-start gap-2 rounded-lg border px-3 py-2 text-sm font-medium ${x.kind === 'overdue' ? 'border-red-300 bg-red-50 text-red-800' : 'border-slate-300 bg-white text-slate-900'}`}>
                            <span className="flex-1">{x.text}</span>
                            <button type="button" className="-m-2 flex h-11 w-11 items-center justify-center" aria-label={t('servicerequests.voice.dismiss')} onClick={(e) => { e.preventDefault(); setToasts((y) => y.filter((z) => z.key !== x.key)); }}>
                                <X className="h-4 w-4" aria-hidden="true" />
                            </button>
                        </Link>
                    ))}
                </div>
            </>
        );
    }

    return (
        <div className="above-tabbar fixed right-4 z-50 flex max-w-[calc(100vw-2rem)] flex-col items-end gap-2">
            {toasts.map((x) => (
                <Link key={x.key} href="/service-requests" className={`flex items-start gap-2 rounded-lg border px-3 py-2 text-sm font-medium ${x.kind === 'overdue' ? 'border-red-300 bg-red-50 text-red-800' : 'border-slate-300 bg-white text-slate-900'}`}>
                    <span>{x.text}</span>
                    <button type="button" aria-label={t('servicerequests.voice.dismiss')} onClick={(e) => { e.preventDefault(); setToasts((y) => y.filter((z) => z.key !== x.key)); }}>
                        <X className="h-4 w-4" />
                    </button>
                </Link>
            ))}
            <button type="button" onClick={() => (active ? setSoundOn(false) : (setSoundOn(true), unlock()))}
                className={`flex min-h-[44px] items-center gap-2 rounded-lg border px-3 py-2 text-[13px] font-semibold ${tone}`} title={t('servicerequests.voice.sound_toggle')}>
                {active ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />}
                <span className="text-left">
                    {label}
                    {status.lastHeard && active && <span className="block font-normal">{t('servicerequests.voice.last_heard', { text: status.lastHeard })}</span>}
                    {status.noVoiceFor && <span className="block font-normal">{t('servicerequests.voice.no_voice', { lang: status.noVoiceFor.toUpperCase() })}</span>}
                </span>
            </button>
        </div>
    );
}
