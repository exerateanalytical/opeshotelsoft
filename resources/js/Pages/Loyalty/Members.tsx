import AppLayout from '@/Layouts/AppLayout';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { Head, Link } from '@inertiajs/react';
import { CreditCard, Loader2, Plus, ScanLine, Search, Settings, X } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface GuestLite { id: number; first_name: string; last_name: string; email?: string | null; phone?: string | null }
interface Member {
    id: number;
    number: string;
    points_balance: number;
    lifetime_points: number;
    lifetime_nights: number;
    status: string;
    enrolled_at: string;
    guest: GuestLite | null;
    tier: { id: number; code: string; name: string; benefits?: string[] } | null;
}
interface Card { id: number; card_number: string; kind: string; token: string; status: string; issued_at: string }
interface Txn { id: number; kind: string; points: number; spend: number; nights: number; description: string; expires_on: string | null; created_at: string }
interface MemberDetail extends Member { cards: Card[]; transactions: Txn[] }
interface Lookup { id: number; number: string; points_balance: number; status: string; guest: { id: number; full_name: string; vip: boolean }; tier: { code: string; name: string; benefits: string[] } }
interface Folio { id: number; number: string; name: string; balance: number; status: string; guest_id?: number | null }
interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }

const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
const Banner = ({ kind, text }: { kind: 'error' | 'ok'; text: string }) => (
    <div className={`mb-4 rounded-lg border px-4 py-2 text-sm ${kind === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700'}`}>{text}</div>
);

export default function LoyaltyMembers() {
    const t = useT();
    const [q, setQ] = useState('');
    const [search, setSearch] = useState('');
    const [tier, setTier] = useState('');
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<number | null>(null);
    const [enrolOpen, setEnrolOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    const params = new URLSearchParams({ page: String(page) });
    if (search) params.set('q', search);
    if (tier) params.set('tier', tier);
    const list = useApi<Page<Member>>(`loyalty/members?${params}`);

    // Card lookup
    const [card, setCard] = useState('');
    const [found, setFound] = useState<Lookup | null>(null);
    const [looking, setLooking] = useState(false);
    async function lookup(e: React.FormEvent) {
        e.preventDefault();
        if (!card.trim()) return;
        setLooking(true); setError(null); setFound(null);
        try {
            const r = await api<Lookup>('loyalty/cards/lookup', { method: 'POST', body: JSON.stringify({ card: card.trim() }) });
            setFound(r);
        } catch (err) { setError(msg(err)); } finally { setLooking(false); }
    }

    const notify = (m: string) => { setSuccess(m); setError(null); list.reload(); };

    return (
        <AppLayout
            title={t('loyalty.members.title')}
            subtitle={t('loyalty.members.subtitle')}
            actions={
                <div className="flex gap-2">
                    <Link href="/loyalty" className="btn-secondary inline-flex items-center gap-2"><Settings className="w-4 h-4" /> {t('loyalty.members.programme')}</Link>
                    <Link href="/loyalty/promotions" className="btn-secondary">{t('loyalty.promo.title')}</Link>
                    <Link href="/loyalty/counter" className="btn-secondary inline-flex items-center gap-2"><ScanLine className="w-4 h-4" /> {t('loyalty.counter.title')}</Link>
                    <button className="btn-primary inline-flex items-center gap-2" onClick={() => setEnrolOpen(true)}><Plus className="w-4 h-4" /> {t('loyalty.members.enrol_guest')}</button>
                </div>
            }
        >
            <Head title={t('loyalty.members.title')} />
            {error && <Banner kind="error" text={error} />}
            {success && <Banner kind="ok" text={success} />}

            {enrolOpen && <EnrolPanel onClose={() => setEnrolOpen(false)} onDone={(m) => { setEnrolOpen(false); notify(t('loyalty.members.enrolled', { number: m.number })); setSelected(m.id); }} onError={setError} />}

            <form onSubmit={lookup} className="card p-4 mb-4 flex flex-col sm:flex-row gap-2 sm:items-center">
                <ScanLine className="w-5 h-5 text-slate-500 hidden sm:block" />
                <input className="input flex-1" placeholder={t('loyalty.members.scan_ph')} aria-label={t('loyalty.members.scan_ph')} value={card} onChange={(e) => setCard(e.target.value)} maxLength={64} />
                <button className="btn-secondary inline-flex items-center gap-2" disabled={looking}>{looking && <Loader2 className="w-4 h-4 animate-spin" />} {t('loyalty.members.lookup')}</button>
            </form>
            {found && (
                <div className="card p-4 mb-4 flex flex-wrap items-center justify-between gap-3 border-emerald-200">
                    <div>
                        <div className="font-semibold">{found.guest.full_name} {found.guest.vip && <span className="ml-1 text-xs rounded bg-amber-50 text-amber-700 px-1.5">VIP</span>}</div>
                        <div className="text-sm text-slate-500">{found.number} · {found.tier.name} · {t('loyalty.members.pts', { count: found.points_balance.toLocaleString() })} · {found.status}</div>
                        {found.tier.benefits?.length > 0 && <div className="text-xs text-emerald-700 mt-1">{found.tier.benefits.map((b) => b.replace(/_/g, ' ')).join(', ')}</div>}
                    </div>
                    <div className="flex gap-2">
                        <button className="btn-primary" onClick={() => setSelected(found.id)}>{t('loyalty.members.open')}</button>
                        <button className="btn-secondary" onClick={() => setFound(null)}><X className="w-4 h-4" /></button>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 xl:grid-cols-5 gap-4">
                <div className="card p-4 xl:col-span-3">
                    <form className="flex flex-col sm:flex-row gap-2 mb-3" onSubmit={(e) => { e.preventDefault(); setPage(1); setSearch(q); }}>
                        <div className="relative flex-1">
                            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                            <input className="input w-full pl-9" placeholder={t('loyalty.members.search_ph')} aria-label={t('loyalty.members.search_ph')} value={q} onChange={(e) => setQ(e.target.value)} />
                        </div>
                        <select className="input" aria-label={t('ui2.loyalty.filter_tier')} value={tier} onChange={(e) => { setTier(e.target.value); setPage(1); }}>
                            <option value="">{t('loyalty.members.all_tiers')}</option>
                            <option value="SILVER">Silver</option>
                            <option value="GOLD">Gold</option>
                            <option value="PLAT">Platinum</option>
                        </select>
                        <button className="btn-secondary">{t('loyalty.members.search')}</button>
                    </form>
                    {list.error && <Banner kind="error" text={list.error.message} />}
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-500">
                                    <th className="py-2 pr-3">{t('loyalty.members.member')}</th><th className="py-2 pr-3">{t('guests.col.guest')}</th><th className="py-2 pr-3">{t('guests.show.tier')}</th>
                                    <th className="py-2 pr-3 text-right">{t('loyalty.members.points')}</th><th className="py-2 pr-3">{t('guests.status')}</th><th className="py-2">{t('loyalty.members.enrolled_on')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {list.loading && !list.data && <tr><td colSpan={6} className="py-8 text-center"><Loader2 className="w-5 h-5 animate-spin inline text-slate-500" /></td></tr>}
                                {list.data?.data.length === 0 && <tr><td colSpan={6}><EmptyFor kind="loyalty" /></td></tr>}
                                {list.data?.data.map((m) => (
                                    <tr key={m.id} onClick={() => setSelected(m.id)} className={`border-t cursor-pointer hover:bg-slate-50 ${selected === m.id ? 'bg-emerald-50' : ''}`}>
                                        <td className="py-2 pr-3 font-mono">{m.number}</td>
                                        <td className="py-2 pr-3">{m.guest ? `${m.guest.first_name} ${m.guest.last_name}` : '—'}</td>
                                        <td className="py-2 pr-3">{m.tier?.name ?? '—'}</td>
                                        <td className="py-2 pr-3 text-right">{m.points_balance.toLocaleString()}</td>
                                        <td className="py-2 pr-3"><span className={`rounded px-2 py-0.5 text-xs ${m.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>{m.status}</span></td>
                                        <td className="py-2">{formatDate(m.enrolled_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {list.data && list.data.last_page > 1 && (
                        <div className="flex justify-between items-center mt-3 text-sm">
                            <span className="text-slate-500">{t('loyalty.members.count', { count: list.data.total })}</span>
                            <div className="flex gap-2">
                                <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                                <span className="py-1">{page} / {list.data.last_page}</span>
                                <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                            </div>
                        </div>
                    )}
                </div>
                <div className="xl:col-span-2">
                    {selected ? <MemberPanel id={selected} onClose={() => setSelected(null)} onDone={notify} onError={(e) => { setError(e); setSuccess(null); }} />
                        : <div className="card p-6 text-center text-sm text-slate-500">{t('loyalty.members.select_hint')}</div>}
                </div>
            </div>
        </AppLayout>
    );
}

function EnrolPanel({ onClose, onDone, onError }: { onClose: () => void; onDone: (m: Member) => void; onError: (t: string) => void }) {
    const [q, setQ] = useState('');
    const t = useT();
    const [term, setTerm] = useState('');
    const [guest, setGuest] = useState<GuestLite | null>(null);
    const [kind, setKind] = useState<'digital' | 'physical'>('digital');
    const [busy, setBusy] = useState(false);
    const guests = useApi<Page<GuestLite>>(term ? `guests?q=${encodeURIComponent(term)}&per_page=10` : null);

    async function enrol() {
        if (!guest) return;
        setBusy(true);
        try {
            const m = await api<Member>('loyalty/members', { method: 'POST', body: JSON.stringify({ guest_id: guest.id, card: kind }) });
            onDone(m);
        } catch (e) { onError(msg(e)); } finally { setBusy(false); }
    }

    return (
        <div className="card p-4 mb-4">
            <div className="flex justify-between items-center mb-3">
                <h2 className="font-semibold">{t('loyalty.members.enrol_title')}</h2>
                <button onClick={onClose} className="text-slate-500 hover:text-slate-600"><X className="w-4 h-4" /></button>
            </div>
            <form className="flex gap-2 mb-2" onSubmit={(e) => { e.preventDefault(); setTerm(q); }}>
                <input className="input flex-1" placeholder={t('loyalty.members.guest_search_ph')} aria-label={t('loyalty.members.guest_search_ph')} value={q} onChange={(e) => setQ(e.target.value)} />
                <button className="btn-secondary">{t('loyalty.members.search')}</button>
            </form>
            {guests.loading && <Loader2 className="w-4 h-4 animate-spin text-slate-500" />}
            {guests.error && <p className="text-sm text-red-600">{guests.error.message}</p>}
            <div className="max-h-56 overflow-y-auto divide-y">
                {guests.data?.data.map((g) => (
                    <button key={g.id} type="button" onClick={() => setGuest(g)} className={`w-full text-left px-2 py-2 text-sm hover:bg-slate-50 ${guest?.id === g.id ? 'bg-emerald-50' : ''}`}>
                        <span className="font-medium">{g.first_name} {g.last_name}</span> <span className="text-slate-500">{g.email ?? g.phone ?? ''}</span>
                    </button>
                ))}
                {guests.data?.data.length === 0 && <p className="text-sm text-slate-500 py-2">{t('loyalty.members.no_guests')}</p>}
            </div>
            {guest && (
                <div className="flex flex-col sm:flex-row gap-2 sm:items-center mt-3">
                    <span className="text-sm flex-1">{t('loyalty.members.selected')} <b>{guest.first_name} {guest.last_name}</b></span>
                    <select className="input" aria-label={t('ui2.loyalty.card_kind')} value={kind} onChange={(e) => setKind(e.target.value as 'digital' | 'physical')}>
                        <option value="digital">{t('loyalty.members.card_digital')}</option><option value="physical">{t('loyalty.members.card_physical')}</option>
                    </select>
                    <button className="btn-primary inline-flex items-center gap-2" disabled={busy} onClick={enrol}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('loyalty.members.enrol')}</button>
                </div>
            )}
        </div>
    );
}

function MemberPanel({ id, onClose, onDone, onError }: { id: number; onClose: () => void; onDone: (t: string) => void; onError: (t: string) => void }) {
    const t = useT();
    const m = useApi<MemberDetail>(`loyalty/members/${id}`);
    const guestId = m.data?.guest?.id;
    // Server filters by guest_id; the client-side filter is a fallback in case the API ignores it.
    const folios = useApi<Page<Folio>>(guestId ? `folios?status=open&guest_id=${guestId}` : null);
    const guestFolios = (folios.data?.data ?? []).filter((f) => f.guest_id == null || f.guest_id === guestId);
    const [busy, setBusy] = useState<string | null>(null);
    const [cardKind, setCardKind] = useState<'digital' | 'physical'>('physical');
    const [redeem, setRedeem] = useState({ folio_id: '', points: '' });
    const [adjust, setAdjust] = useState({ points: '', reason: '' });

    async function run(key: string, path: string, body: object, ok: string) {
        setBusy(key);
        try {
            await api(`loyalty/members/${id}/${path}`, { method: 'POST', body: JSON.stringify(body) });
            onDone(ok);
            m.reload();
            return true;
        } catch (e) { onError(msg(e)); return false; } finally { setBusy(null); }
    }

    if (m.loading && !m.data) return <div className="card p-6 flex justify-center"><Loader2 className="w-5 h-5 animate-spin text-slate-500" /></div>;
    if (m.error) return <div className="card p-4 text-sm text-red-600">{m.error.message}</div>;
    const d = m.data;
    if (!d) return null;

    return (
        <div className="card p-4 space-y-4">
            <div className="flex justify-between items-start">
                <div>
                    <div className="font-semibold text-slate-800">{d.guest ? `${d.guest.first_name} ${d.guest.last_name}` : t('loyalty.members.member')}</div>
                    <div className="text-xs text-slate-500 font-mono">{d.number}</div>
                    <Link href={`/loyalty/members/${d.id}/statement`} className="text-xs text-blue-700 hover:underline">{t('loyalty.stmt.open')}</Link>
                </div>
                <button onClick={onClose} className="text-slate-500 hover:text-slate-600"><X className="w-4 h-4" /></button>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                <Stat label={t('loyalty.members.balance')} value={d.points_balance.toLocaleString()} />
                <Stat label={t('guests.show.tier')} value={d.tier?.name ?? '—'} />
                <Stat label={t('loyalty.members.lifetime_pts')} value={d.lifetime_points.toLocaleString()} />
                <Stat label={t('loyalty.col.nights')} value={String(d.lifetime_nights)} />
            </div>

            <section>
                <h3 className="text-sm font-semibold mb-2 flex items-center gap-1"><CreditCard className="w-4 h-4" /> {t('loyalty.members.cards')}</h3>
                <ul className="text-sm divide-y mb-2">
                    {d.cards.map((c) => (
                        <li key={c.id} className="py-1 flex justify-between gap-2">
                            <Link href={`/loyalty/cards/${c.id}`} className="font-mono text-blue-700 hover:underline">{c.card_number}</Link>
                            <span className="text-slate-500">{c.kind} · {c.status} · {formatDate(c.issued_at)}</span>
                        </li>
                    ))}
                    {d.cards.length === 0 && <li className="py-1 text-slate-500">{t('loyalty.members.no_cards')}</li>}
                </ul>
                <div className="flex gap-2">
                    <select className="input flex-1" aria-label={t('ui2.loyalty.card_kind')} value={cardKind} onChange={(e) => setCardKind(e.target.value as 'digital' | 'physical')}>
                        <option value="physical">{t('loyalty.members.physical')}</option><option value="digital">{t('loyalty.members.digital')}</option>
                    </select>
                    <button className="btn-secondary" disabled={busy === 'card'} onClick={() => run('card', 'cards', { kind: cardKind }, t('loyalty.members.card_issued'))}>{t('loyalty.members.issue_card')}</button>
                </div>
            </section>

            <form className="space-y-2" onSubmit={async (e) => {
                e.preventDefault();
                if (await run('redeem', 'redeem', { folio_id: +redeem.folio_id, points: +redeem.points }, t('loyalty.members.redeemed', { count: redeem.points }))) setRedeem({ folio_id: '', points: '' });
            }}>
                <h3 className="text-sm font-semibold">{t('loyalty.members.redeem_title')}</h3>
                <div className="flex flex-col sm:flex-row gap-2">
                    <select className="input flex-1" required aria-label={t('loyalty.members.open_folio')} value={redeem.folio_id} onChange={(e) => setRedeem({ ...redeem, folio_id: e.target.value })}>
                        <option value="">{t('loyalty.members.open_folio')}</option>
                        {guestFolios.map((f) => <option key={f.id} value={f.id}>{f.number} · {f.name} · {formatCurrency(f.balance)}</option>)}
                    </select>
                    <input type="number" min={1} max={d.points_balance} required className="input sm:w-28" placeholder={t('loyalty.members.points')} aria-label={t('loyalty.members.points')} value={redeem.points} onChange={(e) => setRedeem({ ...redeem, points: e.target.value })} />
                    <button className="btn-primary" disabled={busy === 'redeem'}>{t('loyalty.members.redeem')}</button>
                </div>
                {folios.error && <p className="text-xs text-amber-700">{t('loyalty.members.folios_unavailable', { error: folios.error.message })}</p>}
            </form>

            <form className="space-y-2" onSubmit={async (e) => {
                e.preventDefault();
                if (await run('adjust', 'adjust', { points: +adjust.points, reason: adjust.reason }, t('loyalty.members.adjusted'))) setAdjust({ points: '', reason: '' });
            }}>
                <h3 className="text-sm font-semibold">{t('loyalty.members.adjust_title')}</h3>
                <div className="flex flex-col sm:flex-row gap-2">
                    <input type="number" required className="input sm:w-28" placeholder={t('loyalty.members.adjust_ph')} aria-label={t('loyalty.members.adjust_ph')} value={adjust.points} onChange={(e) => setAdjust({ ...adjust, points: e.target.value })} />
                    <input required maxLength={255} className="input flex-1" placeholder={t('loyalty.members.reason')} aria-label={t('loyalty.members.reason')} value={adjust.reason} onChange={(e) => setAdjust({ ...adjust, reason: e.target.value })} />
                    <button className="btn-secondary" disabled={busy === 'adjust' || adjust.points === '0'}>{t('loyalty.members.apply')}</button>
                </div>
            </form>

            <section>
                <h3 className="text-sm font-semibold mb-2">{t('loyalty.members.history')}</h3>
                <div className="overflow-x-auto max-h-72 overflow-y-auto">
                    <table className="table-auto w-full text-xs">
                        <thead><tr className="text-left text-slate-500"><th className="py-1 pr-2">{t('loyalty.members.date')}</th><th className="py-1 pr-2">{t('guests.companies.kind')}</th><th className="py-1 pr-2">{t('loyalty.members.description')}</th><th className="py-1 text-right">{t('loyalty.members.points')}</th></tr></thead>
                        <tbody>
                            {d.transactions.map((x) => (
                                <tr key={x.id} className="border-t">
                                    <td className="py-1 pr-2 whitespace-nowrap">{formatDate(x.created_at)}</td>
                                    <td className="py-1 pr-2">{x.kind}</td>
                                    <td className="py-1 pr-2">{x.description}</td>
                                    <td className={`py-1 text-right ${x.points < 0 ? 'text-red-600' : 'text-emerald-700'}`}>{x.points > 0 ? '+' : ''}{x.points.toLocaleString()}</td>
                                </tr>
                            ))}
                            {d.transactions.length === 0 && <tr><td colSpan={4} className="py-2 text-slate-500">{t('loyalty.members.no_txn')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}

const Stat = ({ label, value }: { label: string; value: string }) => (
    <div className="rounded bg-slate-50 p-2"><div className="text-xs text-slate-500">{label}</div><div className="font-semibold text-sm">{value}</div></div>
);
