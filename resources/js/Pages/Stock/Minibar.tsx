import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num } from './ControlShared';

interface Task { id: number; room_number: string; status: string; date: string; lines: { stock_item_id: number; item_name: string; quantity: number }[] }
interface Par { id: number; room_type_id: number; item_name: string; par_qty: number; price: number }

export default function Minibar() {
    const t = useT();
    const tasks = useApi<Task[]>('stock-ops/minibar/tasks?status=pending');
    const pars = useApi<Par[]>('stock-ops/minibar/pars');
    const [msg, setMsg] = useState<string | null>(null);

    async function done(id: number) {
        setMsg(null);
        try { await api(`stock-ops/minibar/tasks/${id}/complete`, { method: 'POST', body: '{}' }); tasks.reload(); } catch (e) { setMsg(errMsg(e)); }
    }

    return (
        <AppLayout title={t('stock3.mini.title')} subtitle={t('stock3.mini.subtitle')}>
            <Head title={t('stock3.mini.title')} />
            {msg && <p className="mb-3 text-sm text-red-700">{msg}</p>}
            <h2 className="mb-2 font-medium">{t('stock3.mini.tasks')}</h2>
            {tasks.data && tasks.data.length === 0 && <p className="mb-4 text-sm text-slate-500">{t('stock3.mini.no_tasks')}</p>}
            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
                {(tasks.data ?? []).map((k) => (
                    <div key={k.id} className="card p-3 text-sm">
                        <p className="font-medium">{t('stock3.mini.room', { room: k.room_number })} <span className="text-xs text-slate-500">{k.date}</span></p>
                        <ul className="my-2">{k.lines.length === 0 ? <li className="text-slate-500">{t('stock3.mini.check_only')}</li> : k.lines.map((l) => <li key={l.stock_item_id}>{num(l.quantity)} × {l.item_name}</li>)}</ul>
                        <button type="button" className="btn-primary w-full" onClick={() => done(k.id)}>{t('stock3.mini.done')}</button>
                    </div>
                ))}
            </div>
            <h2 className="mb-2 font-medium">{t('stock3.mini.pars')}</h2>
            <div className="card overflow-x-auto p-3 text-sm">
                <table className="w-full table-auto">
                    <thead><tr className="text-left text-xs uppercase text-slate-500">{['type', 'item', 'par', 'price'].map((h) => <th key={h} className="px-2 py-1">{t(`stock3.mini.col.${h}`)}</th>)}</tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {(pars.data ?? []).map((p) => <tr key={p.id}><td className="px-2 py-1">{p.room_type_id}</td><td className="px-2 py-1">{p.item_name}</td><td className="px-2 py-1">{num(p.par_qty)}</td><td className="px-2 py-1">{fcfa(p.price)}</td></tr>)}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
