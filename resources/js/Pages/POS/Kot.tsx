import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useApi } from '@/lib/api';
import { formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';

interface Ticket { fired_at: string; station: string; items: { name: string; quantity: number; modifiers: { name: string }[] | null; notes: string | null; voided: boolean }[] }
interface Kot { check: { number: string; table: string | null; covers: number; outlet: string }; server: string | null; tickets: Ticket[] }

/** Printable kitchen order tickets (80 mm roll): one ticket per fire and station. */
export default function KotPage({ id, firedAt }: { id: string; firedAt?: string | null }) {
    const t = useT();
    const kot = useApi<Kot>(`pos/checks/${id}/tickets${firedAt ? `?fired_at=${encodeURIComponent(firedAt)}` : ''}`);
    const d = kot.data;
    return (
        <div className="min-h-screen bg-white text-black p-4">
            <Head title={t('pos.kot.title')} />
            <style>{'@media print { .no-print { display: none } @page { size: 80mm auto; margin: 2mm } body { background: #fff } } .kot { width: 76mm; max-width: 100%; }'}</style>
            <div className="no-print flex gap-2 mb-4">
                <OpsPrintButton className="btn-primary inline-flex items-center gap-1" label={t('pos.kot.print_pdf')} path={`kot/${id}${firedAt ? `?fired_at=${encodeURIComponent(firedAt)}` : ''}`} />
                <button className="btn-secondary" onClick={() => window.close()}>{t('pos.kot.close')}</button>
            </div>
            {kot.loading && !d && <Loader2 className="w-5 h-5 animate-spin" />}
            {kot.error && <p className="text-sm text-red-600">{kot.error.message}</p>}
            {d && d.tickets.length === 0 && <p className="text-sm">{t('pos.kot.empty')}</p>}
            {d?.tickets.map((tk, i) => (
                <div key={i} className="kot font-mono text-sm border-b border-dashed border-black pb-3 mb-3" style={{ breakAfter: 'page' }}>
                    <div className="text-center font-bold text-base uppercase">{tk.station}</div>
                    <div className="text-center">{d.check.outlet}</div>
                    <div className="flex justify-between font-bold text-base mt-1"><span>{t('pos.kot.table', { name: d.check.table ?? '-' })}</span><span>{t('pos.common.covers_short', { count: d.check.covers })}</span></div>
                    <div className="flex justify-between"><span>#{d.check.number}</span><span>{formatDateTime(tk.fired_at)}</span></div>
                    {d.server && <div>{t('pos.kot.server', { name: d.server })}</div>}
                    <div className="border-t border-black my-1" />
                    {tk.items.map((it, j) => (
                        <div key={j} className={`mb-1 ${it.voided ? 'line-through' : ''}`}>
                            <div className="font-bold text-base">{it.quantity} x {it.name}{it.voided ? ` (${t('pos.kot.void')})` : ''}</div>
                            {it.modifiers?.map((m, k) => <div key={k} className="pl-4">+ {m.name}</div>)}
                            {it.notes && <div className="pl-4 italic">** {it.notes}</div>}
                        </div>
                    ))}
                </div>
            ))}
        </div>
    );
}
