import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import SignaturePad from './SignaturePad';
import Alert from '@/Components/ui/Alert';

interface Suggestion { id: number; room_id: number; room: string; floor: string | null; score: number; reasons: string[] }
interface Arrival {
    id: number; number: string; booking_ref: string; guest: string; vip: boolean; room_type: string | null; room: string | null; eta: string | null;
    readiness: { status: 'ready' | 'partial' | 'missing'; items: Record<string, boolean>; missing: string[] };
    suggestions: Suggestion[]; prefs: { floor?: string; view?: string; quiet?: boolean } | null;
}
interface Feed { date: string; arrivals: Arrival[]; summary: Record<string, number> }
interface Card { guest: Record<string, string | null>; checkin: { card_accepted: boolean; signed_at: string | null; signature: string | null } }

const BADGE = { ready: 'badge-green', partial: 'badge-yellow', missing: 'badge-red' } as const;
const ITEMS = ['id', 'card', 'payment', 'room'] as const;

export default function Arrivals() {
    const t = useT();
    const [date, setDate] = useState('');
    const feed = useApi<Feed>(`experience/arrivals${date ? `?date=${date}` : ''}`, [date]);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState<number | null>(null);
    const [card, setCard] = useState<Card | null>(null);
    const [form, setForm] = useState({ id_type: 'passport', id_number: '', id_expiry: '', nationality: '', signature: '', card_accepted: false });

    const run = async (fn: () => Promise<void>) => {
        setBusy(true); setMsg(null);
        try { await fn(); feed.reload(); } catch (e) { setMsg({ ok: false, text: e instanceof ApiError ? e.message : String(e) }); }
        setBusy(false);
    };
    const generate = () => run(async () => { await api('experience/suggestions/generate', { method: 'POST', body: JSON.stringify({ date: feed.data?.date }) }); setMsg({ ok: true, text: t('experience.arrivals.generated') }); });
    const decide = (s: Suggestion, what: 'accept' | 'reject') => run(async () => { await api(`experience/suggestions/${s.id}/${what}`, { method: 'POST' }); });
    const openCard = async (a: Arrival) => {
        setOpen(a.id);
        const c = await api<Card>(`experience/reservations/${a.id}/card`);
        setCard(c);
        setForm({ id_type: (c.guest.id_type as string) || 'passport', id_number: c.guest.id_number ?? '', id_expiry: c.guest.id_expiry ?? '', nationality: c.guest.nationality ?? '', signature: '', card_accepted: c.checkin.card_accepted });
    };
    const save = (a: Arrival) => run(async () => {
        await api(`experience/reservations/${a.id}/checkin-data`, { method: 'POST', body: JSON.stringify({ ...form, id_expiry: form.id_expiry || null, nationality: form.nationality || null, signature: form.signature || undefined }) });
        setMsg({ ok: true, text: t('experience.arrivals.saved') });
        await openCard(a);
    });

    return (
        <AppLayout title={t('experience.arrivals.title')}>
            <Head title={t('experience.arrivals.title')} />
            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <input type="date" className="input" aria-label={t('experience.arrivals.date')} value={date || feed.data?.date || ''} onChange={(e) => setDate(e.target.value)} />
                        <button type="button" className="btn-primary" disabled={busy} onClick={generate}>{t('experience.arrivals.suggest')}</button>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2 text-sm">
                    {(['ready', 'partial', 'missing'] as const).map((s) => <span key={s} className={BADGE[s]}>{t(`experience.readiness.${s}`)}: {feed.data?.summary[s] ?? 0}</span>)}
                </div>
                {msg && <Alert variant={msg.ok ? 'success' : 'danger'}>{msg.text}</Alert>}
                <QueryStatus query={feed} variant="table" />
                {feed.data && (
                    <div className="card overflow-x-auto">
                        <table className="table-auto">
                            <thead><tr>
                                <th>{t('experience.arrivals.guest')}</th><th>{t('experience.arrivals.room')}</th><th>{t('experience.arrivals.eta')}</th>
                                <th>{t('experience.arrivals.readiness')}</th><th>{t('experience.arrivals.suggestions')}</th><th />
                            </tr></thead>
                            <tbody>
                                {(feed.data?.arrivals ?? []).length === 0 && <tr><td colSpan={6}><EmptyState title={t('experience.arrivals.none')} description={t('states3.empty.arrivals.text')} action={<a href="/reservations/create" className="btn-secondary">{t('states.empty.reservations.action')}</a>} /></td></tr>}
                                {(feed.data?.arrivals ?? []).map((a) => (
                                    <tr key={a.id} className="align-top">
                                        <td><div className="font-medium">{a.vip && '★ '}{a.guest}</div><div className="text-xs text-slate-500">{a.number} · {a.booking_ref}</div></td>
                                        <td>{a.room ?? <span className="text-amber-700">{t('experience.arrivals.unassigned')}</span>}<div className="text-xs text-slate-500">{a.room_type}</div></td>
                                        <td>{a.eta ?? '-'}</td>
                                        <td>
                                            <span className={BADGE[a.readiness.status]}>{t(`experience.readiness.${a.readiness.status}`)}</span>
                                            <div className="mt-1 flex flex-wrap gap-1 text-xs">{ITEMS.map((k) => <span key={k} className={a.readiness.items[k] ? 'badge-green' : 'badge-slate'}>{t(`experience.item.${k}`)}</span>)}</div>
                                        </td>
                                        <td>
                                            {a.suggestions.map((s) => (
                                                <div key={s.id} className="mb-1 flex flex-wrap items-center gap-1">
                                                    <strong>{s.room}</strong><span className="text-xs text-slate-500">{s.score} · {s.reasons.map((r) => t(`experience.reason.${r}`)).join(', ')}</span>
                                                    <button type="button" className="btn-primary" disabled={busy} onClick={() => decide(s, 'accept')}>{t('experience.arrivals.accept')}</button>
                                                    <button type="button" className="btn-secondary" disabled={busy} onClick={() => decide(s, 'reject')}>{t('experience.arrivals.reject')}</button>
                                                </div>
                                            ))}
                                        </td>
                                        <td><button type="button" className="btn-secondary" onClick={() => openCard(a)}>{t('experience.arrivals.card')}</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {open && card && (
                    <div className="card space-y-3 p-4">
                        <h2 className="font-semibold">{t('experience.card.title')}: {card.guest.first_name} {card.guest.last_name}</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div><label className="label" htmlFor="idt">{t('experience.card.id_type')}</label>
                                <select id="idt" className="select" value={form.id_type} onChange={(e) => setForm({ ...form, id_type: e.target.value })}>
                                    {['passport', 'national_id', 'residence_permit', 'driving_licence'].map((k) => <option key={k} value={k}>{t(`experience.card.${k}`)}</option>)}
                                </select></div>
                            <div><label className="label" htmlFor="idn">{t('experience.card.id_number')}</label><input id="idn" className="input" value={form.id_number} onChange={(e) => setForm({ ...form, id_number: e.target.value })} /></div>
                            <div><label className="label" htmlFor="ide">{t('experience.card.id_expiry')}</label><input id="ide" type="date" className="input" value={form.id_expiry} onChange={(e) => setForm({ ...form, id_expiry: e.target.value })} /></div>
                            <div><label className="label" htmlFor="nat">{t('experience.card.nationality')}</label><input id="nat" maxLength={2} className="input" value={form.nationality} onChange={(e) => setForm({ ...form, nationality: e.target.value.toUpperCase() })} /></div>
                        </div>
                        {card.checkin.signature && <img src={card.checkin.signature} alt={t('experience.card.signature')} className="h-16 rounded border border-slate-200 bg-white" />}
                        <SignaturePad label={t('experience.card.signature')} clearLabel={t('experience.card.clear')} onChange={(v) => setForm((f) => ({ ...f, signature: v }))} />
                        <label className="min-h-11 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.card_accepted} onChange={(e) => setForm({ ...form, card_accepted: e.target.checked })} />{t('experience.card.accepted')}</label>
                        <div className="flex gap-2">
                            <button type="button" className="btn-primary" disabled={busy} onClick={() => save({ id: open } as Arrival)}>{t('experience.card.save')}</button>
                            <button type="button" className="btn-secondary" onClick={() => { setOpen(null); setCard(null); }}>{t('common.cancel')}</button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
