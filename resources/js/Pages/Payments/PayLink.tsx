import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Guest pay page (no login). Strings are local FR/EN so the page works without a session locale. */
const TEXT = {
    fr: {
        title: 'Paiement', due: 'Reste à payer', paid: 'Déjà payé', amount: 'Montant à payer', phone: 'Numéro Mobile Money', email: 'E-mail (carte)',
        pay: 'Payer', waiting: 'Validez le paiement sur votre téléphone…', ok: 'Paiement reçu. Merci !', failed: 'Le paiement a échoué.',
        closed: 'Ce lien n’est plus payable.', done: 'Tout est réglé. Merci !', loading: 'Chargement…', mtn_momo: 'MTN MoMo', orange_money: 'Orange Money', card: 'Carte bancaire',
    },
    en: {
        title: 'Payment', due: 'Balance due', paid: 'Already paid', amount: 'Amount to pay', phone: 'Mobile Money number', email: 'E-mail (card)',
        pay: 'Pay', waiting: 'Approve the payment on your phone…', ok: 'Payment received. Thank you!', failed: 'The payment failed.',
        closed: 'This link can no longer be paid.', done: 'Fully paid. Thank you!', loading: 'Loading…', mtn_momo: 'MTN MoMo', orange_money: 'Orange Money', card: 'Card',
    },
};

interface LinkInfo { code: string; label: string | null; amount: number; amount_paid: number; balance: number; currency: string; allow_partial: boolean; status: string; payable: boolean; hotel?: { name: string }; providers?: string[] }
interface IntentInfo { reference: string; status: string; redirect_url: string | null; failure_reason: string | null; link?: LinkInfo }

const money = (n: number, c: string, l: string) => new Intl.NumberFormat(l === 'en' ? 'en-GB' : 'fr-FR', { style: 'currency', currency: c, maximumFractionDigits: 0 }).format(n);

export default function PayLink({ code, sig, lang }: { code: string; sig: string; lang: 'fr' | 'en' }) {
    const d = TEXT[lang] ?? TEXT.fr;
    const base = `/api/v1/pay/${code}/${sig}`;
    const [link, setLink] = useState<LinkInfo | null>(null);
    const [provider, setProvider] = useState('mtn_momo');
    const [amount, setAmount] = useState('');
    const [phone, setPhone] = useState('');
    const [email, setEmail] = useState('');
    const [intent, setIntent] = useState<IntentInfo | null>(null);
    const [error, setError] = useState('');
    const call = (path: string, init?: RequestInit) => fetch(base + path, { ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
        .then(async (r) => { const b = await r.json().catch(() => ({})); if (!r.ok) throw new Error(b.detail || b.message || r.statusText || String(r.status)); return b; });

    useEffect(() => { call('').then((l) => { setLink(l); setAmount(String(l.balance)); }).catch((e) => setError(e.message)); }, []);
    useEffect(() => {
        if (!intent || intent.status !== 'pending') return;
        const id = setTimeout(() => call(`/intents/${intent.reference}`).then((i) => { setIntent(i); if (i.link) { setLink((l) => ({ ...l!, ...i.link })); setAmount(String(i.link.balance)); } }).catch(() => {}), 3000);
        return () => clearTimeout(id);
    }, [intent]);

    const pay = async () => {
        setError('');
        try {
            const i = await call('/intents', { method: 'POST', body: JSON.stringify({ provider, amount: Number(amount), payer_phone: provider === 'card' ? null : phone, payer_email: email || null }) });
            setIntent(i);
            if (i.redirect_url) window.location.href = i.redirect_url;
        } catch (e) {
            setError((e as Error).message);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 flex items-start justify-center px-4 py-10">
            <Head title={d.title} />
            <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 space-y-4">
                {!link ? <p>{error || d.loading}</p> : (
                    <>
                        <div>
                            <div className="text-slate-500">{link.hotel?.name}</div>
                            <h1 className="text-xl font-semibold">{link.label ?? d.title}</h1>
                        </div>
                        <div className="flex justify-between"><span>{d.due}</span><b>{money(link.balance, link.currency, lang)}</b></div>
                        {link.amount_paid > 0 && <div className="flex justify-between text-slate-600"><span>{d.paid}</span><span>{money(link.amount_paid, link.currency, lang)}</span></div>}
                        {link.status === 'paid' ? <p className="text-emerald-700">{d.done}</p> : !link.payable ? <p className="text-slate-600">{d.closed}</p> : intent?.status === 'pending' ? <p>{d.waiting}</p> : (
                            <>
                                {intent?.status === 'succeeded' && <p className="text-emerald-700">{d.ok}</p>}
                                {intent?.status === 'failed' && <p className="text-red-700">{d.failed} {intent.failure_reason}</p>}
                                <div className="grid grid-cols-3 gap-2">
                                    {(link.providers ?? ['mtn_momo', 'orange_money', 'card']).map((p) => (
                                        <button key={p} onClick={() => setProvider(p)} aria-pressed={provider === p} className={`min-h-11 rounded-lg border px-2 text-sm font-semibold ${provider === p ? 'border-navy-800 bg-navy-800 text-white' : 'border-slate-500 text-navy-800'}`}>{d[p as 'card']}</button>
                                    ))}
                                </div>
                                {link.allow_partial && (
                                    <label className="block"><span className="text-slate-600">{d.amount}</span>
                                        <input className="input mt-1" inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} /></label>
                                )}
                                {provider !== 'card'
                                    ? <label className="block"><span className="text-slate-600">{d.phone}</span>
                                        <input className="input mt-1" inputMode="tel" placeholder="+2376…" value={phone} onChange={(e) => setPhone(e.target.value)} /></label>
                                    : <label className="block"><span className="text-slate-600">{d.email}</span>
                                        <input className="input mt-1" type="email" value={email} onChange={(e) => setEmail(e.target.value)} /></label>}
                                <button className="btn-primary w-full min-h-12 text-base" onClick={pay}>{d.pay} {amount ? money(Number(amount), link.currency, lang) : ''}</button>
                                {error && <p className="text-red-700">{error}</p>}
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
