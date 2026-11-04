import { useCallback, useEffect, useState } from 'react';

/**
 * Desktop shell helpers (workstream N1): safe storage, shortcut preference, the page events the
 * command palette and help dialog listen to, a "typing" guard and a query-parameter hook.
 */

export const PALETTE_EVENT = 'opes:palette';
export const HELP_EVENT = 'opes:help';
const SHORTCUTS_KEY = 'opes.shortcuts';
const SHORTCUTS_EVENT = 'opes:shortcuts-changed';

export function safeGet(key: string): string | null {
    try { return localStorage.getItem(key); } catch { return null; }
}

export function safeSet(key: string, value: string | null) {
    try { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); } catch { /* storage unavailable */ }
}

export function readJson<T>(key: string, fallback: T): T {
    const raw = safeGet(key);
    if (!raw) return fallback;
    try { return JSON.parse(raw) as T; } catch { return fallback; }
}

export const openPalette = () => window.dispatchEvent(new Event(PALETTE_EVENT));
export const openHelp = () => window.dispatchEvent(new Event(HELP_EVENT));

export const shortcutsEnabled = () => safeGet(SHORTCUTS_KEY) !== '0';

export function setShortcutsEnabled(on: boolean) {
    safeSet(SHORTCUTS_KEY, on ? null : '0');
    window.dispatchEvent(new Event(SHORTCUTS_EVENT));
}

/** Reactive shortcut on/off preference (the off switch lives in the shortcuts help dialog). */
export function useShortcutsEnabled(): [boolean, (on: boolean) => void] {
    const [on, setOn] = useState(shortcutsEnabled);
    useEffect(() => {
        const h = () => setOn(shortcutsEnabled());
        window.addEventListener(SHORTCUTS_EVENT, h);
        return () => window.removeEventListener(SHORTCUTS_EVENT, h);
    }, []);
    return [on, setShortcutsEnabled];
}

/** True when a key press belongs to a text control and must not fire single-key shortcuts. */
export function isTypingTarget(el: EventTarget | null): boolean {
    if (!(el instanceof HTMLElement)) return false;
    if (el.isContentEditable) return true;
    const tag = el.tagName;
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag === 'INPUT') {
        const type = (el as HTMLInputElement).type;
        return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'file'].includes(type);
    }
    const role = el.getAttribute('role');
    return role === 'textbox' || role === 'combobox' || role === 'searchbox';
}

/** A modal dialog or the palette is open: single-key page shortcuts stand down. */
export const dialogOpen = () => !!document.querySelector('[role="dialog"][aria-modal="true"]');

/**
 * String query parameter kept in the address bar with history.replaceState (the Inertia state object is preserved),
 * so a split-view selection is shareable. Returns [value, set].
 */
export function useQueryParam(name: string): [string | null, (v: string | null) => void] {
    const read = () => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get(name));
    const [value, setValue] = useState<string | null>(read);
    const set = useCallback((v: string | null) => {
        const url = new URL(window.location.href);
        if (v === null || v === '') url.searchParams.delete(name); else url.searchParams.set(name, v);
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        setValue(v === '' ? null : v);
    }, [name]);
    useEffect(() => {
        const h = () => setValue(read());
        window.addEventListener('popstate', h);
        return () => window.removeEventListener('popstate', h);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [name]);
    return [value, set];
}

/** Subsequence fuzzy score: higher is better, -1 means no match. Prefix and word-start matches rank first. */
export function fuzzyScore(query: string, text: string): number {
    const q = query.trim().toLowerCase();
    if (!q) return 0;
    const s = text.toLowerCase();
    const idx = s.indexOf(q);
    if (idx >= 0) return 1000 - idx * 4 - (s.length - q.length) + (idx === 0 || s[idx - 1] === ' ' ? 200 : 0);
    let pos = 0;
    let score = 0;
    let last = -2;
    for (const ch of q) {
        const at = s.indexOf(ch, pos);
        if (at < 0) return -1;
        score += at === last + 1 ? 8 : 2;
        if (at === 0 || s[at - 1] === ' ') score += 6;
        last = at;
        pos = at + 1;
    }
    return score;
}
