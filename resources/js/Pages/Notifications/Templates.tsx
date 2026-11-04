import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useEffect, useMemo, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Template {
    id: number;
    event: string;
    channel: 'email' | 'sms' | 'whatsapp';
    locale: 'fr' | 'en';
    category: 'transactional' | 'marketing';
    subject: string | null;
    body: string;
    active: boolean;
}

interface Payload {
    events: { event: string; category: string }[];
    templates: Template[];
}

interface Preview { subject: string | null; body: string; variables: string[] }

const EVENT_LABELS: Record<string, string> = {
    'reservation.created': 'notif.event.reservation_created',
    'reservation.cancelled': 'notif.event.reservation_cancelled',
    'reservation.pre_arrival': 'notif.event.reservation_pre_arrival',
    'invoice.issued': 'notif.event.invoice_issued',
    'guest_portal.otp': 'notif.event.guest_portal_otp',
    'staff.password_reset': 'notif.event.staff_password_reset',
    'marketing.offer': 'notif.event.marketing_offer',
};

export default function NotificationTemplates() {
    const t = useT();
    const data = useApi<Payload>('notifications/templates');
    const [event, setEvent] = useState('reservation.created');
    const [selected, setSelected] = useState<Template | null>(null);
    const [form, setForm] = useState({ subject: '', body: '', active: true });
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    const rows = useMemo(() => (data.data?.templates ?? []).filter((t) => t.event === event), [data.data, event]);

    useEffect(() => {
        if (selected) setForm({ subject: selected.subject ?? '', body: selected.body, active: selected.active });
        setPreview(null);
    }, [selected]);

    useEffect(() => {
        if (!selected) return;
        const handle = setTimeout(() => {
            api<Preview>('notifications/templates/preview', {
                method: 'POST',
                body: JSON.stringify({ subject: form.subject || null, body: form.body }),
            })
                .then(setPreview)
                .catch(() => setPreview(null));
        }, 300);
        return () => clearTimeout(handle);
    }, [form.subject, form.body, selected]);

    async function save() {
        if (!selected) return;
        setBusy(true);
        setMessage(null);
        try {
            const saved = await api<Template>(`notifications/templates/${selected.id}`, {
                method: 'PUT',
                body: JSON.stringify(selected.channel === 'email' ? form : { body: form.body, active: form.active }),
            });
            setSelected(saved);
            data.reload();
            setMessage({ ok: true, text: t('notif.template_saved') });
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : t('notif.save_failed') });
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppLayout
            title={t('notif.templates_title')}
            subtitle={t('notif.templates_subtitle')}
            actions={<Link href="/notifications/outbox" className="btn-secondary">{t('notif.outbox')}</Link>}
        >
            <Head title={t('notif.templates_title')} />
            <QueryStatus query={data} variant="cards" rows={3} />
            {data.data && data.data.events.length === 0 && <div className="card"><EmptyFor kind="messages" /></div>}

            {data.data && (
                <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
                    <div className="card p-4 space-y-3">
                        <div>
                            <label className="label" htmlFor="event">{t('notif.event')}</label>
                            <select id="event" className="input" value={event} onChange={(e) => { setEvent(e.target.value); setSelected(null); }}>
                                {data.data.events.map((e) => (
                                    <option key={e.event} value={e.event}>{EVENT_LABELS[e.event] ? t(EVENT_LABELS[e.event]) : e.event}</option>
                                ))}
                            </select>
                        </div>
                        <ul className="divide-y divide-slate-100">
                            {rows.map((tpl) => (
                                <li key={tpl.id}>
                                    <button
                                        type="button"
                                        onClick={() => setSelected(tpl)}
                                        className={`flex min-h-11 w-full items-center justify-between py-2 text-left text-sm ${selected?.id === tpl.id ? 'font-semibold text-slate-900' : 'text-slate-700'}`}
                                    >
                                        <span className="capitalize">{tpl.channel} · {tpl.locale.toUpperCase()}</span>
                                        <span className={tpl.active ? 'badge-green' : 'badge-slate'}>{tpl.active ? t('notif.active') : t('notif.off')}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        <p className="text-sm text-slate-500">
                            {data.data.events.find((e) => e.event === event)?.category === 'marketing'
                                ? t('notif.marketing_hint')
                                : t('notif.transactional_hint')}
                        </p>
                    </div>

                    <div className="card p-4 space-y-4">
                        {!selected && <p className="text-sm text-slate-500">{t('notif.choose')}</p>}
                        {selected && (
                            <>
                                {selected.channel === 'email' && (
                                    <div>
                                        <label className="label" htmlFor="subject">{t('notif.subject')}</label>
                                        <input id="subject" className="input" value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} />
                                    </div>
                                )}
                                <div>
                                    <label className="label" htmlFor="body">{t('notif.message')}</label>
                                    <textarea id="body" rows={8} className="input font-mono text-sm" value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
                                    <p className="mt-1 text-xs text-slate-500">{t('notif.placeholders_hint')}</p>
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} />
                                    {t('notif.send_this')}
                                </label>

                                <div className="rounded border border-slate-200 bg-slate-50 p-3">
                                    <p className="label">{t('notif.preview')}</p>
                                    {preview ? (
                                        <>
                                            {preview.subject && <p className="text-sm font-semibold text-slate-900">{preview.subject}</p>}
                                            <p className="whitespace-pre-wrap break-words text-sm text-slate-700">{preview.body}</p>
                                            {selected.channel !== 'email' && <p className="mt-2 text-xs text-slate-500">{t('notif.characters', { count: preview.body.length })}</p>}
                                        </>
                                    ) : (
                                        <p className="text-sm text-slate-500">…</p>
                                    )}
                                </div>

                                {message && <p className={`text-sm ${message.ok ? 'text-emerald-700' : 'text-red-700'}`}>{message.text}</p>}
                                <div className="flex justify-end">
                                    <button type="button" className="btn-primary" disabled={busy} onClick={save}>
                                        {busy ? t('notif.saving') : t('notif.save_template')}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
