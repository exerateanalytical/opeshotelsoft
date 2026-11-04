import { router } from '@inertiajs/react';
import Tabs, { type TabItem } from '@/Components/ui/Tabs';
import { useT } from '@/lib/i18n';
import { usePerms } from './ControlShared';

/** Every stock page with the permission its route requires (routes/web.php, stock2.php, stock3.php). */
const PAGES: { key: string; href: string; label: string; perm: string }[] = [
    { key: 'index', href: '/stock', label: 'stock4.tab.index', perm: 'stock.view' },
    { key: 'recipes', href: '/stock/recipes', label: 'stock4.tab.recipes', perm: 'stockcontrol.view' },
    { key: 'variance', href: '/stock/variance', label: 'stock4.tab.variance', perm: 'stockcontrol.view' },
    { key: 'reorder', href: '/stock/reorder', label: 'stock4.tab.reorder', perm: 'stockcontrol.view' },
    { key: 'batches', href: '/stock/batches', label: 'stock4.tab.batches', perm: 'stockcontrol.view' },
    { key: 'valuation', href: '/stock/valuation', label: 'stock4.tab.valuation', perm: 'stockcontrol.valuation' },
    { key: 'rfq', href: '/stock/rfq', label: 'stock4.tab.rfq', perm: 'stockops.view' },
    { key: 'scan', href: '/stock/scan', label: 'stock4.tab.scan', perm: 'stockops.scan' },
    { key: 'stocktake', href: '/stock/stocktake', label: 'stock4.tab.stocktake', perm: 'stocktake.count' },
    { key: 'minibar', href: '/stock/minibar', label: 'stock4.tab.minibar', perm: 'stockops.minibar' },
    { key: 'menu', href: '/stock/menu-engineering', label: 'stock4.tab.menu', perm: 'stockops.view' },
    { key: 'issues', href: '/stock/issues', label: 'stock4.tab.issues', perm: 'stockops.view' },
];

export type StockPage = 'index' | 'recipes' | 'variance' | 'reorder' | 'batches' | 'valuation' | 'rfq' | 'scan' | 'stocktake' | 'minibar' | 'menu' | 'issues';

/** One tab strip shared by all stock pages: lists the pages the signed-in user may open. */
export default function StockTabs({ current }: { current: StockPage }) {
    const t = useT();
    const perms = usePerms();
    const tabs: TabItem[] = PAGES.filter((p) => perms.length === 0 || perms.includes(p.perm) || p.key === current).map((p) => ({ key: p.key, label: t(p.label) }));
    return (
        <Tabs className="mb-4" label={t('stock4.tab.label')} tabs={tabs} value={current}
            onChange={(k) => { const p = PAGES.find((x) => x.key === k); if (p && k !== current) router.visit(p.href); }} />
    );
}
