import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Loader2, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { activeHotelId, api, ApiError, Problem } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
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
        <AppLayout>
            <Head title={t('res.create.title')} />
            <div className="mx-auto max-w-4xl space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <Link href="/reservations" className="btn-secondary inline-flex items-center gap-1"><ArrowLeft className="h-4 w-4" /> {t('res.title')}</Link>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={walkIn} onChange={(e) => setWalkIn(e.target.checked)} /> {t('res.create.walk_in_now')}
                    </label>
                </div>
                <h1 className="text-xl font-semibold">{walkIn ? t('res.create.walk_in') : t('res.create.title')}</h1>

                {hotels.length > 1 && (
                    <section className="card p-4">
                        <label className="label" htmlFor="hotel">{t('res.create.hotel')}</label>
                        <select id="hotel" className="input w-full" value={hotelId ?? ''} onChange={(e) => (setHotelId(+e.target.value), setOffer(null), setAgentId(null))}>
                            {hotels.map((h) => <option key={h.id} value={h.id}>{h.name}{h.city ? ` · ${h.city}` : ''}</option>)}
                        </select>
                        {hotelId !== (property?.id ?? null) && <p className="mt-1 text-sm text-amber-700">{t('res.create.sister_hotel')}</p>}
                    </section>
                )}

                <section className="card grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                    {walkIn ? (
                        <label className="text-sm">{t('res.nights')}<input className="input" type="number" min={1} max={90} value={nights} onChange={(e) => setNights(Math.max(1, +e.target.value))} /></label>
                    ) : (
                        <>
                            <label className="text-sm">{t('res.arrival')}<input className="input" type="date" value={arrival} onChange={(e) => setArrival(e.target.value)} /></label>
                            <label className="text-sm">{t('res.departure')}<input className="input" type="date" value={departure} min={arrival} onChange={(e) => setDeparture(e.target.value)} /></label>
                        </>
                    )}
                    <label className="text-sm">{t('res.adults')}<input className="input" type="number" min={1} max={12} value={adults} onChange={(e) => setAdults(Math.max(1, +e.target.value))} /></label>
                    <label className="text-sm">{t('res.children')}<input className="input" type="number" min={0} max={12} value={children} onChange={(e) => setChildren(Math.max(0, +e.target.value))} /></label>
                </section>

                <section className="card p-4">
                    <h2 className="mb-2 font-medium">{t('res.create.availability')}</h2>
                    {loadingOffers ? (
                        <Loader2 className="h-5 w-5 animate-spin" />
                    ) : offers && offers.length === 0 ? (
                        <p className="text-sm text-gray-500">{t('res.create.no_availability')}</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead><tr className="text-left text-gray-500"><th /><th>{t('res.room_type')}</th><th>{t('res.rate_plan')}</th><th>{t('res.create.left')}</th><th className="text-right">{t('res.create.per_night')}</th><th className="text-right">{t('res.total')}</th></tr></thead>
                            <tbody>
                                {offers?.map((o) => {
                                    const sel = offer?.room_type.id === o.room_type.id && offer?.rate_plan.id === o.rate_plan.id;
                                    return (
                                        <tr key={`${o.room_type.id}-${o.rate_plan.id}`} className={sel ? 'bg-blue-50' : 'cursor-pointer hover:bg-gray-50'} onClick={() => setOffer(o)}>
                                            <td><input type="radio" checked={sel} onChange={() => setOffer(o)} /></td>
                                            <td>{o.room_type.name}</td>
                                            <td>{o.rate_plan.name} {!o.rate_plan.refundable && <span className="text-xs text-amber-700">{t('res.create.non_refundable')}</span>}</td>
                                            <td>{o.available}</td>
                                            <td className="text-right">{formatCurrency(Math.round(o.total / Math.max(1, o.nights.length)), o.currency)}</td>
                                            <td className="text-right font-medium">{formatCurrency(o.total, o.currency)}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </section>

                {walkIn && offer && (
                    <section className="card p-4">
                        <h2 className="mb-2 font-medium">{t('res.room')}</h2>
                        {vacant.length === 0 ? <p className="text-sm text-gray-500">{t('res.create.no_vacant')}</p> : (
                            <select className="input" value={roomId ?? ''} onChange={(e) => setRoomId(e.target.value ? +e.target.value : null)}>
                                <option value="">{t('res.create.choose_room')}</option>
                                {vacant.map((r) => <option key={r.id} value={r.id}>{r.number}{r.hk_status ? ` (${r.hk_status})` : ''}</option>)}
                            </select>
                        )}
                        {guest && !guest.id_number && (
                            <div className="mt-3 grid grid-cols-3 gap-2 text-sm">
                                <p className="col-span-3 text-amber-800">{t('res.create.id_required')}</p>
                                <select className="input" value={idDoc.id_type} onChange={(e) => setIdDoc({ ...idDoc, id_type: e.target.value })}>
                                    {['national_id', 'passport', 'residence_permit', 'driving_licence', 'other'].map((ty) => <option key={ty} value={ty}>{t(`res.id_type.${ty}`)}</option>)}
                                </select>
                                <input className="input" placeholder={t('res.create.id_number')} value={idDoc.id_number} onChange={(e) => setIdDoc({ ...idDoc, id_number: e.target.value })} />
                                <input className="input" placeholder={t('res.create.nationality')} maxLength={2} value={idDoc.nationality} onChange={(e) => setIdDoc({ ...idDoc, nationality: e.target.value })} />
                            </div>
                        )}
                    </section>
                )}

                <section className="card space-y-2 p-4">
                    <h2 className="font-medium">{t('res.guest')}</h2>
                    {guest ? (
                        <div className="flex items-center justify-between text-sm">
                            <span>{guest.first_name} {guest.last_name} {guest.email && <span className="text-gray-500">· {guest.email}</span>}</span>
                            <button type="button" className="btn-secondary" onClick={() => (setGuest(null), setDupes([]))}>{t('res.create.change')}</button>
                        </div>
                    ) : newGuest ? (
                        <div className="grid grid-cols-2 gap-2">
                            {(['first_name', 'last_name', 'email', 'phone'] as const).map((f) => (
                                <input key={f} className="input" placeholder={t(`res.create.field.${f}`)} value={newGuest[f]} onChange={(e) => setNewGuest({ ...newGuest, [f]: e.target.value })} />
                            ))}
                            <div className="col-span-2 flex gap-2">
                                <button type="button" className="btn-primary" disabled={!newGuest.first_name || !newGuest.last_name} onClick={createGuest}>{t('res.create.create_guest')}</button>
                                <button type="button" className="btn-secondary" onClick={() => setNewGuest(null)}>{t('common.cancel')}</button>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="flex gap-2">
                                <div className="relative flex-1">
                                    <Search className="absolute left-2 top-2.5 h-4 w-4 text-gray-400" />
                                    <input className="input w-full pl-8" placeholder={t('res.create.search_guest')} value={guestQ} onChange={(e) => setGuestQ(e.target.value)} />
                                </div>
                                <button type="button" className="btn-secondary" onClick={() => setNewGuest({ first_name: '', last_name: '', email: '', phone: '' })}>{t('res.create.new_guest')}</button>
                            </div>
                            {guestHits.map((g) => (
                                <button key={g.id} type="button" className="block w-full rounded px-2 py-1 text-left text-sm hover:bg-gray-50" onClick={() => (setGuest(g), setGuestQ(''))}>
                                    {g.first_name} {g.last_name} <span className="text-gray-500">{g.email ?? g.phone ?? ''}</span>
                                </button>
                            ))}
                        </>
                    )}
                    {dupes.length > 0 && (
                        <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">
                            {t('res.create.duplicates')}
                            {dupes.map((d) => (
                                <button key={d.id} type="button" className="ml-2 underline" onClick={() => (setGuest(d), setDupes([]))}>
                                    {t('res.create.use_profile', { name: `${d.first_name} ${d.last_name}`, id: d.id })}
                                </button>
                            ))}
                        </div>
                    )}
                </section>

                {!walkIn && (
                    <section className="card grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                        <div className="text-sm">
                            {t('res.company')}
                            {company ? (
                                <div className="flex items-center justify-between">{company.name}<button type="button" className="btn-secondary" onClick={() => setCompany(null)}>{t('res.create.clear')}</button></div>
                            ) : (
                                <>
                                    <input className="input w-full" placeholder={t('res.create.search_company')} value={companyQ} onChange={(e) => setCompanyQ(e.target.value)} />
                                    {companyHits.map((c) => (
                                        <button key={c.id} type="button" className="block w-full px-2 py-1 text-left hover:bg-gray-50" onClick={() => (setCompany(c), setCompanyQ(''))}>{c.name}</button>
                                    ))}
                                </>
                            )}
                        </div>
                        <label className="text-sm">{t('res.travel_agent')}
                            <select className="input w-full" value={agentId ?? ''} onChange={(e) => setAgentId(e.target.value ? +e.target.value : null)}>
                                <option value="">{t('common.none')}</option>
                                {agents.map((a) => <option key={a.id} value={a.id}>{a.name}{a.iata_number ? ` (IATA ${a.iata_number})` : ''} · {Number(a.commission_percent)}%</option>)}
                            </select>
                        </label>
                        <label className="text-sm">{t('res.channel')}
                            <select className="input w-full" value={channel} onChange={(e) => setChannel(e.target.value)}>{CHANNELS.map((c) => <option key={c} value={c}>{t(`res.channel.${c}`)}</option>)}</select>
                        </label>
                        <label className="text-sm">{t('res.guarantee')}
                            <select className="input w-full" value={guarantee} onChange={(e) => setGuarantee(e.target.value)}>{GUARANTEES.map((g) => <option key={g} value={g}>{g ? t(`res.guarantee.${g}`) : '—'}</option>)}</select>
                        </label>
                        <label className="text-sm">{t('res.eta')}<input className="input w-full" type="time" value={eta} onChange={(e) => setEta(e.target.value)} /></label>
                        <label className="text-sm">{t('res.rooms')}<input className="input w-full" type="number" min={1} max={50} value={rooms} onChange={(e) => setRooms(Math.max(1, +e.target.value))} /></label>
                        <label className="text-sm sm:col-span-2">{t('res.special_requests')}<textarea className="input w-full" rows={2} value={requests} onChange={(e) => setRequests(e.target.value)} /></label>
                    </section>
                )}

                <ProblemBox problem={problem} />

                <div className="flex items-center justify-end gap-3">
                    {offer && <span className="text-sm">{t('res.total')}: <b>{formatCurrency(offer.total * (walkIn ? 1 : rooms), offer.currency)}</b></span>}
                    <button type="button" className="btn-primary inline-flex items-center gap-1" disabled={!canSubmit} onClick={submit}>
                        {saving && <Loader2 className="h-4 w-4 animate-spin" />} {walkIn ? t('res.create.check_in_walk_in') : t('res.create.book')}
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}
