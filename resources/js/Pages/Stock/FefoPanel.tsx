import { useState } from 'react';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { fcfa, num, Ref } from './ControlShared';
import { useList } from './StockForms';

interface Fefo { id: number; lot_number: string; expiry_date: string | null; received_date: string | null; quantity_remaining: number; unit_cost: number }

/** GAPC: first-expired-first-out pick order for one item in one store (stock-control/lots/fefo). */
export default function FefoPanel() {
    const t = useT();
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const stores = useList<Ref>('stock/stores?per_page=200&sort=code');
    const [itemId, setItemId] = useState('');
    const [storeId, setStoreId] = useState('');
    const ready = itemId && storeId;
    const fefo = useApi<Fefo[]>(ready ? `stock-control/lots/fefo?stock_item_id=${itemId}&store_id=${storeId}` : null, [itemId, storeId]);
    const rows = fefo.data ?? [];
    return (
        <section className="card mb-3 p-3" aria-label={t('gapc.fefo.title')}>
            <h2 className="mb-1 text-lg font-semibold">{t('gapc.fefo.title')}</h2>
            <p className="mb-3 text-sm text-slate-500">{t('gapc.fefo.hint')}</p>
            <div className="mb-3 grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                <Field label={t('gapc.col.item')}>
                    <select className="select" value={itemId} onChange={(e) => setItemId(e.target.value)}>
                        <option value="">{t('gapc.choose')}</option>{items.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                    </select>
                </Field>
                <Field label={t('gapc.par.store')}>
                    <select className="select" value={storeId} onChange={(e) => setStoreId(e.target.value)}>
                        <option value="">{t('gapc.choose')}</option>{stores.rows.map((s) => <option key={s.id} value={s.id}>{s.code} - {s.name}</option>)}
                    </select>
                </Field>
            </div>
            {!ready ? <EmptyState title={t('gapc.fefo.pick')} /> : (
                <DataTable<Fefo> caption={t('gapc.fefo.caption')} rows={rows} rowKey={(l) => l.id} loading={fefo.loading}
                    empty={<EmptyState title={t('gapc.fefo.empty')} />}
                    columns={[
                        { key: 'rank', header: t('gapc.fefo.rank'), cell: (l) => rows.indexOf(l) + 1 },
                        { key: 'lot', header: t('gapc.fefo.lot'), cell: (l) => l.lot_number, hideLabel: true },
                        { key: 'exp', header: t('gapc.fefo.expiry'), cell: (l) => (l.expiry_date ? String(l.expiry_date).slice(0, 10) : t('gapc.fefo.no_expiry')) },
                        { key: 'rec', header: t('gapc.fefo.received'), cell: (l) => (l.received_date ? String(l.received_date).slice(0, 10) : '-') },
                        { key: 'qty', header: t('gapc.fefo.qty'), align: 'right', cell: (l) => num(l.quantity_remaining) },
                        { key: 'cost', header: t('gapc.fefo.cost'), align: 'right', cell: (l) => fcfa(l.unit_cost) },
                    ]} />
            )}
        </section>
    );
}
