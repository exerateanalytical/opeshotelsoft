import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Loader2, Search } from 'lucide-react';
import Field from '@/Components/ui/Field';
import { useEffect, useState } from 'react';
import { activeHotelId, api, ApiError, Problem } from '@/lib/api';
import { cn, formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Offer {
    room_type: { id: number; code: string; name: string; max_adults: number; max_children: number };
    rate_plan: { id: number; code: string; name: string; meal_plan: string | null; refundable: boolean };
    available: number;
    currency: string;
    total: number;
    nights: { date: string; amount: number }[];
}
interface Guest { id: number; first_name: string; last_name: string; email: string | null; phone: string | null; id_number?: string | null }
interface Company { id: number; name: string }
interface Room { id: number; number: string; hk_status?: string }
interface Page<T> { data: T[] }
interface Hotel { id: number; code: string; name: string; city: string | null }
interface Agent { id: number; name: string; iata_number: string | null; commission_percent: string | number }

const CHANNELS = ['direct', 'phone', 'email', 'website', 'app', 'corporate', 'travel_agent'];
const GUARANTEES = ['', 'card', 'deposit', 'company', 'none'];

const iso = (d: Date) => d.toISOString().slice(0, 10);
const addDays = (n: number) => iso(new Date(Date.now() + n * 86_400_000));

function ProblemBox({ problem }: { problem: Problem | null }) {
    const t = useT();
    if (!problem) return null;
    const label = problem.status === 409 ? t('res.create.sold_out') : problem.status === 422 ? t('res.create.cannot_book') : t('res.create.error');
    return (
        <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            <p className="font-semibold">{label}: {problem.detail ?? problem.title}</p>
            {problem.errors && (
                <ul className="mt-1 list-disc pl-5">
                    {Object.entries(problem.errors).flatMap(([k, v]) => v.map((m) => <li key={k + m}>{m}</li>))}
                </ul>
            )}
        </div>
    );
}

const toProblem = (e: unknown, t: (k: string) => string): Problem =>
    e instanceof ApiError ? e.problem : { type: 'network', title: t('res.network_error'), status: 0, detail: String(e) };

export default function ReservationCreate() {
    const t = useT();
    // Cross-property booking: users allowed several hotels can book a sister hotel from here.
    const { hotels = [], property } = usePage<{ hotels?: Hotel[]; property?: { id: number } | null }>().props;
    const [hotelId, setHotelId] = useState<number | null>(() => Number(activeHotelId() ?? property?.id ?? hotels[0]?.id) || null);
    const opts = { hotelId };
    const [agents, setAgents] = useState<Agent[]>([]);
    const [agentId, setAgentId] = useState<number | null>(null);
    const [walkIn, setWalkIn] = useState(false);
    const [arrival, setArrival] = useState(addDays(0));
    const [departure, setDeparture] = useState(addDays(1));
    const [nights, setNights] = useState(1);
    const [adults, setAdults] = useState(1);
    const [children, setChildren] = useState(0);

    const [offers, setOffers] = useState<Offer[] | null>(null);
    const [loadingOffers, setLoadingOffers] = useState(false);
    const [offer, setOffer] = useState<Offer | null>(null);

    const [guestQ, setGuestQ] = useState('');
    const [guestHits, setGuestHits] = useState<Guest[]>([]);
    const [guest, setGuest] = useState<Guest | null>(null);
    const [newGuest, setNewGuest] = useState<{ first_name: string; last_name: string; email: string; phone: string } | null>(null);
    const [dupes, setDupes] = useState<Guest[]>([]);

    const [companyQ, setCompanyQ] = useState('');
    const [companyHits, setCompanyHits] = useState<Company[]>([]);
    const [company, setCompany] = useState<Company | null>(null);
    const [channel, setChannel] = useState('direct');
    const [guarantee, setGuarantee] = useState('');
    const [eta, setEta] = useState('');
    const [requests, setRequests] = useState('');
    const [rooms, setRooms] = useState(1);

    const [vacant, setVacant] = useState<Room[]>([]);
    const [roomId, setRoomId] = useState<number | null>(null);
    const [idDoc, setIdDoc] = useState({ id_type: 'national_id', id_number: '', nationality: '' });

    const [problem, setProblem] = useState<Problem | null>(null);
    const [saving, setSaving] = useState(false);

    // Walk-ins always start today; departure follows nights.
    const effArrival = walkIn ? addDays(0) : arrival;
    const effDeparture = walkIn ? addDays(nights) : departure;

    useEffect(() => {
        if (!effArrival || !effDeparture || effDeparture <= effArrival) return;
        let cancelled = false;
        setLoadingOffers(true);
        setOffer(null);
        api<{ data: { offers: Offer[] }[] }>(`availability?arrival=${effArrival}&departure=${effDeparture}&adults=${adults}&children=${children}`, opts)
            .then((r) => !cancelled && (setOffers(r.data.flatMap((h) => h.offers)), setProblem(null)))
            .catch((e) => !cancelled && (setOffers([]), setProblem(toProblem(e, t))))
            .finally(() => !cancelled && setLoadingOffers(false));
        return () => {
            cancelled = true;
        };
    }, [effArrival, effDeparture, adults, children, hotelId]);

    useEffect(() => {
        api<Page<Agent>>('travel-agents', opts).then((r) => setAgents(r.data)).catch(() => setAgents([]));
    }, [hotelId]);

    useEffect(() => {
        setRoomId(null);
        setVacant([]);
        if (!walkIn || !offer) return;
        api<Page<Room>>(`rooms?filter[room_type_id]=${offer.room_type.id}&filter[occupied]=0&per_page=200`, opts)
            .then((r) => setVacant(r.data))
            .catch((e) => setProblem(toProblem(e, t)));
    }, [walkIn, offer?.room_type.id]);

    useEffect(() => {
        if (guestQ.trim().length < 2) return setGuestHits([]);
        const tm = setTimeout(() => {
            api<Page<Guest>>(`guests?q=${encodeURIComponent(guestQ.trim())}&per_page=10`).then((r) => setGuestHits(r.data)).catch(() => setGuestHits([]));
        }, 250);
        return () => clearTimeout(tm);
    }, [guestQ]);

    useEffect(() => {
        if (companyQ.trim().length < 2) return setCompanyHits([]);
        const tm = setTimeout(() => {
            api<Page<Company>>(`companies?q=${encodeURIComponent(companyQ.trim())}&per_page=10`).then((r) => setCompanyHits(r.data)).catch(() => setCompanyHits([]));
        }, 250);
        return () => clearTimeout(tm);
    }, [companyQ]);

    async function createGuest() {
        if (!newGuest) return;
        setProblem(null);
        try {
            const g = await api<Guest>('guests', {
                method: 'POST',
                body: JSON.stringify({ ...newGuest, email: newGuest.email || null, phone: newGuest.phone || null }),
            });
            setGuest(g);
            setNewGuest(null);
            const d = await api<Guest[] | Page<Guest>>(`guests/${g.id}/duplicates`).catch(() => []);
            setDupes(Array.isArray(d) ? d : d.data);
        } catch (e) {
            setProblem(toProblem(e, t));
        }
    }

    async function submit() {
        if (!offer || !guest) return;
        setSaving(true);
        setProblem(null);
        try {
            let id: number;
            if (walkIn) {
                const r = await api<{ id: number }>('walk-ins', {
                    ...opts,
                    method: 'POST',
                    body: JSON.stringify({
                        guest_id: guest.id, room_type_id: offer.room_type.id, rate_plan_id: offer.rate_plan.id,
                        room_id: roomId, nights, adults, children,
                        ...(idDoc.id_number ? { guest: { id_type: idDoc.id_type, id_number: idDoc.id_number, ...(idDoc.nationality ? { nationality: idDoc.nationality.toUpperCase() } : {}) } } : {}),
                    }),
                });
                id = r.id;
            } else {
                const r = await api<{ id: number } | { booking_ref: string; data: { id: number }[] }>('reservations', {
                    ...opts,
                    method: 'POST',
                    body: JSON.stringify({
                        guest_id: guest.id, room_type_id: offer.room_type.id, rate_plan_id: offer.rate_plan.id,
                        arrival, departure, adults, children, rooms,
                        company_id: company?.id ?? null, agent_id: agentId, channel: agentId && channel === 'direct' ? 'travel_agent' : channel, guarantee: guarantee || null,
                        eta: eta || null, special_requests: requests || null,
                    }),
                });
                id = 'data' in r ? r.data[0].id : r.id;
            }
            router.visit(hotelId && hotelId !== property?.id ? '/reservations' : `/reservations/${id}`);
        } catch (e) {
            setProblem(toProblem(e, t));
            setSaving(false);
        }
    }

    const canSubmit = !!offer && !!guest && !saving && (!walkIn || !!roomId);

    return (
        <AppLayout
            title={walkIn ? t('res.create.walk_in') : t('res.create.title')}
            actions={<Link href="/reservations" className="btn-secondary"><ArrowLeft className="h-4 w-4" aria-hidden="true" /> {t('res.title')}</Link>}
        >
            <Head title={t('res.create.title')} />
            <div className="mx-auto max-w-4xl space-y-4">
                <label className="flex min-h-11 items-center gap-3 text-sm font-medium text-slate-800">
                    <input type="checkbox" className="h-5 w-5" checked={walkIn} onChange={(e) => setWalkIn(e.target.checked)} /> {t('res.create.walk_in_now')}
                </label>

                {hotels.length > 1 && (
                    <section className="card p-4">
                        <Field label={t('res.create.hotel')}>
                            <select className="select" value={hotelId ?? ''} onChange={(e) => (setHotelId(+e.target.value), setOffer(null), setAgentId(null))}>
                                {hotels.map((h) => <option key={h.id} value={h.id}>{h.name}{h.city ? ` · ${h.city}` : ''}</option>)}
                            </select>
                        </Field>
                        {hotelId !== (property?.id ?? null) && <p className="mt-2 flex items-center gap-1 text-sm text-amber-800"><AlertTriangle className="h-4 w-4" aria-hidden="true" />{t('res.create.sister_hotel')}</p>}
                    </section>
                )}

                <section className="card grid grid-cols-1 gap-3 p-4 min-[480px]:grid-cols-2 md:grid-cols-4">
                    {walkIn ? (
                        <Field label={t('res.nights')}><input className="input" type="number" inputMode="numeric" min={1} max={90} value={nights} onChange={(e) => setNights(Math.max(1, +e.target.value))} /></Field>
                    ) : (
                        <>
                            <Field label={t('res.arrival')}><input className="input" type="date" value={arrival} onChange={(e) => setArrival(e.target.value)} /></Field>
                            <Field label={t('res.departure')}><input className="input" type="date" value={departure} min={arrival} onChange={(e) => setDeparture(e.target.value)} /></Field>
                        </>
                    )}
                    <Field label={t('res.adults')}><input className="input" type="number" inputMode="numeric" min={1} max={12} value={adults} onChange={(e) => setAdults(Math.max(1, +e.target.value))} /></Field>
                    <Field label={t('res.children')}><input className="input" type="number" inputMode="numeric" min={0} max={12} value={children} onChange={(e) => setChildren(Math.max(0, +e.target.value))} /></Field>
                </section>

                <section className="card p-4">
                    <h2 className="mb-3 text-base font-semibold">{t('res.create.availability')}</h2>
                    {loadingOffers ? (
                        <Loader2 className="h-5 w-5 animate-spin" aria-label="..." />
                    ) : offers && offers.length === 0 ? (
                        <p className="text-sm text-slate-600">{t('res.create.no_availability')}</p>
                    ) : (
                        <div role="radiogroup" aria-label={t('res.create.availability')} className="grid gap-2">
                            {offers?.map((o) => {
                                const sel = offer?.room_type.id === o.room_type.id && offer?.rate_plan.id === o.rate_plan.id;
                                return (
                                    <label key={`${o.room_type.id}-${o.rate_plan.id}`}
                                        className={cn('flex min-h-14 cursor-pointer flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border-2 px-3 py-2', sel ? 'border-navy-800 bg-navy-50' : 'border-slate-300 bg-white hover:border-navy-400')}>
                                        <input type="radio" name="offer" className="h-5 w-5 shrink-0" checked={sel} onChange={() => setOffer(o)} />
                                        <span className="min-w-0 flex-1 basis-40">
                                            <span className="block font-medium text-slate-900">{o.room_type.name}</span>
                                            <span className="block text-sm text-slate-600">{o.rate_plan.name} {!o.rate_plan.refundable && <span className="font-medium text-amber-800">{t('res.create.non_refundable')}</span>}</span>
                                        </span>
                                        <span className="text-sm text-slate-600">{t('res.create.left')}: <b className="text-slate-900">{o.available}</b></span>
                                        <span className="text-sm tabular-nums text-slate-600">{formatCurrency(Math.round(o.total / Math.max(1, o.nights.length)), o.currency)} / {t('res.create.per_night')}</span>
                                        <span className="ml-auto text-base font-semibold tabular-nums text-slate-900">{formatCurrency(o.total, o.currency)}</span>
                                    </label>
                                );
                            })}
                        </div>
                    )}
                </section>

                {walkIn && offer && (
                    <section className="card space-y-3 p-4">
                        <h2 className="text-base font-semibold">{t('res.room')}</h2>
                        {vacant.length === 0 ? <p className="text-sm text-slate-600">{t('res.create.no_vacant')}</p> : (
                            <Field label={t('res.create.choose_room')}>
                                <select className="select" value={roomId ?? ''} onChange={(e) => setRoomId(e.target.value ? +e.target.value : null)} aria-label={t('res.create.choose_room')}>
                                    <option value="">{t('res.create.choose_room')}</option>
                                    {vacant.map((r) => <option key={r.id} value={r.id}>{r.number}{r.hk_status ? ` (${r.hk_status})` : ''}</option>)}
                                </select>
                            </Field>
                        )}
                        {guest && !guest.id_number && (
                            <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-3">
                                <p className="flex items-center gap-1 text-sm text-amber-800 min-[480px]:col-span-3"><AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />{t('res.create.id_required')}</p>
                                <Field label={t('res.id_type.national_id')}>
                                    <select className="select" value={idDoc.id_type} onChange={(e) => setIdDoc({ ...idDoc, id_type: e.target.value })}>
                                        {['national_id', 'passport', 'residence_permit', 'driving_licence', 'other'].map((ty) => <option key={ty} value={ty}>{t(`res.id_type.${ty}`)}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('res.create.id_number')}><input className="input" value={idDoc.id_number} onChange={(e) => setIdDoc({ ...idDoc, id_number: e.target.value })} /></Field>
                                <Field label={t('res.create.nationality')}><input className="input" maxLength={2} value={idDoc.nationality} onChange={(e) => setIdDoc({ ...idDoc, nationality: e.target.value })} /></Field>
                            </div>
                        )}
                    </section>
                )}

                <section className="card space-y-3 p-4">
                    <h2 className="text-base font-semibold">{t('res.guest')}</h2>
                    {guest ? (
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span>{guest.first_name} {guest.last_name} {guest.email && <span className="text-slate-600">· {guest.email}</span>}</span>
                            <button type="button" className="btn-secondary" onClick={() => (setGuest(null), setDupes([]))}>{t('res.create.change')}</button>
                        </div>
                    ) : newGuest ? (
                        <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2">
                            {(['first_name', 'last_name', 'email', 'phone'] as const).map((f) => (
                                <Field key={f} label={t(`res.create.field.${f}`)}>
                                    <input className="input" type={f === 'email' ? 'email' : f === 'phone' ? 'tel' : 'text'} value={newGuest[f]} onChange={(e) => setNewGuest({ ...newGuest, [f]: e.target.value })} />
                                </Field>
                            ))}
                            <div className="btn-row min-[480px]:col-span-2">
                                <button type="button" className="btn-primary" disabled={!newGuest.first_name || !newGuest.last_name} onClick={createGuest}>{t('res.create.create_guest')}</button>
                                <button type="button" className="btn-secondary" onClick={() => setNewGuest(null)}>{t('common.cancel')}</button>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-2">
                                <div className="relative min-w-0 flex-1 basis-56">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                                    <input type="search" className="input pl-9" placeholder={t('res.create.search_guest')} aria-label={t('res.create.search_guest')} value={guestQ} onChange={(e) => setGuestQ(e.target.value)} />
                                </div>
                                <button type="button" className="btn-secondary" onClick={() => setNewGuest({ first_name: '', last_name: '', email: '', phone: '' })}>{t('res.create.new_guest')}</button>
                            </div>
                            {guestHits.length > 0 && (
                                <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200">
                                    {guestHits.map((g) => (
                                        <li key={g.id}>
                                            <button type="button" className="flex min-h-11 w-full flex-wrap items-center gap-x-2 px-3 py-2 text-left text-sm hover:bg-slate-50" onClick={() => (setGuest(g), setGuestQ(''))}>
                                                <span className="font-medium">{g.first_name} {g.last_name}</span> <span className="text-slate-600">{g.email ?? g.phone ?? ''}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                    {dupes.length > 0 && (
                        <div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                            <p className="font-medium">{t('res.create.duplicates')}</p>
                            {dupes.map((d) => (
                                <button key={d.id} type="button" className="mt-1 block min-h-11 text-left font-medium underline" onClick={() => (setGuest(d), setDupes([]))}>
                                    {t('res.create.use_profile', { name: `${d.first_name} ${d.last_name}`, id: d.id })}
                                </button>
                            ))}
                        </div>
                    )}
                </section>

                {!walkIn && (
                    <section className="card grid grid-cols-1 gap-3 p-4 md:grid-cols-2">
                        <div className="min-w-0">
                            <span className="label">{t('res.company')}</span>
                            {company ? (
                                <div className="flex items-center justify-between gap-2">{company.name}<button type="button" className="btn-secondary" onClick={() => setCompany(null)}>{t('res.create.clear')}</button></div>
                            ) : (
                                <>
                                    <input className="input" aria-label={t('res.company')} placeholder={t('res.create.search_company')} value={companyQ} onChange={(e) => setCompanyQ(e.target.value)} />
                                    {companyHits.length > 0 && (
                                        <ul className="mt-1 divide-y divide-slate-200 rounded-lg border border-slate-200">
                                            {companyHits.map((c) => (
                                                <li key={c.id}><button type="button" className="min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-slate-50" onClick={() => (setCompany(c), setCompanyQ(''))}>{c.name}</button></li>
                                            ))}
                                        </ul>
                                    )}
                                </>
                            )}
                        </div>
                        <Field label={t('res.travel_agent')}>
                            <select className="select" value={agentId ?? ''} onChange={(e) => setAgentId(e.target.value ? +e.target.value : null)} aria-label={t('common.none')}>
                                <option value="">{t('common.none')}</option>
                                {agents.map((a) => <option key={a.id} value={a.id}>{a.name}{a.iata_number ? ` (IATA ${a.iata_number})` : ''} · {Number(a.commission_percent)}%</option>)}
                            </select>
                        </Field>
                        <Field label={t('res.channel')}>
                            <select className="select" value={channel} onChange={(e) => setChannel(e.target.value)}>{CHANNELS.map((c) => <option key={c} value={c}>{t(`res.channel.${c}`)}</option>)}</select>
                        </Field>
                        <Field label={t('res.guarantee')}>
                            <select className="select" value={guarantee} onChange={(e) => setGuarantee(e.target.value)}>{GUARANTEES.map((g) => <option key={g} value={g}>{g ? t(`res.guarantee.${g}`) : '-'}</option>)}</select>
                        </Field>
                        <Field label={t('res.eta')}><input className="input" type="time" value={eta} onChange={(e) => setEta(e.target.value)} /></Field>
                        <Field label={t('res.rooms')}><input className="input" type="number" inputMode="numeric" min={1} max={50} value={rooms} onChange={(e) => setRooms(Math.max(1, +e.target.value))} /></Field>
                        <Field label={t('res.special_requests')} className="md:col-span-2"><textarea className="textarea" rows={2} value={requests} onChange={(e) => setRequests(e.target.value)} /></Field>
                    </section>
                )}

                <ProblemBox problem={problem} />

                <div className="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-white px-1 py-3">
                    {offer && <span className="mr-auto text-base">{t('res.total')}: <b className="tabular-nums">{formatCurrency(offer.total * (walkIn ? 1 : rooms), offer.currency)}</b></span>}
                    <button type="button" className="btn-primary btn-lg w-full sm:w-auto" disabled={!canSubmit} onClick={submit}>
                        {saving && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />} {walkIn ? t('res.create.check_in_walk_in') : t('res.create.book')}
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
