import { useState, type FormEvent, type ReactNode } from 'react';
import { money, t } from './i18n';
import PublicLayout, { Notice, publicApi, type PublicProps } from './PublicLayout';

interface Booking {
    number: string; status: string; arrival: string; departure: string; adults: number; children: number; total_amount: number; currency: string;
    deposit_required: number; deposit_paid: number; manage_token: string; room_type: string | null; can_cancel: boolean;
    hotel: { code: string; name: string; phone: string | null; check_in_time: string } | null;
    guest: { first_name: string; last_name: string };
    policy: { refundable: boolean; cancellation_hours: number | null; rate_plan: string } | null;
    loyalty: { number: string; tier: string | null; points: number; qr_text: string } | null;
    checkin: { id_type: string; eta: string | null; submitted_at: string } | null;
}

const Card = ({ title, children }: { title: string; children: ReactNode }) => (
    <section className="space-y-3 rounded-lg border border-[#e5dfd0] bg-white p-5">
        <h2 className="font-serif text-xl">{title}</h2>
        {children}
    </section>
);

export default function Manage(p: PublicProps) {
    const d = t(p.lang);
    const slug = p.group.slug;
    const [conf, setConf] = useState(p.query.c ?? '');
    const [lastName, setLastName] = useState('');
    const [b, setB] = useState<Booking | null>(null);
    const [error, setError] = useState('');
    const [msg, setMsg] = useState('');
    const [busy, setBusy] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [ci, setCi] = useState({ id_type: 'passport', id_number: '', nationality: '', eta: '14:00', notes: '' });
    const [pay, setPay] = useState({ method: 'mtn_momo', phone: '' });
    const [pending, setPending] = useState('');

    const run = async (fn: () => Promise<void>) => {
        setBusy(true); setError(''); setMsg('');
        try { await fn(); } catch (e) { setError((e as Error & { status?: number }).status === 404 ? d.notFound : (e as Error).message); }
        setBusy(false);
    };
    const reload = (number = conf, name = lastName) => publicApi<Booking>(slug, '/reservations/lookup', { confirmation: number.trim(), last_name: name.trim() }).then(setB);

    const lookup = (e: FormEvent) => { e.preventDefault(); run(() => reload()); };
    const cancel = () => run(async () => {
        const r = await publicApi<{ cancellation_fee: number }>(slug, '/reservations/cancel', { confirmation: b!.number, token: b!.manage_token });
        setConfirmCancel(false);
        await reload(b!.number);
        setMsg(`${d.cancelled}${r.cancellation_fee > 0 ? ` ${d.fee}: ${money(r.cancellation_fee, b!.currency, p.lang)}` : ''}`);
    });
    const checkIn = (e: FormEvent) => { e.preventDefault(); run(async () => {
        await publicApi(slug, '/reservations/check-in', { confirmation: b!.number, token: b!.manage_token, ...ci, nationality: ci.nationality || null, notes: ci.notes || null });
        await reload(b!.number);
        setMsg(d.checkinDone);
    }); };
    const deposit = (e: FormEvent) => { e.preventDefault(); run(async () => {
        // W03 Payments endpoint: creates a payment intent (sandbox/dry-run without keys).
        const r = await publicApi<{ reference: string; status: string; redirect_url: string | null }>(slug, `/reservations/${encodeURIComponent(b!.number)}/deposit`, {
            token: b!.manage_token, provider: pay.method, payer_phone: pay.method === 'card' ? null : pay.phone.replace(/\s/g, ''),
            return_url: window.location.href,
        });
        if (r.redirect_url) { window.location.href = r.redirect_url; return; }
        setPending(r.reference);
        await reload(b!.number);
        setMsg(`${d.depositPending}: ${r.reference}`);
    }); };

    const upcoming = b && ['tentative', 'confirmed'].includes(b.status);
    const due = b ? Math.max(0, b.deposit_required - b.deposit_paid) : 0;

    return (
        <PublicLayout p={p} title={d.manage}>
            <div className="space-y-6">
                {!b && (
                    <form onSubmit={lookup} className="max-w-xl space-y-4 rounded-lg border border-[#e5dfd0] bg-white p-5">
                        <div><label className="label" htmlFor="conf">{d.confirmationNo}</label><input id="conf" className="input" value={conf} onChange={(e) => setConf(e.target.value)} required /></div>
                        <div><label className="label" htmlFor="ln">{d.lastName}</label><input id="ln" className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} required /></div>
                        <button type="submit" disabled={busy} className="rounded-md bg-[#0f1f3d] px-5 py-2 text-base font-semibold text-white hover:bg-[#1b2f57] disabled:opacity-50">{d.lookup}</button>
                    </form>
                )}
                {error && <Notice kind="error">{error}</Notice>}
                {msg && <Notice kind="ok">{msg}</Notice>}
                {b && (
                    <div className="grid gap-6 md:grid-cols-2">
                        <Card title={b.hotel?.name ?? ''}>
                            <dl className="grid grid-cols-2 gap-2 text-base">
                                <dt className="text-slate-500">{d.confirmationNo}</dt><dd className="font-mono">{b.number}</dd>
                                <dt className="text-slate-500">{d.status}</dt><dd><span className={b.status === 'cancelled' ? 'badge-red' : 'badge-green'}>{d.statuses[b.status] ?? b.status}</span></dd>
                                <dt className="text-slate-500">{d.checkIn}</dt><dd>{b.arrival}</dd>
                                <dt className="text-slate-500">{d.checkOut}</dt><dd>{b.departure}</dd>
                                <dt className="text-slate-500">{d.adults} / {d.children}</dt><dd>{b.adults} / {b.children}</dd>
                                <dt className="text-slate-500">{b.room_type}</dt><dd>{b.policy?.rate_plan}</dd>
                                <dt className="text-slate-500">{d.total}</dt><dd className="font-semibold">{money(b.total_amount, b.currency, p.lang)}</dd>
                                {b.hotel && <><dt className="text-slate-500">{d.arrivalTime}</dt><dd>{b.hotel.check_in_time?.slice(0, 5)}</dd></>}
                            </dl>
                        </Card>

                        {b.loyalty && (
                            <Card title={d.loyaltyCard}>
                                <div className="space-y-2 rounded-lg bg-[#0f1f3d] p-5 text-white">
                                    <p className="font-serif text-lg text-[#d4af6a]">{p.group.name}{b.loyalty.tier ? ` · ${b.loyalty.tier}` : ''}</p>
                                    <p className="text-sm">{b.guest.first_name} {b.guest.last_name}</p>
                                    <p className="font-mono text-2xl tracking-widest">{b.loyalty.number}</p>
                                    <p className="text-sm">{b.loyalty.points.toLocaleString()} {d.points}</p>
                                    {/* QR text fallback: no QR library in the bundle yet; front desk scanners accept the code text. */}
                                    <p className="break-all rounded border border-[#d4af6a] bg-white px-3 py-2 font-mono text-sm text-[#0f1f3d]" aria-label="QR">{b.loyalty.qr_text}</p>
                                </div>
                            </Card>
                        )}

                        {upcoming && due > 0 && (
                            <Card title={d.payDeposit}>
                                <p className="text-base">{d.depositDue}: <strong>{money(due, b.currency, p.lang)}</strong></p>
                                {pending && <p className="text-sm"><span className="badge-yellow">{d.depositPending}: {pending}</span></p>}
                                <form onSubmit={deposit} className="space-y-3">
                                    <div>
                                        <label className="label" htmlFor="method">{d.method}</label>
                                        <select id="method" className="input" value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}>
                                            <option value="mtn_momo">{d.mtn}</option><option value="orange_money">{d.orange}</option><option value="card">{d.card}</option>
                                        </select>
                                    </div>
                                    {pay.method !== 'card' && <div><label className="label" htmlFor="pphone">{d.phone}</label><input id="pphone" type="tel" className="input" value={pay.phone} onChange={(e) => setPay({ ...pay, phone: e.target.value })} required /></div>}
                                    <button type="submit" disabled={busy} className="rounded-md bg-[#b8923a] px-5 py-2 text-base font-semibold text-white hover:bg-[#a07e2f] disabled:opacity-50">{d.payNow}</button>
                                </form>
                            </Card>
                        )}

                        {upcoming && (
                            <Card title={d.onlineCheckin}>
                                {b.checkin && <p className="text-sm"><span className="badge-green">{d.checkinDone}</span></p>}
                                <form onSubmit={checkIn} className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label className="label" htmlFor="id_type">{d.idType}</label>
                                        <select id="id_type" className="input" value={ci.id_type} onChange={(e) => setCi({ ...ci, id_type: e.target.value })}>
                                            {(['passport', 'national_id', 'residence_permit', 'driving_licence'] as const).map((k) => <option key={k} value={k}>{d[k]}</option>)}
                                        </select>
                                    </div>
                                    <div><label className="label" htmlFor="id_number">{d.idNumber}</label><input id="id_number" className="input" value={ci.id_number} onChange={(e) => setCi({ ...ci, id_number: e.target.value })} required /></div>
                                    <div><label className="label" htmlFor="nat">{d.nationality}</label><input id="nat" className="input" maxLength={2} value={ci.nationality} onChange={(e) => setCi({ ...ci, nationality: e.target.value.toUpperCase() })} /></div>
                                    <div><label className="label" htmlFor="eta">{d.eta}</label><input id="eta" type="time" className="input" value={ci.eta} onChange={(e) => setCi({ ...ci, eta: e.target.value })} required /></div>
                                    <div className="sm:col-span-2"><label className="label" htmlFor="notes">{d.notes}</label><textarea id="notes" rows={2} className="input" value={ci.notes} onChange={(e) => setCi({ ...ci, notes: e.target.value })} /></div>
                                    <div className="sm:col-span-2"><button type="submit" disabled={busy} className="rounded-md bg-[#0f1f3d] px-5 py-2 text-base font-semibold text-white hover:bg-[#1b2f57] disabled:opacity-50">{d.submit}</button></div>
                                </form>
                            </Card>
                        )}

                        {b.can_cancel && (
                            <Card title={d.cancel}>
                                <p className="text-base"><strong>{d.policy}:</strong> {b.policy?.refundable ? d.freeUntil(b.policy.cancellation_hours ?? 24) : d.nonRefundablePolicy}</p>
                                {!confirmCancel ? (
                                    <button type="button" onClick={() => setConfirmCancel(true)} className="btn-secondary">{d.cancel}</button>
                                ) : (
                                    <div className="flex flex-wrap gap-3">
                                        <button type="button" disabled={busy} onClick={cancel} className="rounded-md bg-red-700 px-5 py-2 text-base font-semibold text-white hover:bg-red-800 disabled:opacity-50">{d.cancelConfirm}</button>
                                        <button type="button" onClick={() => setConfirmCancel(false)} className="btn-secondary">{d.keep}</button>
                                    </div>
                                )}
                            </Card>
                        )}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
