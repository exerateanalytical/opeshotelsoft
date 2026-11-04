import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import Button from '@/Components/ui/Button';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import { Head } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatCurrency, formatDate } from '@/lib/utils';
import { StockPdfButton } from '@/Pages/Stock/gapcShared';
import { Paged, Ref } from '@/Pages/Stock/ControlShared';

interface Line { id: number; stock_item_id: number; quantity: number | string; received_quantity: number | string; unit_price: number | string; vat_rate: number | string }
interface Order { id: number; number: string; status: string; supplier_id: number; store_id: number; order_date: string; expected_date: string | null; notes: string | null; total: number | string; net_total: number | string; supplier?: { id: number; name: string }; lines: Line[] }

const BADGE: Record<string, string> = { draft: 'badge-slate', submitted: 'badge-blue', approved: 'badge-green', partially_received: 'badge-yellow', received: 'badge-green', cancelled: 'badge-red' };

/** GAPC: purchase order detail with print (reached from the PO lists). */
export default function OrderShow({ orderId }: { orderId: number }) {
    const t = useT();
    const order = useApi<Order>(`purchasing/orders/${orderId}`, [orderId]);
    const items = useApi<Paged<Ref>>('stock/items?per_page=500&sort=name');
    const stores = useApi<Paged<Ref>>('stock/stores?per_page=200&sort=code');
    const o = order.data;
    const iName = (id: number) => items.data?.data.find((i) => i.id === id)?.name ?? `#${id}`;
    const sName = (id: number) => stores.data?.data.find((s) => s.id === id)?.name ?? `#${id}`;

    return (
        <AppLayout title={o ? t('gapc.po.title', { number: o.number }) : t('gapc.po.title_loading')} subtitle={t('gapc.po.subtitle')}
            actions={<><Button variant="secondary" icon={ArrowLeft} href="/stock">{t('gapc.po.back')}</Button>{o && <StockPdfButton path={`documents/purchase-orders/${o.id}`} label={t('gapc.po.print')} className="btn-primary" />}</>}>
            <Head title={o ? t('gapc.po.title', { number: o.number }) : t('gapc.po.title_loading')} />
            <QueryStatus query={order} variant="detail" />
            {o && (
                <div className="grid gap-4">
                    <dl className="card grid grid-cols-1 gap-x-6 gap-y-2 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4 [&>div]:min-w-0">
                        <div><dt className="text-slate-500">{t('gapc.po.status')}</dt><dd><span className={BADGE[o.status] ?? 'badge-slate'}>{t(`stock.status.${o.status}`)}</span></dd></div>
                        <div><dt className="text-slate-500">{t('gapc.po.supplier')}</dt><dd>{o.supplier?.name ?? `#${o.supplier_id}`}</dd></div>
                        <div><dt className="text-slate-500">{t('gapc.po.store')}</dt><dd>{sName(o.store_id)}</dd></div>
                        <div><dt className="text-slate-500">{t('gapc.po.order_date')}</dt><dd>{formatDate(o.order_date)}</dd></div>
                        <div><dt className="text-slate-500">{t('gapc.po.expected')}</dt><dd>{o.expected_date ? formatDate(o.expected_date) : '-'}</dd></div>
                        <div><dt className="text-slate-500">{t('gapc.po.net')}</dt><dd>{formatCurrency(Number(o.net_total), 'XAF')}</dd></div>
                        <div><dt className="text-slate-500">{t('gapc.po.total')}</dt><dd className="font-semibold">{formatCurrency(Number(o.total), 'XAF')}</dd></div>
                        {o.notes && <div className="sm:col-span-2"><dt className="text-slate-500">{t('gapc.po.notes')}</dt><dd>{o.notes}</dd></div>}
                    </dl>
                    <div className="card p-3">
                        <DataTable<Line> caption={t('gapc.po.lines')} rows={o.lines} rowKey={(l) => l.id}
                            empty={<EmptyState title={t('gapc.po.no_lines')} />}
                            columns={[
                                { key: 'item', header: t('gapc.col.item'), cell: (l) => iName(l.stock_item_id), hideLabel: true },
                                { key: 'qty', header: t('gapc.po.ordered'), align: 'right', cell: (l) => Number(l.quantity) },
                                { key: 'rec', header: t('gapc.po.received'), align: 'right', cell: (l) => Number(l.received_quantity) },
                                { key: 'price', header: t('gapc.po.unit_price'), align: 'right', cell: (l) => formatCurrency(Number(l.unit_price), 'XAF') },
                                { key: 'vat', header: t('gapc.po.vat'), align: 'right', cell: (l) => `${Number(l.vat_rate)} %` },
                                { key: 'sum', header: t('gapc.po.line_total'), align: 'right', cell: (l) => formatCurrency(Number(l.quantity) * Number(l.unit_price), 'XAF') },
                            ]} />
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
