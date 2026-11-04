import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useCan } from '@/lib/useCan';
import { useT } from '@/lib/i18n';
import { errText } from './blob';

interface Prefs { email: boolean; sms: boolean; whatsapp: boolean; marketing: boolean; marketing_consent: boolean; language: string | null }
const KEYS = ['email', 'sms', 'whatsapp', 'marketing'] as const;

/** Table 2 row 28: which channels the guest may be contacted on (notifications/preferences/{guest}). */
export default function ContactPreferencesCard({ guestId }: { guestId: number | string }) {
    const t = useT();
    const can = useCan();
    const allowed = can('notifications.view', 'notifications.manage');
    const q = useApi<Prefs>(allowed ? `notifications/preferences/${guestId}` : null);
    const [f, setF] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    useEffect(() => { if (q.data) setF({ email: q.data.email, sms: q.data.sms, whatsapp: q.data.whatsapp, marketing: q.data.marketing }); }, [q.data]);
    if (!allowed) return null;
    const mayEdit = can('notifications.manage', 'guests.manage');

    return (
        <form className="card p-4 space-y-2" aria-labelledby="gape-contact-h" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setMsg(null);
            try {
                await api(`notifications/preferences/${guestId}`, { method: 'PUT', body: JSON.stringify(f) });
                setMsg({ ok: true, text: t('gape.contact.saved') });
                q.reload();
            } catch (err) { setMsg({ ok: false, text: errText(err) }); } finally { setBusy(false); }
        }}>
            <h3 id="gape-contact-h" className="font-semibold text-slate-800">{t('gape.contact.title')}</h3>
            {q.error && <p className="text-sm text-red-700">{q.error.message}</p>}
            {q.data && (
                <>
                    <fieldset disabled={!mayEdit} className="space-y-1">
                        <legend className="sr-only">{t('gape.contact.title')}</legend>
                        {KEYS.map((k) => (
                            <label key={k} className="flex min-h-11 items-center gap-2 text-sm">
                                <input type="checkbox" checked={!!f[k]} onChange={(e) => setF({ ...f, [k]: e.target.checked })} /> {t(`gape.contact.${k}`)}
                            </label>
                        ))}
                    </fieldset>
                    <p className="text-sm text-slate-500">{t(q.data.marketing_consent ? 'gape.contact.consent_yes' : 'gape.contact.consent_no')}</p>
                    {mayEdit && <button className="btn-secondary w-full justify-center" disabled={busy}>{t('gape.contact.save')}</button>}
                    {msg && <p role="status" className={`text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
                </>
            )}
        </form>
    );
}
