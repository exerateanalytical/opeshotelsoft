import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Message {
    id: number;
    event: string;
    channel: string;
    locale: string;
    category: string;
    recipient: string;
    subject: string | null;
    body: string;
    status: 'queued' | 'sent' | 'retrying' | 'failed' | 'skipped';
    attempts: number;
    error: string | null;
    driver: string | null;
    provider_id: string | null;
    next_attempt_at: string | null;
    sent_at: string | null;
    created_at: string;
}

interface Page { data: Message[]; current_page: number; last_page: number; total: number }

const BADGE: Record<Message['status'], string> = {
    queued: 'badge-blue',
    sent: 'badge-green',
    retrying: 'badge-yellow',
    failed: 'badge-red',
    skipped: 'badge-slate',
};

export default function NotificationOutbox() {
    const t = useT();
    const [status, setStatus] = useState('');
    const [channel, setChannel] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState<number | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const params = new URLSearchParams({ page: String(page), ...(status && { status }), ...(channel && { channel }), ...(q && { q }) });
    const list = useApi<Page>(`notifications/outbox?${params}`);

    async function resend(m: Message) {
        setBusy(m.id);
        setMessage(null);
        try {
            const r = await api<Message>(`notifications/outbox/${m.id}/resend`, { method: 'POST' });
            setMessage({ ok: r.status === 'sent' || r.status === 'queued', text: t('notif.resend_result', { id: m.id, status: t(`notif.status.${r.status}`) }) });
            list.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : t('notif.resend_failed') });
        } finally {
            setBusy(null);
        }
    }

    return (
        <AppLayout
            title={t('notif.outbox.title')}
            subtitle={t('notif.outbox.subtitle')}
            actions={<Link href="/notifications/templates" className="btn-secondary">{t('notif.templates')}</Link>}
        >
            <Head title={t('notif.outbox.title')} />
            <div className="card p-4 mb-4 grid gap-3 sm:grid-cols-3">
                <div>
                    <label className="label" htmlFor="status">{t('notif.status')}</label>
                    <select id="status" className="select" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                        <option value="">{t('common.all')}</option>
                        {Object.keys(BADGE).map((s) => <option key={s} value={s}>{t(`notif.status.${s}`)}</option>)}
                    </select>
                </div>
                <div>
                    <label className="label" htmlFor="channel">{t('notif.channel')}</label>
                    <select id="channel" className="select" value={channel} onChange={(e) => { setChannel(e.target.value); setPage(1); }}>
                        <option value="">{t('common.all')}</option>
                        <option value="email">{t('notif.email')}</option>
                        <option value="sms">SMS</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                </div>
                <div>
                    <label className="label" htmlFor="q">{t('notif.recipient')}</label>
                    <input id="q" className="input" value={q} placeholder={t('notif.email_or_phone')} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                </div>
            </div>

            {message && <p className={`mb-3 text-sm ${message.ok ? 'text-emerald-700' : 'text-red-700'}`}>{message.text}</p>}
            {list.error && <p className="text-sm text-red-700">{list.error.message}</p>}

            <div className="card overflow-x-auto">
                {list.loading && !list.data ? (
                    <div className="p-4"><Loader2 className="h-5 w-5 animate-spin text-slate-500" /></div>
                ) : (
                    <table className="table-auto w-full text-sm">
                        <thead>
                            <tr>
                                <th>{t('notif.date')}</th>
                                <th>{t('notif.event')}</th>
                                <th>{t('notif.channel')}</th>
                                <th>{t('notif.recipient')}</th>
                                <th>{t('notif.status')}</th>
                                <th className="text-right">{t('notif.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data?.data.length === 0 && (
                                <tr><td colSpan={6} className="text-slate-500">{t('notif.no_messages')}</td></tr>
                            )}
                            {list.data?.data.map((m) => (
                                <Fragment key={m.id}>
                                    <tr>
                                        <td className="whitespace-nowrap">{new Date(m.created_at).toLocaleString()}</td>
                                        <td>{m.event}</td>
                                        <td className="capitalize">{m.channel} · {m.locale.toUpperCase()}</td>
                                        <td className="break-all">{m.recipient}</td>
                                        <td>
                                            <span className={BADGE[m.status]}>{t(`notif.status.${m.status}`)}</span>
                                            {m.attempts > 0 && <span className="ml-1 text-xs text-slate-500">×{m.attempts}</span>}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            <button type="button" className="btn-secondary mr-2" onClick={() => setOpen(open === m.id ? null : m.id)}>
                                                {open === m.id ? t('notif.hide') : t('notif.view')}
                                            </button>
                                            {m.status !== 'skipped' && (
                                                <button type="button" className="btn-secondary" disabled={busy === m.id} onClick={() => resend(m)}>
                                                    {busy === m.id ? t('notif.sending') : t('notif.resend')}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                    {open === m.id && (
                                        <tr>
                                            <td colSpan={6}>
                                                {m.subject && <p className="font-semibold text-slate-900">{m.subject}</p>}
                                                <p className="whitespace-pre-wrap break-words text-slate-700">{m.body}</p>
                                                <p className="mt-2 text-xs text-slate-500">
                                                    {t('notif.driver_line', { driver: m.driver ?? '-', provider: m.provider_id ?? '-' })}
                                                    {m.next_attempt_at && t('notif.next_attempt', { date: new Date(m.next_attempt_at).toLocaleString() })}
                                                </p>
                                                {m.error && <p className="mt-1 text-sm text-red-700 break-words">{m.error}</p>}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-sm">
                    <button type="button" className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                    <span>{t('notif.page', { page: list.data.current_page, last: list.data.last_page })}</span>
                    <button type="button" className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                </div>
            )}
        </AppLayout>
    );
}
