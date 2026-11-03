import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, Loader2, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Guest {
    id: number; title: string | null; first_name: string; last_name: string; full_name: string; email: string | null; phone: string | null;
    gender: string | null; date_of_birth: string | null; nationality: string | null; country: string | null; city: string | null; address: string | null;
    language: string; id_type: string | null; id_number: string | null; id_expiry: string | null; id_issued_in: string | null; profession: string | null;
    vip: boolean; blacklisted: boolean; blacklist_reason: string | null; preferences: Record<string, unknown> | null; notes: string | null;
    marketing_consent: boolean; company: { id: number; name: string } | null;
}
interface Stay { id: number; number: string; status: string; arrival: string; departure: string; total_amount: number; hotel_name: string; room_number: string | null; room_type: string | null }
interface FolioRow { id: number; number: string; kind: string; status: string; balance: number; hotel_name: string }
interface Membership { number: string; status: string; tier: string | null; points_balance: number; lifetime_points: number; lifetime_nights: number }
interface Dup { id: number; first_name: string; last_name: string; email: string | null; phone: string | null }
interface PrivacyReq { id: number; kind: string; status: string; reason: string; created_at: string }

type Msg = { ok: boolean; text: string } | null;
const err = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-3 py-1 border-b border-slate-100 last:border-0">
            <span className="text-xs text-slate-500">{label}</span>
            <span className="text-sm text-slate-800 text-right break-all">{value || '—'}</span>
        </div>
    );
}

export default function GuestShow({ id }: { id: string }) {
    const t = useT();
    const guest = useApi<Guest>(`guests/${id}`);
    const stays = useApi<Stay[]>(`guests/${id}/history`);
    const folios = useApi<{ folios: FolioRow[]; open_balance: number }>(`guests/${id}/folios`);
    const loyalty = useApi<{ membership: Membership | null }>(`guests/${id}/loyalty`);
    const dups = useApi<Dup[]>(`guests/${id}/duplicates`);
    const privacy = useApi<{ data: PrivacyReq[] }>(`guests/privacy-requests?guest_id=${id}`);
    const [msg, setMsg] = useState<Msg>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [confirmMerge, setConfirmMerge] = useState<number | null>(null);
    const [privacyForm, setPrivacyForm] = useState<{ kind: string; reason: string } | null>(null);

    async function post(key: string, path: string, body: object, ok: string) {
        setBusy(key);
        setMsg(null);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            setMsg({ ok: true, text: ok });
            guest.reload(); stays.reload(); folios.reload(); loyalty.reload(); dups.reload(); privacy.reload();
            return true;
        } catch (e) {
            setMsg({ ok: false, text: err(e) });
            return false;
        } finally {
            setBusy(null);
        }
    }

    const g = guest.data;
    const prefs = g?.preferences ? Object.entries(g.preferences) : [];
    const m = loyalty.data?.membership;

    return (
        <AppLayout
            title={g ? g.full_name : t('guests.col.guest')}
            subtitle={g ? [g.vip ? 'VIP' : null, g.company?.name, g.email].filter(Boolean).join(' · ') : ''}
            actions={<Link href="/guests" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('guests.back')}</Link>}
        >
            <Head title={g ? g.full_name : t('guests.col.guest')} />
            {msg && <div className={`mb-4 rounded-lg px-4 py-2 text-sm border ${msg.ok ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200'}`}>{msg.text}</div>}
            {guest.error && <div className="mb-4 rounded-lg px-4 py-2 text-sm bg-red-50 text-red-700 border border-red-200">{guest.error.message}</div>}
            {guest.loading && !g && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            {g && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div className="space-y-4">
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.show.profile')}</h3>
                            {g.blacklisted && <div className="mb-2"><span className="badge-red">{t('guests.blacklisted')}</span> <span className="text-sm text-slate-600">{g.blacklist_reason}</span></div>}
                            <Row label={t('guests.field.phone')} value={g.phone} />
                            <Row label={t('guests.field.email')} value={g.email} />
                            <Row label={t('guests.field.dob')} value={g.date_of_birth ? formatDate(g.date_of_birth) : null} />
                            <Row label={t('guests.col.nationality')} value={g.nationality} />
                            <Row label={t('guests.field.address')} value={[g.address, g.city, g.country].filter(Boolean).join(', ')} />
                            <Row label={t('guests.field.profession')} value={g.profession} />
                            <Row label={t('guests.field.language')} value={g.language?.toUpperCase()} />
                            <Row label={t('guests.company')} value={g.company ? <Link className="text-navy-600 underline-offset-2 hover:underline" href={`/guests/companies/${g.company.id}`}>{g.company.name}</Link> : null} />
                            <Row label={t('guests.field.marketing')} value={g.marketing_consent ? t('guests.yes') : t('guests.no')} />
                            {g.notes && <p className="text-sm text-slate-600 mt-2">{g.notes}</p>}
                        </div>
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.col.id_doc')}</h3>
                            <Row label={t('guests.companies.kind')} value={g.id_type?.replace('_', ' ')} />
                            <Row label={t('guests.show.number')} value={g.id_number} />
                            <Row label={t('guests.show.expiry')} value={g.id_expiry ? formatDate(g.id_expiry) : null} />
                            <Row label={t('guests.show.issued_in')} value={g.id_issued_in} />
                        </div>
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.show.preferences')}</h3>
                            {prefs.length === 0 && <p className="text-sm text-slate-500">{t('guests.show.no_prefs')}</p>}
                            {prefs.map(([k, v]) => <Row key={k} label={k} value={String(v)} />)}
                        </div>
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.show.loyalty')}</h3>
                            {loyalty.loading && !loyalty.data && <Loader2 className="w-4 h-4 animate-spin text-slate-500" />}
                            {loyalty.data && !m && <p className="text-sm text-slate-500">{t('guests.show.not_member')}</p>}
                            {m && (<>
                                <Row label={t('guests.show.card')} value={m.number} />
                                <Row label={t('guests.show.tier')} value={m.tier} />
                                <Row label={t('guests.status')} value={m.status} />
                                <Row label={t('guests.show.points')} value={m.points_balance.toLocaleString()} />
                                <Row label={t('guests.show.lifetime_nights')} value={m.lifetime_nights} />
                            </>)}
                        </div>
                    </div>

                    <div className="lg:col-span-2 space-y-4">
                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.show.stay_history')}</h3>
                            {stays.error && <p className="text-sm text-red-600">{stays.error.message}</p>}
                            <div className="overflow-x-auto">
                                <table className="table-auto">
                                    <thead><tr className="text-left text-xs text-slate-500"><th className="py-1 pr-2">{t('guests.show.hotel')}</th><th className="pr-2">{t('guests.show.reservation')}</th><th className="pr-2">{t('guests.show.dates')}</th><th className="pr-2">{t('guests.show.room')}</th><th className="pr-2">{t('guests.status')}</th><th className="text-right">{t('guests.show.amount')}</th></tr></thead>
                                    <tbody>
                                        {stays.data?.map((s) => (
                                            <tr key={s.id}>
                                                <td className="py-1 pr-2">{s.hotel_name}</td>
                                                <td className="pr-2"><Link className="text-navy-600 underline-offset-2 hover:underline" href={`/reservations/${s.id}`}>{s.number}</Link></td>
                                                <td className="pr-2 whitespace-nowrap">{formatDate(s.arrival)} → {formatDate(s.departure)}</td>
                                                <td className="pr-2">{s.room_number ?? s.room_type ?? '—'}</td>
                                                <td className="pr-2"><span className="badge-slate">{s.status.replace('_', ' ')}</span></td>
                                                <td className="text-right">{formatCurrency(s.total_amount)}</td>
                                            </tr>
                                        ))}
                                        {stays.data?.length === 0 && <tr><td colSpan={6} className="py-3 text-slate-500">{t('guests.show.no_stays')}</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="card p-4">
                            <div className="flex justify-between items-center mb-2">
                                <h3 className="font-semibold text-slate-800">{t('guests.show.folios')}</h3>
                                {folios.data && <span className="text-sm">{t('guests.show.open_balance')} <strong>{formatCurrency(folios.data.open_balance)}</strong></span>}
                            </div>
                            <div className="overflow-x-auto">
                                <table className="table-auto">
                                    <tbody>
                                        {folios.data?.folios.map((f) => (
                                            <tr key={f.id}>
                                                <td className="py-1 pr-2">{f.number}</td><td className="pr-2">{f.hotel_name}</td><td className="pr-2">{f.kind}</td>
                                                <td className="pr-2"><span className={f.status === 'open' ? 'badge-yellow' : 'badge-slate'}>{f.status}</span></td>
                                                <td className="text-right">{formatCurrency(f.balance)}</td>
                                            </tr>
                                        ))}
                                        {folios.data?.folios.length === 0 && <tr><td className="py-3 text-slate-500">{t('guests.show.no_folios')}</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="card p-4">
                            <h3 className="font-semibold text-slate-800 mb-2">{t('guests.show.possible_dups')}</h3>
                            {dups.data?.length === 0 && <p className="text-sm text-slate-500">{t('guests.show.none_found')}</p>}
                            {dups.data?.map((d) => (
                                <div key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2 border-t border-slate-100 text-sm">
                                    <span>{d.first_name} {d.last_name} <span className="text-slate-500">{d.email ?? d.phone}</span></span>
                                    {confirmMerge === d.id ? (
                                        <span className="flex gap-2">
                                            <button className="btn-primary" disabled={busy === 'merge'} onClick={() => post('merge', `guests/${id}/merge`, { duplicate_id: d.id }, t('guests.show.merged')).then(() => setConfirmMerge(null))}>{t('guests.show.confirm_merge')}</button>
                                            <button className="btn-secondary" onClick={() => setConfirmMerge(null)}>{t('common.cancel')}</button>
                                        </span>
                                    ) : <button className="btn-secondary" onClick={() => setConfirmMerge(d.id)}>{t('guests.show.merge_into')}</button>}
                                </div>
                            ))}
                        </div>

                        <div className="card p-4">
                            <div className="flex flex-wrap justify-between items-center gap-2 mb-2">
                                <h3 className="font-semibold text-slate-800 flex items-center gap-2"><ShieldAlert className="w-4 h-4" /> {t('guests.show.data_protection')}</h3>
                                <div className="flex gap-2">
                                    <a className="btn-secondary" href={`/api/v1/guests/${id}/export`}><Download className="w-4 h-4" /> {t('guests.export')}</a>
                                    {!privacyForm && <button className="btn-secondary" onClick={() => setPrivacyForm({ kind: 'anonymise', reason: '' })}>{t('guests.show.request_erasure')}</button>}
                                </div>
                            </div>
                            <p className="text-sm text-slate-500 mb-2">{t('guests.show.erasure_hint')}</p>
                            {privacyForm && (
                                <form className="space-y-2 mb-3" onSubmit={(e) => { e.preventDefault(); post('priv', `guests/${id}/privacy-requests`, privacyForm, t('guests.show.request_sent')).then((ok) => ok && setPrivacyForm(null)); }}>
                                    <select className="input w-full" value={privacyForm.kind} onChange={(e) => setPrivacyForm({ ...privacyForm, kind: e.target.value })} aria-label={t('guests.show.kind_anonymise')}>
                                        <option value="anonymise">{t('guests.show.kind_anonymise')}</option>
                                        <option value="delete">{t('guests.show.kind_delete')}</option>
                                    </select>
                                    <textarea className="input w-full" rows={2} aria-label={t('guests.show.reason_ph')} placeholder={t('guests.show.reason_ph')} required value={privacyForm.reason} onChange={(e) => setPrivacyForm({ ...privacyForm, reason: e.target.value })} />
                                    <div className="flex gap-2">
                                        <button className="btn-primary" disabled={busy === 'priv'}>{t('guests.show.submit_request')}</button>
                                        <button type="button" className="btn-secondary" onClick={() => setPrivacyForm(null)}>{t('common.cancel')}</button>
                                    </div>
                                </form>
                            )}
                            {privacy.data?.data.map((r) => (
                                <div key={r.id} className="text-sm border-t border-slate-100 py-1 flex justify-between gap-2">
                                    <span>{r.kind} · {r.reason}</span>
                                    <span className={r.status === 'pending' ? 'badge-yellow' : r.status === 'approved' ? 'badge-green' : 'badge-slate'}>{r.status}</span>
                                </div>
                            ))}
                            <Link href="/guests/privacy-requests" className="text-sm text-navy-600 underline-offset-2 hover:underline">{t('guests.show.all_requests')}</Link>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
