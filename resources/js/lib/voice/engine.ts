import { api } from '@/lib/api';

/**
 * Voice alerts, browser side. Everything the server decides (what to say, in which language, for which
 * departments) arrives ready to speak in the feed; this file only moves it and decides when it is quiet.
 */
export interface VoiceSettings {
    enabled: boolean;
    departments: string[];
    repeat_seconds: number;
    quiet_enabled: boolean;
    quiet_start: string;
    quiet_end: string;
    volume: number;
    rate: number;
    voice: string;
    chime: boolean;
}

export interface FeedEvent {
    id: number;
    kind: 'request' | 'late' | 'overdue' | 'message';
    request_id: number | null;
    room_number: string;
    department: string;
    category: string | null;
    priority: string;
    status: string | null;
    text: string;
    speech: string;
}

export interface PendingRequest {
    request_id: number;
    room_number: string;
    text: string;
    speech: string;
}

export interface FeedResponse {
    events: FeedEvent[];
    cursor: number;
    pending: PendingRequest[];
    locale: 'fr' | 'en';
}

export const POLL_MS = 4000;

/**
 * The only function that knows how events travel (polling today). Swap the body for SSE or a websocket
 * later; callers just ask for what is new after a cursor. `since === null` returns the starting cursor only.
 */
export function fetchFeed(since: number | null, locale: string): Promise<FeedResponse> {
    const q = new URLSearchParams({ locale });
    if (since !== null) q.set('since', String(since));
    return api<FeedResponse>(`service-requests/feed?${q}`);
}

const minutes = (hhmm: string) => {
    const [h, m] = hhmm.split(':').map(Number);
    return (h || 0) * 60 + (m || 0);
};

/** True when `now` falls in the quiet window; the window may cross midnight (22:00 to 06:00). */
export function inQuietHours(now: Date, start: string, end: string): boolean {
    const cur = now.getHours() * 60 + now.getMinutes();
    const s = minutes(start);
    const e = minutes(end);
    if (s === e) return false;
    return s < e ? cur >= s && cur < e : cur >= s || cur < e;
}

/** Whether an event should still be announced when it is played (it may have been handled while queued). */
export function stillRelevant(e: Pick<FeedEvent, 'kind' | 'status'>): boolean {
    if (e.kind === 'request' || e.kind === 'late') return e.status === null || e.status === 'new';
    return true;
}
