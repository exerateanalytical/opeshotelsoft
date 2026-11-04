import { useEffect, useRef, useState } from 'react';
import { useT } from '@/lib/i18n';
import MemberLayout from './MemberLayout';
import { mapi, MemberApiError } from './api';

interface Msg { id: number; from: 'me' | 'reception'; body: string; at: string }
interface Thread { hotel: { id: number; code: string; name: string }; messages: Msg[]; last_id: number }

export default function Chat({ hotels, thread }: { hotels: { id: number; code: string; name: string }[]; thread: Thread }) {
    const t = useT();
    const [hotel, setHotel] = useState(thread.hotel.id);
    const [msgs, setMsgs] = useState<Msg[]>(thread.messages);
    const [text, setText] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const last = useRef(thread.last_id);
    const end = useRef<HTMLDivElement>(null);

    // Reception replies appear here: poll every 5 seconds.
    useEffect(() => {
        const id = setInterval(async () => {
            try {
                const r = await mapi<Thread>('GET', `/account/chat/messages?hotel=${hotel}&after=${last.current}`);
                if (r.messages.length) {
                    last.current = r.last_id;
                    setMsgs((m) => [...m, ...r.messages.filter((x) => !m.some((y) => y.id === x.id))]);
                }
            } catch { /* offline: try again on the next tick */ }
        }, 5000);
        return () => clearInterval(id);
    }, [hotel]);
    useEffect(() => { end.current?.scrollIntoView({ block: 'end' }); }, [msgs.length]);

    async function switchHotel(id: number) {
        setHotel(id);
        const r = await mapi<Thread>('GET', `/account/chat/messages?hotel=${id}`);
        last.current = r.last_id;
        setMsgs(r.messages);
    }
    async function send(e: React.FormEvent) {
        e.preventDefault();
        if (!text.trim()) return;
        setBusy(true); setError('');
        try {
            const m = await mapi<Msg>('POST', '/account/chat', { text, hotel });
            last.current = Math.max(last.current, m.id);
            setMsgs((x) => [...x, m]);
            setText('');
        } catch (err) { setError(err instanceof MemberApiError ? err.message : String(err)); }
        setBusy(false);
    }

    return (
        <MemberLayout title={t('member.chat.title')}>
            <div className="card flex flex-col h-[calc(100dvh-14rem)] min-h-[360px]">
                <div className="p-3 border-b border-slate-200 flex items-center gap-2">
                    <label htmlFor="chat-hotel" className="text-sm">{t('member.chat.hotel')}</label>
                    <select id="chat-hotel" className="select" value={hotel} onChange={(e) => switchHotel(Number(e.target.value))}>{hotels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select>
                </div>
                <div className="flex-1 overflow-y-auto p-3 space-y-2" aria-live="polite">
                    {msgs.length === 0 && <p className="text-slate-500 text-sm">{t('member.chat.empty')}</p>}
                    {msgs.map((m) => (
                        <div key={m.id} className={`max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap ${m.from === 'me' ? 'ml-auto bg-navy-900 text-white' : 'bg-slate-100'}`}>
                            {m.from === 'reception' && <span className="block text-sm font-semibold text-navy-500">{t('member.chat.reception')}</span>}
                            {m.body}
                            <span className={`block text-xs mt-1 ${m.from === 'me' ? 'text-slate-300' : 'text-slate-500'}`}>{new Date(m.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                        </div>
                    ))}
                    <div ref={end} />
                </div>
                <form onSubmit={send} className="p-3 border-t border-slate-200 flex gap-2">
                    <input className="input" value={text} maxLength={1000} onChange={(e) => setText(e.target.value)} placeholder={t('member.chat.placeholder')} aria-label={t('member.chat.placeholder')} />
                    <button className="btn-primary" disabled={busy || !text.trim()}>{t('member.chat.send')}</button>
                </form>
                {error && <p role="alert" className="px-3 pb-3 text-sm text-red-700">{error}</p>}
            </div>
            <p className="text-sm text-slate-600">{t('member.chat.hint')}</p>
        </MemberLayout>
    );
}

