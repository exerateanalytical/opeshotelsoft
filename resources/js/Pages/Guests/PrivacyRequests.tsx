import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Req {
    id: number; guest_id: number; kind: string; status: string; reason: string; decision_note: string | null; created_at: string;
    guest: { first_name: string; last_name: string } | null; requester: { name: string } | null; decider: { name: string } | null;
}

export default function PrivacyRequests() {
    const t = useT();
    const [status, setStatus] = useState('pending');
    const list = useApi<{ data: Req[] }>(`guests/privacy-requests?status=${status}`);
    const [deciding, setDeciding] = useState<{ id: number; approve: boolean; note: string } | null>(null);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    async function decide() {
        if (!deciding) return;
        setBusy(true);
        setMsg(null);
        try {
            await api(`guests/privacy-requests/${deciding.id}/${deciding.approve ? 'approve' : 'reject'}`, { method: 'POST', body: JSON.stringify({ note: deciding.note || null }) });
            setMsg({ ok: true, text: deciding.approve ? t('guests.privacy.approved_msg') : t('guests.privacy.rejected_msg') });
            setDeciding(null);
            list.reload();
        } catch (e) {
            setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppLayout title={t('guests.privacy.title')} subtitle={t('guests.privacy.subtitle')}>
            <Head title={t('guests.privacy.title')} />
            {msg && <Alert variant={msg.ok ? 'success' : 'danger'} className="mb-4">{msg.text}</Alert>}
            <div className="flex gap-2 mb-4 overflow-x-auto">
                {['pending', 'approved', 'rejected'].map((s) => (
                    <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'}>{t(`guests.privacy.tab.${s}`)}</button>
                ))}
            </div>
            {list.error && <Alert variant="danger" className="mb-4">{list.error.message}</Alert>}
            {list.loading && !list.data && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            <div className="space-y-3">
                {list.data?.data.length === 0 && <p className="text-sm text-slate-500">{t(`guests.privacy.empty.${status}`)}</p>}
                {list.data?.data.map((r) => (
                    <div key={r.id} className="card p-4 text-sm">
                        <div className="flex flex-wrap justify-between gap-2">
                            <div>
                                <Link href={`/guests/${r.guest_id}`} className="font-medium text-navy-600 underline-offset-2 hover:underline">{r.guest ? `${r.guest.first_name} ${r.guest.last_name}` : t('guests.guest_no', { id: r.guest_id })}</Link>
                                <span className="badge-slate ml-2">{r.kind}</span>
                                <p className="text-slate-600 mt-1">{r.reason}</p>
                                <p className="text-xs text-slate-500">{t('guests.privacy.requested_by', { name: r.requester?.name ?? '—' })} · {formatDateTime(r.created_at)}{r.decider ? ` · ${t('guests.privacy.decided_by', { name: r.decider.name })}` : ''}</p>
                                {r.decision_note && <p className="text-slate-600">{t('guests.privacy.note', { note: r.decision_note })}</p>}
                            </div>
                            {r.status === 'pending' && deciding?.id !== r.id && (
                                <div className="flex gap-2 items-start">
                                    <button className="btn-primary" onClick={() => setDeciding({ id: r.id, approve: true, note: '' })}>{t('guests.privacy.approve')}</button>
                                    <button className="btn-secondary" onClick={() => setDeciding({ id: r.id, approve: false, note: '' })}>{t('guests.privacy.reject')}</button>
                                </div>
                            )}
                        </div>
                        {deciding?.id === r.id && (
                            <form className="mt-3 space-y-2" onSubmit={(e) => { e.preventDefault(); decide(); }}>
                                {deciding.approve && <p className="text-red-700">{t('guests.privacy.warning')}</p>}
                                <input className="input w-full" aria-label={deciding.approve ? t('guests.privacy.note_optional') : t('guests.privacy.reject_reason')} placeholder={deciding.approve ? t('guests.privacy.note_optional') : t('guests.privacy.reject_reason')} required={!deciding.approve} value={deciding.note} onChange={(e) => setDeciding({ ...deciding, note: e.target.value })} />
                                <div className="flex gap-2">
                                    <button className="btn-primary" disabled={busy}>{deciding.approve ? t('guests.privacy.confirm_anon') : t('guests.privacy.confirm_reject')}</button>
                                    <button type="button" className="btn-secondary" onClick={() => setDeciding(null)}>{t('common.cancel')}</button>
                                </div>
                            </form>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
