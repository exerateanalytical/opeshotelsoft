import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyAction, EmptyFor } from '@/Components/ui/EmptyStates';
import StockTabs from './StockTabs';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num, usePerms } from './ControlShared';

interface Lot { id: number; item: string; unit: string; lot_number: string; store_id: number; expiry_date: string | null; quantity_remaining: number; days_left?: number; value?: number }
interface Expiry { expired: Lot[]; expiring: Lot[] }
const REASONS = ['expired', 'spoiled', 'breakage', 'damaged', 'theft', 'kitchen_error', 'guest_complaint', 'other'];

export default function Batches() {
    const t = useT();
    const perms = usePerms();
    const canWriteOff = perms.includes('stockcontrol.writeoff');
    const expiry = useApi<Expiry>('stock-control/lots/expiry');
    const lots = useApi<Lot[]>('stock-control/lots');
    const [reason, setReason] = useState('expired');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

    async function writeOff(l: Lot) {
        if (!window.confirm(t('stock2.batches.confirm', { lot: l.lot_number, qty: num(l.quantity_remaining) }))) return;
        try {
            await api(`stock-control/lots/${l.id}/write-off`, { method: 'POST', body: JSON.stringify({ reason }) });
            setMsg({ ok: true, text: t('stock2.batches.written_off') });
            expiry.reload(); lots.reload();
        } catch (e) { setMsg({ ok: false, text: errMsg(e) }); }
    }
    const table = (rows: Lot[], title: string, tone: string) => (
        <div className="card mb-3 overflow-x-auto p-3 text-sm">
            <h2 className={`mb-2 font-medium ${tone}`}>{title} ({rows.length})</h2>
            <table className="w-full table-auto">
                <thead><tr className="text-left text-xs uppercase text-slate-500">
                    {['item', 'lot', 'expiry', 'left', 'value'].map((h) => <th key={h} className="px-2 py-1">{t(`stock2.batches.col.${h}`)}</th>)}<th /></tr></thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map((l) => (
                        <tr key={l.id}>
                            <td className="px-2 py-1">{l.item}</td><td className="px-2 py-1">{l.lot_number}</td>
                            <td className="px-2 py-1">{l.expiry_date ? String(l.expiry_date).slice(0, 10) : '-'}{l.days_left !== undefined ? ` (${l.days_left} ${t('stock2.batches.days')})` : ''}</td>
                            <td className="px-2 py-1">{num(l.quantity_remaining)} {l.unit}</td><td className="px-2 py-1">{l.value !== undefined ? fcfa(l.value) : '-'}</td>
                            <td className="px-2 py-1">{canWriteOff && l.quantity_remaining > 0 && <button type="button" className="btn-secondary" onClick={() => writeOff(l)}>{t('stock2.batches.write_off')}</button>}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {rows.length === 0 && <p className="py-3 text-center text-slate-500">{t('stock2.common.nothing')}</p>}
        </div>
    );

    return (
        <AppLayout title={t('stock2.batches.title')} subtitle={t('stock2.batches.subtitle')}
            actions={canWriteOff ? (
                <select className="select" aria-label={t('stock4.aria.write_off_reason')} value={reason} onChange={(e) => setReason(e.target.value)}>
                    {REASONS.map((r) => <option key={r} value={r}>{t(`stock2.reason.${r}`)}</option>)}
                </select>
            ) : undefined}>
            <Head title={t('stock2.batches.title')} />
            <QueryStatus query={expiry} variant="table" />
            <QueryStatus query={lots} variant="table" />
            <StockTabs current="batches" />
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            {table(expiry.data?.expired ?? [], t('stock2.batches.expired'), 'text-red-700')}
            {table(expiry.data?.expiring ?? [], t('stock2.batches.expiring'), 'text-amber-700')}
            {lots.data?.length === 0 ? <div className="card"><EmptyFor kind="stock" action={<EmptyAction kind="stock" href="/stock" />} /></div> : table(lots.data ?? [], t('stock2.batches.all'), '')}
        </AppLayout>
    );
}
