import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { Coins, ScanLine, Search } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { errorText } from './lib';

interface Check { id: number; number: string; total: number; status: string; outlet_id: number; table_number?: string | null; guest_id?: number | null }
interface MemberHit { id: number; number: string; points_balance: number; guest: { first_name: string; last_name: string } | null; tier: { code: string; name: string } | null }
interface Attached { check_id: number; member: { id: number; number: string; points_balance: number; points_value: number; point_value: number; guest: { first_name: string; last_name: string } | null; tier: { name: string } | null } }

/** POS loyalty counter: identify the member on an open check (card scan or search) and pay with points. */
export default function LoyaltyCounter() {
    const t = useT();
    const checks = useApi<{ data: Check[] }>('pos/checks?status=open');
    const [checkId, setCheckId] = useState<number | null>(null);
    const [scan, setScan] = useState('');
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<MemberHit[]>([]);
    const [attached, setAttached] = useState<Attached | null>(null);
    const [points, setPoints] = useState('');
    const [method, setMethod] = useState('CASH');
    const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    const check = checks.data?.data.find((c) => c.id === checkId) ?? null;
    const fail = (e: unknown) => setMsg({ kind: 'error', text: errorText(e) });

    async function attach(body: { card?: string; member_id?: number }) {
        if (!checkId) { setMsg({ kind: 'error', text: t('loyalty.counter.pick_check') }); return; }
        setBusy(true); setMsg(null);
        try {
            const r = await api<Attached>(`loyalty/pos/checks/${checkId}/member`, { method: 'POST', body: JSON.stringify(body) });
            setAttached(r); setHits([]); setScan('');
        } catch (e) { fail(e); } finally { setBusy(false); }
    }

    async function search(e: React.FormEvent) {
        e.preventDefault();
        if (!q.trim()) return;
        try { setHits((await api<{ data: MemberHit[] }>(`loyalty/members?q=${encodeURIComponent(q.trim())}`)).data); } catch (err) { fail(err); }
    }

    const value = attached ? Number(points || 0) * attached.member.point_value : 0;
    const rest = check ? check.total - value : 0;

    async function settle() {
        if (!check || !attached) return;
        setBusy(true); setMsg(null);
        try {
            const payments = [] as { method: string; amount: number; reference?: string }[];
            if (value > 0) payments.push({ method: 'PTS', amount: value, reference: attached.member.number });
            if (rest > 0) payments.push({ method, amount: rest });
            await api(`pos/checks/${check.id}/settle`, { method: 'POST', body: JSON.stringify({ payments }) });
            setMsg({ kind: 'ok', text: t('loyalty.counter.settled', { number: check.number }) });
            setAttached(null); setCheckId(null); setPoints(''); checks.reload();
        } catch (e) { fail(e); } finally { setBusy(false); }
    }

    const maxPoints = attached && check ? Math.min(attached.member.points_balance, Math.floor(check.total / attached.member.point_value)) : 0;

    return (
        <AppLayout title={t('loyalty.counter.title')} subtitle={t('loyalty.counter.subtitle')}>
            <Head title={t('loyalty.counter.title')} />
            <QueryStatus query={checks} variant="list" />
            {msg && <div className={`mb-4 rounded-lg border px-4 py-2 text-sm ${msg.kind === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700'}`}>{msg.text}</div>}
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="card p-4 text-sm">
                    <h3 className="font-semibold mb-2">{t('loyalty.counter.open_checks')}</h3>
                    <ul className="divide-y">
                        {checks.data?.data.map((c) => (
                            <li key={c.id}>
                                <button className={`w-full text-left py-2 px-2 rounded ${c.id === checkId ? 'bg-slate-100 font-medium' : ''}`} onClick={() => { setCheckId(c.id); setAttached(null); }}>
                                    #{c.number}{c.table_number ? ` · ${t('loyalty.counter.table')} ${c.table_number}` : ''} <span className="float-right">{formatCurrency(c.total)}</span>
                                </button>
                            </li>
                        ))}
                        {checks.data && checks.data.data.length === 0 && <li className="py-2 text-slate-500">{t('loyalty.counter.no_checks')}</li>}
                    </ul>
                </div>

                <div className="card p-4 space-y-4 text-sm">
                    <form onSubmit={(e) => { e.preventDefault(); if (scan.trim()) attach({ card: scan.trim() }); }} className="space-y-1">
                        <label className="font-semibold flex items-center gap-1"><ScanLine className="w-4 h-4" /> {t('loyalty.counter.scan')}</label>
                        <div className="flex gap-2"><input autoFocus className="input flex-1 font-mono" value={scan} onChange={(e) => setScan(e.target.value)} placeholder={t('loyalty.counter.scan_hint')} aria-label={t('loyalty.counter.scan')} /><button className="btn-secondary" disabled={busy}>OK</button></div>
                    </form>
                    <form onSubmit={search} className="space-y-1">
                        <label className="font-semibold flex items-center gap-1"><Search className="w-4 h-4" /> {t('loyalty.counter.search')}</label>
                        <div className="flex gap-2"><input className="input flex-1" aria-label={t('loyalty.counter.search')} value={q} onChange={(e) => setQ(e.target.value)} /><button className="btn-secondary">{t('loyalty.counter.find')}</button></div>
                    </form>
                    {hits.length > 0 && (
                        <ul className="divide-y border rounded">
                            {hits.map((h) => (
                                <li key={h.id} className="p-2 flex justify-between items-center gap-2">
                                    <span>{h.guest ? `${h.guest.first_name} ${h.guest.last_name}` : h.number} <span className="text-slate-500 font-mono">{h.number}</span></span>
                                    <button className="btn-secondary" disabled={busy} onClick={() => attach({ member_id: h.id })}>{t('loyalty.counter.attach')}</button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="card p-4 space-y-3 text-sm">
                    <h3 className="font-semibold flex items-center gap-1"><Coins className="w-4 h-4" /> {t('loyalty.counter.pay_points')}</h3>
                    {!attached && <p className="text-slate-500">{t('loyalty.counter.none')}</p>}
                    {attached && check && (
                        <>
                            <div>{attached.member.guest ? `${attached.member.guest.first_name} ${attached.member.guest.last_name}` : ''} · {attached.member.tier?.name}</div>
                            <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.counter.balance')}</span><span>{attached.member.points_balance.toLocaleString()} pts ({formatCurrency(attached.member.points_value)})</span></div>
                            <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.counter.total')}</span><span>{formatCurrency(check.total)}</span></div>
                            <label className="block">{t('loyalty.counter.points_to_use', { max: maxPoints })}
                                <input type="number" min={0} max={maxPoints} className="input w-full" value={points} onChange={(e) => setPoints(e.target.value)} />
                            </label>
                            <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.counter.points_value')}</span><span>{formatCurrency(value)}</span></div>
                            {rest > 0 && (
                                <label className="block">{t('loyalty.counter.rest', { amount: formatCurrency(rest) })}
                                    <select className="input w-full" value={method} onChange={(e) => setMethod(e.target.value)}>
                                        {['CASH', 'CARD', 'MTN', 'OM'].map((m) => <option key={m} value={m}>{m}</option>)}
                                    </select>
                                </label>
                            )}
                            <button className="btn-primary w-full" disabled={busy || rest < 0 || Number(points) > maxPoints} onClick={settle}>{t('loyalty.counter.settle')}</button>
                            <p className="text-xs text-slate-500">{t('loyalty.counter.earn_note')}</p>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
