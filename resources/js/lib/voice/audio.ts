import { useSyncExternalStore } from 'react';
import type { VoiceSettings } from './engine';

/**
 * Chime and speech through the Web Speech API (no dependency). Browsers refuse audio until the user has
 * clicked once, so `unlock()` must run from a user gesture; the status tells the UI which state it is in.
 */
export interface VoiceStatus {
    soundOn: boolean;
    unlocked: boolean;
    noVoiceFor: string | null;
    lastHeard: string | null;
}

const KEY = 'voice.sound';
const read = (): boolean => {
    try {
        return localStorage.getItem(KEY) === 'on';
    } catch {
        return false;
    }
};

let status: VoiceStatus = { soundOn: read(), unlocked: false, noVoiceFor: null, lastHeard: null };
const listeners = new Set<() => void>();
const set = (patch: Partial<VoiceStatus>) => {
    status = { ...status, ...patch };
    listeners.forEach((l) => l());
};

let ctx: AudioContext | null = null;
const synth = (): SpeechSynthesis | null => (typeof window !== 'undefined' && 'speechSynthesis' in window ? window.speechSynthesis : null);

export const voiceStatus = () => status;
export const useVoiceStatus = () =>
    useSyncExternalStore((cb) => (listeners.add(cb), () => listeners.delete(cb)), voiceStatus, voiceStatus);

export function setSoundOn(on: boolean) {
    try {
        localStorage.setItem(KEY, on ? 'on' : 'off');
    } catch {
        /* private mode: the choice lasts for this page only */
    }
    set({ soundOn: on });
    if (on) unlock();
    else synth()?.cancel();
}

/** Call from a click or key press. Safe to call again. */
export function unlock() {
    try {
        ctx ??= new (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)();
        void ctx.resume();
        const s = synth();
        if (s && !status.unlocked) s.speak(new SpeechSynthesisUtterance(''));
    } catch {
        /* no audio support: the visual toast still works */
    }
    if (ctx && ctx.state !== 'closed') set({ unlocked: true });
}

/** If the user said "sound on" on an earlier visit, the first click anywhere unlocks audio again. */
export function unlockOnFirstGesture() {
    if (!status.soundOn || status.unlocked) return () => {};
    const go = () => unlock();
    window.addEventListener('pointerdown', go, { once: true });
    window.addEventListener('keydown', go, { once: true });
    return () => {
        window.removeEventListener('pointerdown', go);
        window.removeEventListener('keydown', go);
    };
}

export function voices(): SpeechSynthesisVoice[] {
    return synth()?.getVoices() ?? [];
}

export const langTag = (locale: string) => (locale === 'en' ? 'en-US' : 'fr-FR');

/** Voice to use: the user's pick when it fits the language, else a local voice of that language. */
export function pickVoice(locale: string, name: string): SpeechSynthesisVoice | null {
    const all = voices().filter((v) => v.lang.toLowerCase().startsWith(locale));
    return all.find((v) => v.name === name) ?? all.find((v) => v.localService) ?? all[0] ?? null;
}

/** Voices load lazily in some browsers, so an empty list means "unknown", not "none". */
export function hasVoiceFor(locale: string): boolean {
    if (!synth()) return false;
    const all = voices();
    return all.length === 0 || all.some((v) => v.lang.toLowerCase().startsWith(locale));
}

export async function chime(volume: number): Promise<void> {
    if (!ctx || ctx.state !== 'running') return;
    const t0 = ctx.currentTime;
    [880, 1175].forEach((freq, i) => {
        const osc = ctx!.createOscillator();
        const gain = ctx!.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        const start = t0 + i * 0.18;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(Math.max(0.0002, 0.4 * volume), start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.17);
        osc.connect(gain).connect(ctx!.destination);
        osc.start(start);
        osc.stop(start + 0.18);
    });
    await new Promise((r) => setTimeout(r, 400));
}

/** Speak and resolve when finished (or after a safety timeout). Returns false when no voice could be used. */
export function speak(text: string, locale: string, s: Pick<VoiceSettings, 'volume' | 'rate' | 'voice'>): Promise<boolean> {
    const sy = synth();
    if (!sy || !hasVoiceFor(locale)) {
        set({ noVoiceFor: locale });
        return Promise.resolve(false);
    }
    set({ noVoiceFor: null });
    return new Promise((resolve) => {
        const u = new SpeechSynthesisUtterance(text);
        u.lang = langTag(locale);
        u.volume = s.volume;
        u.rate = s.rate;
        const v = pickVoice(locale, s.voice);
        if (v) u.voice = v;
        const done = (ok: boolean) => {
            clearTimeout(timer);
            resolve(ok);
        };
        const timer = setTimeout(() => done(true), 20000);
        u.onend = () => done(true);
        u.onerror = () => done(false);
        sy.speak(u);
    });
}

export const markHeard = (text: string) => set({ lastHeard: text });
