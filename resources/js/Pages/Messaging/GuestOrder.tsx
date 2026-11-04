import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { STRINGS } from './strings';
import Alert from '@/Components/ui/Alert';

interface Item { id: number; name: string; category: string | null; price: number }
interface Props {
    lang: 'fr' | 'en';
    hotel: { name: string; phone: string | null } | null;
    stay: { number: string; room: string | null; in_house: boolean; currency: string };
    outlet: string | null;
    menu: Item[];
    orders: { id: number; total: number; status: string; created_at: string }[];
    submit_url: string;
}

/** Signed mobile ordering page opened from WhatsApp (no login). */
export default function GuestOrder(p: Props) {
    const s = (k: string) => STRINGS[p.lang]?.[`messaging.order.${k}`] ?? k;
    const fmt = (n: number) => `${new Intl.NumberFormat(p.lang === 'en' ? 'en-GB' : 'fr-FR').format(n)} ${p.stay.currency}`;
    const [cart, setCart] = useState<Record<number, number>>({});
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [uuid, setUuid] = useState(() => crypto.randomUUID());
    const groups = useMemo(() => p.menu.reduce<Record<string, Item[]>>((a, i) => { (a[i.category ?? ''] ??= []).push(i); return a; }, {}), [p.menu]);
    const total = p.menu.reduce((sum, i) => sum + (cart[i.id] ?? 0) * i.price, 0);
    const add = (id: number, d: number) => setCart((c) => { const n = Math.max(0, (c[id] ?? 0) + d); const x = { ...c, [id]: n }; if (!n) delete x[id]; return x; });

    async function place() {
        setBusy(true); setMsg(null);
        try {
            const r = await fetch(p.submit_url, {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Accept-Language': p.lang },
                body: JSON.stringify({ items: Object.entries(cart).map(([id, q]) => ({ menu_item_id: Number(id), quantity: q })), notes: notes || null, client_uuid: uuid }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(j.detail ?? j.message ?? r.statusText);
            setMsg({ ok: true, text: s('placed') }); setCart({}); setNotes(''); setUuid(crypto.randomUUID());
        } catch (e) { setMsg({ ok: false, text: (e as Error).message }); }
        setBusy(false);
    }

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <Head title={`${s('title')} · ${p.hotel?.name ?? ''}`} />
            <header className="bg-white border-b border-slate-200 px-4 py-3">
                <div className="font-medium">{p.hotel?.name}</div>
                <div className="text-sm text-slate-500">{s('title')}{p.stay.room ? ` · ${p.lang === 'en' ? 'Room' : 'Chambre'} ${p.stay.room}` : ''}</div>
            </header>
            <main className="mx-auto max-w-xl p-4 space-y-4 pb-40">
                {!p.stay.in_house && <Alert variant="warning">{s('closed')}</Alert>}
                {Object.entries(groups).map(([cat, items]) => (
                    <section key={cat} className="space-y-2">
                        {cat && <h2 className="font-medium">{cat}</h2>}
                        {items.map((i) => (
                            <div key={i.id} className="flex items-center justify-between rounded border border-slate-200 bg-white p-3">
                                <div><div>{i.name}</div><div className="text-sm text-slate-500">{fmt(i.price)}</div></div>
                                <div className="flex items-center gap-2">
                                    {cart[i.id] ? <><button type="button" className="btn-secondary" onClick={() => add(i.id, -1)}>−</button><span>{cart[i.id]}</span></> : null}
                                    <button type="button" className="btn-secondary" disabled={!p.stay.in_house} onClick={() => add(i.id, 1)}>{cart[i.id] ? '+' : s('add')}</button>
                                </div>
                            </div>
                        ))}
                    </section>
                ))}
                {p.orders.length > 0 && (
                    <section>
                        <h2 className="font-medium">{s('history')}</h2>
                        <ul className="text-sm text-slate-600">{p.orders.map((o) => <li key={o.id}>{new Date(o.created_at).toLocaleString()} · {fmt(o.total)}</li>)}</ul>
                    </section>
                )}
            </main>
            {total > 0 && (
                <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white p-4">
                    <div className="mx-auto max-w-xl space-y-2">
                        <input className="input w-full" aria-label={s('notes')} placeholder={s('notes')} value={notes} onChange={(e) => setNotes(e.target.value)} />
                        <button type="button" className="btn-primary w-full" disabled={busy} onClick={place}>{s('place')} · {fmt(total)}</button>
                    </div>
                </div>
            )}
            {msg && <div className={`fixed inset-x-4 top-4 rounded border p-3 text-sm ${msg.ok ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50'}`}>{msg.text}</div>}
        </div>
    );
}
