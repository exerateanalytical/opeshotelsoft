import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import ManageExperience from './ManageExperience';
import { money, t } from './i18n';
import PublicLayout, { Notice, publicApi, type PublicProps } from './PublicLayout';

interface Booking {
    number: string; public_code?: string; status: string; arrival: string; departure: string; adults: number; children: number; total_amount: number; currency: string;
    deposit_required: number; deposit_paid: number; manage_token: string; room_type: string | null; can_cancel: boolean;
    hotel: { code: string; name: string; phone: string | null; check_in_time: string } | null;
    guest: { first_name: string; last_name: string };
    policy: { refundable: boolean; cancellation_hours: number | null; rate_plan: string } | null;
    loyalty: { number: string; tier: string | null; points: number; qr_text: string } | null;
    checkin: { id_type: string; eta: string | null; submitted_at: string } | null;
}

const Card = ({ id, title, children }: { id?: string; title: string; children: ReactNode }) => (
    <section id={id} className="scroll-mt-4 space-y-3 rounded-lg border border-slate-200 bg-white p-5">
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
    const [pay, setPay] = useState({ method: 'mtn_momo', phone: '' });
    const [pending, setPending] = useState('');

    const run = async (fn: () => Promise<void>) => {
        setBusy(true); setError(''); setMsg('');
        try { await fn(); } catch (e) { setError((e as Error & { status?: number }).status === 404 ? d.notFound : (e as Error).message); }
        setBusy(false);
    };
    const refOf = (x: Booking) => x.public_code ?? x.number;
    const reload = (number = conf, name = lastName) => publicApi<Booking>(slug, '/reservations/lookup', { confirmation: number.trim(), last_name: name.trim() }).then(setB);

    const lookup = (e: FormEvent) => { e.preventDefault(); run(() => reload()); };
    const cancel = () => run(async () => {
        const r = await publicApi<{ cancellation_fee: number }>(slug, '/reservations/cancel', { confirmation: refOf(b!), token: b!.manage_token });
        setConfirmCancel(false);
        await reload(refOf(b!));
        setMsg(`${d.cancelled}${r.cancellation_fee > 0 ? ` ${d.fee}: ${money(r.cancellation_fee, b!.currency, p.lang)}` : ''}`);
    });
    const deposit = (e: FormEvent) => { e.preventDefault(); run(async () => {
        // W03 Payments endpoint: creates a payment intent (sandbox/dry-run without keys).
        const r = await publicApi<{ reference: string; status: string; redirect_url: string | null }>(slug, `/reservations/${encodeURIComponent(refOf(b!))}/deposit`, {
            token: b!.manage_token, provider: pay.method, payer_phone: pay.method === 'card' ? null : pay.phone.replace(/\s/g, ''),
            return_url: window.location.href,
        });
        if (r.redirect_url) { window.location.href = r.redirect_url; return; }
        setPending(r.reference);
        await reload(refOf(b!));
        setMsg(`${d.depositPending}: ${r.reference}`);
    }); };

    // R-B: message links end in #checkin, #pay or #review; scroll there once the booking is shown.
    useEffect(() => { if (b && window.location.hash) setTimeout(() => document.getElementById(window.location.hash.slice(1))?.scrollIntoView(), 400); }, [b?.number]);

    const upcoming = b && ['tentative', 'confirmed'].includes(b.status);
    const due = b ? Math.max(0, b.deposit_required - b.deposit_paid) : 0;

    return (
        <PublicLayout p={p} title={d.manage}>
            <div className="space-y-6">
                {!b && (
                    <form onSubmit={lookup} className="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-5">
                        <div><label className="label" htmlFor="conf">{d.confirmationNo}</label><input id="conf" className="input" value={conf} onChange={(e) => setConf(e.target.value)} required /></div>
                        <div><label className="label" htmlFor="ln">{d.lastName}</label><input id="ln" className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} required /></div>
                        <button type="submit" disabled={busy} className="btn-primary min-h-11 px-5">{d.lookup}</button>
                    </form>
                )}
                {error && <Notice kind="error">{error}</Notice>}
                {msg && <Notice kind="ok">{msg}</Notice>}
                {b && (
                    <div className="grid gap-6 md:grid-cols-2">
                        <Card title={b.hotel?.name ?? ''}>
                            <dl className="grid grid-cols-2 gap-2 text-base">
                                <dt className="text-slate-500">{d.confirmationNo}</dt><dd className="font-mono">{b.public_code ?? b.number}{b.public_code ? ` (${b.hotel?.code ?? ""} ${b.number})` : ""}</dd>
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
                                <div className="space-y-2 rounded-lg bg-navy-800 p-5 text-white">
                                    <p className="font-serif text-lg text-brand-300">{p.group.name}{b.loyalty.tier ? ` · ${b.loyalty.tier}` : ''}</p>
                                    <p className="text-sm">{b.guest.first_name} {b.guest.last_name}</p>
                                    <p className="font-mono text-2xl tracking-widest">{b.loyalty.number}</p>
                                    <p className="text-sm">{b.loyalty.points.toLocaleString()} {d.points}</p>
                                    {/* QR text fallback: no QR library in the bundle yet; front desk scanners accept the code text. */}
                                    <p className="break-all rounded border border-brand-300 bg-white px-3 py-2 font-mono text-sm text-navy-800" aria-label="QR">{b.loyalty.qr_text}</p>
                                </div>
                            </Card>
                        )}

                        {upcoming && due > 0 && (
                            <Card id="pay" title={d.payDeposit}>
                                <p className="text-base">{d.depositDue}: <strong>{money(due, b.currency, p.lang)}</strong></p>
                                {pending && <p className="text-sm"><span className="badge-yellow">{d.depositPending}: {pending}</span></p>}
                                <form onSubmit={deposit} className="space-y-3">
                                    <div>
                                        <label className="label" htmlFor="method">{d.method}</label>
                                        <select id="method" className="select" value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}>
                                            <option value="mtn_momo">{d.mtn}</option><option value="orange_money">{d.orange}</option><option value="card">{d.card}</option>
                                        </select>
                                    </div>
                                    {pay.method !== 'card' && <div><label className="label" htmlFor="pphone">{d.phone}</label><input id="pphone" type="tel" className="input" value={pay.phone} onChange={(e) => setPay({ ...pay, phone: e.target.value })} required /></div>}
                                    <button type="submit" disabled={busy} className="btn-accent min-h-11 px-6">{d.payNow}</button>
                                </form>
                            </Card>
                        )}
                        <ManageExperience b={b} slug={slug} lang={p.lang} hidePay={!!upcoming && due > 0} onChanged={() => reload(refOf(b))} />

                        {b.can_cancel && (
                            <Card title={d.cancel}>
                                <p className="text-base"><strong>{d.policy}:</strong> {b.policy?.refundable ? d.freeUntil(b.policy.cancellation_hours ?? 24) : d.nonRefundablePolicy}</p>
                                {!confirmCancel ? (
                                    <button type="button" onClick={() => setConfirmCancel(true)} className="btn-secondary">{d.cancel}</button>
                                ) : (
                                    <div className="flex flex-wrap gap-3">
                                        <button type="button" disabled={busy} onClick={cancel} className="btn-danger min-h-11 px-5">{d.cancelConfirm}</button>
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
