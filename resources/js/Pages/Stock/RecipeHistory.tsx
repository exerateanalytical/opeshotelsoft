import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { fcfa } from './ControlShared';

interface Row { id: number; date: string; plate_cost: number | string; net_price: number | string; margin_pct: number | string | null; cause: string }

/** GAPC: cost history of one recipe (stock-control/recipes/{id}/history), newest first. */
export default function RecipeHistory({ recipeId, reloadKey }: { recipeId: number; reloadKey?: number }) {
    const t = useT();
    const h = useApi<Row[]>(`stock-control/recipes/${recipeId}/history`, [recipeId, reloadKey]);
    return (
        <section className="mt-4" aria-label={t('gapc.hist.title')}>
            <h3 className="mb-2 text-base font-semibold">{t('gapc.hist.title')}</h3>
            <DataTable<Row> caption={t('gapc.hist.title')} rows={h.data ?? []} rowKey={(r) => r.id} loading={h.loading}
                empty={<EmptyState title={t('gapc.hist.empty')} description={t('gapc.hist.empty_hint')} />}
                columns={[
                    { key: 'date', header: t('gapc.hist.date'), cell: (r) => String(r.date).slice(0, 10), hideLabel: true },
                    { key: 'cost', header: t('gapc.hist.plate_cost'), align: 'right', cell: (r) => fcfa(Number(r.plate_cost)) },
                    { key: 'price', header: t('gapc.hist.net_price'), align: 'right', cell: (r) => fcfa(Number(r.net_price)) },
                    { key: 'margin', header: t('gapc.hist.margin'), align: 'right', cell: (r) => (r.margin_pct === null ? '-' : `${Number(r.margin_pct)} %`) },
                    { key: 'cause', header: t('gapc.hist.cause'), cell: (r) => { const k = `gapc.hist.cause.${r.cause}`; const v = t(k); return v === k ? r.cause : v; } },
                ]} />
        </section>
    );
}
