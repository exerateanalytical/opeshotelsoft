import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useMt } from './strings';

interface Conv {
    id: number; channel?: string; wa_id: string; name: string | null; status: 'open' | 'closed'; assigned_to: number | null; unread: number;
    last_message_at: string | null; window_open: boolean; window_closes_at: string | null; opted_out: boolean; locale: string;
    last: { body: string | null; type: string; direction: string } | null;
}
interface Msg { id: number; direction: 'in' | 'out'; type: string; body: string | null; template_name: string | null; journey: string | null; status: string; error: string | null; dry_run: boolean; created_at: string }
interface Detail extends Conv { messages: Msg[]; reservation: { number: string; status: string; arrival: string; departure: string; room: string | null } | null }
interface QuickReply { id: number; title: string; body: string; locale: string }

const JOURNEYS = ['booking_confirmation', 'pre_arrival', 'welcome', 'bill', 'review'];

export default function MessagingInbox() {
    const t = useMt();
    const [filter, setFilter] = useState('open');
    const [q, setQ] = useState('');
    const [sel, setSel] = useState<number | null>(null);
    const [text, setText] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [qr, setQr] = useState({ title: '', body: '' });
    const params = new URLSearchParams(filter === 'mine' || filter === 'none' ? { assigned: filter } : { status: filter });
    if (q) params.set('q', q);
    const list = useApi<{ data: Conv[] }>(`messaging/conversations?${params}`);
    const detail = useApi<Detail>(sel ? `messaging/conversations/${sel}` : null);
    const quick = useApi<QuickReply[]>('messaging/quick-replies');
    const staff = useApi<{ id: number; name: string }[]>('messaging/staff');
    const reg = useApi<{ dry_run: boolean }>('messaging/templates');

    useEffect(() => {
        const id = setInterval(() => { list.reload(); if (sel) detail.reload(); }, 15000);
        return () => clearInterval(id);
    }, [sel]);

    async function run(fn: () => Promise<unknown>) {
        setBusy(true); setError('');
        try { await fn(); detail.reload(); list.reload(); } catch (e) { setError(e instanceof ApiError ? e.message : String(e)); }
        setBusy(false);
    }
    const post = (path: string, body: object) => api(`messaging/conversations/${sel}/${path}`, { method: 'POST', body: JSON.stringify(body) });
    const send = (e: FormEvent) => { e.preventDefault(); if (text.trim()) run(async () => { await post('reply', { text }); setText(''); }); };
    const d = detail.data;

    return (
        <AppLayout title={t('messaging.inbox.title')} subtitle={t('messaging.inbox.subtitle')}
            actions={<Link href="/messaging/templates" className="btn-secondary">{t('messaging.templates.title')}</Link>}>
            <Head title={t('messaging.inbox.title')} />
            <QueryStatus query={list} variant="list" />
            {reg.data?.dry_run && <p className="mb-3 text-sm text-slate-600">{t('messaging.dry_run')}</p>}
            <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
                <div className="card p-3 space-y-3">
                    <div className="flex flex-wrap gap-1">
                        {['open', 'mine', 'none', 'closed', 'all'].map((f) => (
                            <button key={f} type="button" className={filter === f ? 'btn-primary' : 'btn-secondary'} onClick={() => setFilter(f)}>
                                {t(`messaging.filter.${f === 'none' ? 'unassigned' : f}`)}
                            </button>
                        ))}
                    </div>
                    <input className="input" aria-label={t('messaging.search')} placeholder={t('messaging.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <ul className="divide-y divide-slate-200">
                        {list.data?.data.length === 0 && <li><EmptyFor kind="messages" /></li>}
                        {list.data?.data.map((c) => (
                            <li key={c.id}>
                                <button type="button" onClick={() => setSel(c.id)} className={`w-full py-2 px-2 text-left ${sel === c.id ? 'bg-slate-100' : ''}`}>
                                    <div className="flex justify-between gap-2">
                                        <span className="font-medium truncate">{c.name ?? `+${c.wa_id}`}</span>{c.channel === "web" && <span className="badge-slate">{t("messaging.channel.web")}</span>}
                                        {c.unread > 0 && <span className="badge-blue">{c.unread}</span>}
                                    </div>
                                    <div className="text-sm text-slate-500 truncate">{c.last?.body ?? ''}</div>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="card p-4 flex flex-col min-h-[480px]">
                    {!d && <p className="text-slate-500">{t('messaging.select')}</p>}
                    {d && (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3">
                                <div>
                                    <div className="font-medium">{d.name ?? `+${d.wa_id}`} {d.channel === "web" ? <span className="badge-slate">{t("messaging.channel.web")}</span> : <span className="text-slate-500">+{d.wa_id}</span>}</div>
                                    {d.reservation && <div className="text-sm text-slate-500">{t('messaging.stay')} {d.reservation.number} · {d.reservation.arrival} → {d.reservation.departure}{d.reservation.room ? ` · ${t('messaging.room')} ${d.reservation.room}` : ''}</div>}
                                </div>
                                <div className="flex gap-2 items-center">
                                    <label className="text-sm" htmlFor="assign">{t('messaging.assign')}</label>
                                    <select id="assign" className="select" value={d.assigned_to ?? ''} onChange={(e) => run(() => post('assign', { user_id: e.target.value ? Number(e.target.value) : null }))}>
                                        <option value="">{t('messaging.unassigned')}</option>
                                        {staff.data?.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                    </select>
                                    <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(() => post('status', { status: d.status === 'open' ? 'closed' : 'open' }))}>
                                        {t(d.status === 'open' ? 'messaging.close' : 'messaging.reopen')}
                                    </button>
                                </div>
                            </div>
                            <div className="flex-1 overflow-y-auto py-3 space-y-2">
                                {d.messages.map((m) => (
                                    <div key={m.id} className={`max-w-[80%] rounded border p-2 text-sm ${m.direction === 'in' ? 'border-slate-200 bg-white' : 'ml-auto border-blue-200 bg-blue-50'}`}>
                                        {m.template_name && <div className="text-xs text-slate-500">{m.template_name}</div>}
                                        <div className="whitespace-pre-wrap">{m.body}</div>
                                        <div className="text-xs text-slate-500 mt-1">{new Date(m.created_at).toLocaleString()} · {t(`messaging.msg.${m.status}`)}{m.error ? ` · ${m.error}` : ''}</div>
                                    </div>
                                ))}
                            </div>
                            <div className="border-t border-slate-200 pt-3 space-y-2">
                                <p className="text-sm text-slate-600">
                                    {d.opted_out ? t('messaging.opted_out') : d.window_open ? t('messaging.window_open', { time: new Date(d.window_closes_at!).toLocaleString() }) : t('messaging.window_closed')}
                                </p>
                                {error && <p className="text-sm text-red-700">{error}</p>}
                                {d.window_open && !d.opted_out && (
                                    <>
                                        <div className="flex flex-wrap gap-1">
                                            {quick.data?.map((r) => <button key={r.id} type="button" className="btn-secondary" onClick={() => setText(r.body)}>{r.title}</button>)}
                                        </div>
                                        <form onSubmit={send} className="flex gap-2">
                                            <textarea className="input flex-1" rows={2} value={text} aria-label={t('messaging.reply_placeholder')} placeholder={t('messaging.reply_placeholder')} onChange={(e) => setText(e.target.value)} />
                                            <button className="btn-primary" disabled={busy || !text.trim()}>{t('messaging.send')}</button>
                                        </form>
                                    </>
                                )}
                                {!d.opted_out && (
                                    <div className="flex gap-2 items-center">
                                        <label className="text-sm" htmlFor="tpl">{t('messaging.send_template')}</label>
                                        <select id="tpl" className="select" value="" onChange={(e) => e.target.value && run(() => post('template', { journey: e.target.value }))}>
                                            <option value="">—</option>
                                            {JOURNEYS.map((j) => <option key={j} value={j}>{t(`messaging.journey.${j}`)}</option>)}
                                        </select>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>

            <div className="card p-4 mt-4">
                <h2 className="font-medium mb-2">{t('messaging.quick_replies')}</h2>
                <form className="grid max-w-3xl gap-2 sm:grid-cols-[200px_1fr_auto]" onSubmit={(e) => { e.preventDefault(); run(async () => { await api('messaging/quick-replies', { method: 'POST', body: JSON.stringify(qr) }); setQr({ title: '', body: '' }); quick.reload(); }); }}>
                    <input className="input" aria-label={t('messaging.title')} placeholder={t('messaging.title')} value={qr.title} onChange={(e) => setQr({ ...qr, title: e.target.value })} />
                    <input className="input" aria-label={t('messaging.body')} placeholder={t('messaging.body')} value={qr.body} onChange={(e) => setQr({ ...qr, body: e.target.value })} />
                    <button className="btn-secondary" disabled={!qr.title || !qr.body}>{t('messaging.quick_reply_new')}</button>
                </form>
            </div>
        </AppLayout>
    );
}
