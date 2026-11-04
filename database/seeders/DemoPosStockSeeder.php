<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\POS\Domain\PosTable;
use App\Modules\POS\Domain\PriceList;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Stock\Application\PosStockAvailability;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Control\Application\LotService;
use App\Modules\Stock\Control\Application\RecipeService;
use App\Modules\Stock\Control\Domain\ParLevel;
use App\Modules\Stock\Control\Domain\Recipe;
use App\Modules\Stock\Control\Domain\SupplierLeadTime;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Stock\Domain\SupplierPrice;
use App\Modules\Stock\Operations\Application\StocktakeService;
use App\Modules\Stock\Operations\Domain\Stocktake;
use App\Modules\Stock\Operations\Domain\StocktakeLine;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Makes the POS sellable in every hotel of the demo data: outlet stores stocked for their menu (recorded as receipts
 * and lot receipts through StockService/LotService, so ledgers, average cost and the GL stay consistent), main stores
 * stocked, every stock-consuming menu item linked to its components or a recipe, floor plans, a happy hour, recipes
 * with yields, par levels, supplier prices and lead times, perishable lots with expiry and one posted stocktake.
 * Idempotent: re-running only tops up stores that fell below half their target and skips what exists.
 *
 *   php artisan db:seed --class=DemoPosStockSeeder
 */
class DemoPosStockSeeder extends Seeder
{
    /** code => [name, category, unit, inventory account, cost account, unit cost, supplier, lead days, shelf life days (perishable lot) or null] */
    private const CATALOGUE = [
        'BEER65' => ['Bière 65cl', 'beverage', 'btl', '311200', '603120', 700, 'BRASS', 3, null],
        'SODA' => ['Soda 33cl', 'beverage', 'btl', '311200', '603120', 280, 'BOISS', 3, null],
        'WATER' => ['Eau minérale 50cl', 'beverage', 'btl', '311200', '603120', 180, 'BOISS', 3, null],
        'WINE' => ['Vin rouge (bouteille)', 'beverage', 'btl', '311200', '603120', 4200, 'BOISS', 7, null],
        'WHISKY' => ['Whisky (bouteille)', 'beverage', 'btl', '311200', '603120', 9500, 'BOISS', 7, null],
        'JUICE' => ['Jus de fruit 1L', 'beverage', 'btl', '311200', '603120', 900, 'BOISS', 3, null],
        'POULET' => ['Poulet entier', 'food', 'kg', '311100', '603110', 2200, 'VIANDE', 1, 30],
        'POISSON' => ['Poisson capitaine', 'food', 'kg', '311100', '603110', 3500, 'POISS', 1, 14],
        'BOEUF' => ['Viande de bœuf', 'food', 'kg', '311100', '603110', 3200, 'VIANDE', 1, 30],
        'CREVETTE' => ['Crevettes', 'food', 'kg', '311100', '603110', 5200, 'POISS', 2, 21],
        'TOMATE' => ['Tomates', 'food', 'kg', '311100', '603110', 600, 'FRESH', 1, 12],
        'OEUF' => ['Œufs (plateau)', 'food', 'pc', '311100', '603110', 1900, 'FRESH', 1, 21],
        'RIZ' => ['Riz', 'food', 'kg', '311100', '603110', 650, 'EPICE', 5, null],
        'PLANTAIN' => ['Plantain', 'food', 'kg', '311100', '603110', 450, 'FRESH', 1, null],
        'OIGNON' => ['Oignons', 'food', 'kg', '311100', '603110', 500, 'FRESH', 2, null],
        'HUILE' => ['Huile de palme', 'food', 'l', '311100', '603110', 1300, 'EPICE', 5, null],
        'ARACHIDE' => ['Pâte d\'arachide', 'food', 'kg', '311100', '603110', 1800, 'EPICE', 5, null],
    ];

    /** Keyword (in code or name, lower case) => components per portion; first match wins. Used only for items with no bill of materials. */
    private const KEYWORDS = [
        ['bi[eè]re|beer|b65', [['BEER65', 1]]], ['soda|coca|fanta|top ', [['SODA', 1]]], ['eau|water', [['WATER', 1]]],
        ['vin|wine', [['WINE', 0.2]]], ['whisky', [['WHISKY', 0.06]]], ['jus|juice', [['JUICE', 0.33]]],
        ['ndol', [['CREVETTE', 0.15], ['HUILE', 0.05]]], ['soupe', [['POISSON', 0.2]]], ['poisson|fish', [['POISSON', 0.4], ['PLANTAIN', 0.3]]],
        ['poulet|chicken', [['POULET', 0.5], ['PLANTAIN', 0.3]]], ['riz|rice', [['RIZ', 0.25], ['BOEUF', 0.15]]],
        ['brochette|b[oœ]euf|beef|steak', [['BOEUF', 0.25]]], ['omelette|oeuf|œuf|egg', [['OEUF', 0.1], ['OIGNON', 0.05]]],
        ['salade|salad', [['TOMATE', 0.2], ['OIGNON', 0.05]]],
    ];

    /** Dish recipes (yield in portions) — they take precedence over the flat components at sale time. */
    private const RECIPES = [
        'NDOLE' => ['Ndolè crevettes (10 portions)', 10, [['CREVETTE', 1.5, 90], ['HUILE', 0.5, 100], ['ARACHIDE', 0.8, 100], ['OIGNON', 0.3, 90]]],
        'POULETDG' => ['Poulet DG (8 portions)', 8, [['POULET', 4.0, 80], ['PLANTAIN', 2.4, 85], ['@SAUCE', 1.0, 100]]],
        'POISSON' => ['Poisson braisé (6 portions)', 6, [['POISSON', 2.4, 75], ['PLANTAIN', 1.8, 85], ['@SAUCE', 0.6, 100]]],
        'RIZ' => ['Riz sauce arachide (10 portions)', 10, [['RIZ', 2.5, 100], ['BOEUF', 1.5, 90], ['ARACHIDE', 0.6, 100]]],
    ];

    private ?TenantContext $ctx = null;

    private array $counts = [];

    public function run(): void
    {
        if (app()->isProduction() && ! filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Production environment: DemoPosStockSeeder skipped (set ALLOW_DEMO_SEED=true to force).');

            return;
        }
        $this->ctx = app(TenantContext::class);
        $users = User::orderBy('id')->take(2)->get();
        if ($users->count() < 2) {
            $this->command?->warn('DemoPosStockSeeder needs two users (stocktake approval); run DemoSeeder first.');

            return;
        }
        [$actor, $approver] = [$users[0], $users[1]];
        $hotels = $this->ctx->withoutScope(fn () => Hotel::orderBy('id')->get());

        foreach ($hotels as $hotel) {
            $this->seedHotel($hotel, $actor, $approver);
        }
        $this->ctx->set(null, null, null);
        $this->command?->info('DemoPosStockSeeder done: '.json_encode($this->counts));
    }

    /** One hotel; DemoBulkSeeder calls this right after building a hotel's menu, before simulating its sales. */
    public function seedHotel(Hotel $hotel, User $actor, User $approver): void
    {
        $this->ctx ??= app(TenantContext::class);
        $previous = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        try {
            $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $actor);
            $suppliers = $this->suppliers();
            $items = $this->items($suppliers);
            $this->components($items);
            $this->recipes($items);
            $targets = $this->stock($hotel, $items);
            $this->purchasing($items, $suppliers, $targets, $hotel);
            $this->floor();
            $this->priceLists();
            $this->stocktake($actor, $approver);
            $this->command?->info("{$hotel->code}: POS and stock demo data ready.");
        } finally {
            $this->ctx->set(...$previous);
        }
    }

    private function bump(string $k, int $n = 1): void
    {
        $this->counts[$k] = ($this->counts[$k] ?? 0) + $n;
    }

    /** @return array<string, Supplier> */
    private function suppliers(): array
    {
        $out = [];
        foreach ([['BRASS', 'Brasseries du Cameroun', 30, 3], ['BOISS', 'Sodiscam Boissons', 30, 3], ['FRESH', 'Demo Produits Frais', 15, 1], ['VIANDE', 'Boucherie Centrale', 7, 1],
            ['POISS', 'Pêcherie de Kribi', 7, 2], ['EPICE', 'Grossiste Mokolo', 30, 5], ['HYG', 'Hygiène Pro', 30, 7], ['QUINC', 'Quincaillerie Moderne', 30, 10]] as [$c, $n, $terms, $lead]) {
            $out[$c] = Supplier::firstOrCreate(['code' => $c], ['name' => $n, 'payment_terms_days' => $terms]);
            if (! SupplierLeadTime::where('supplier_id', $out[$c]->id)->exists()) {
                SupplierLeadTime::create(['supplier_id' => $out[$c]->id, 'lead_time_days' => $lead]);
                $this->bump('supplier_lead_times');
            }
        }

        return $out;
    }

    /** @return array<string, StockItem> */
    private function items(array $suppliers): array
    {
        $out = [];
        foreach (self::CATALOGUE as $code => [$name, $cat, $unit, $inv, $cost, , $sup]) {
            $out[$code] = StockItem::firstOrCreate(['code' => $code], [
                'name' => $name, 'category' => $cat, 'unit' => $unit, 'inventory_account' => $inv, 'cost_account' => $cost, 'reorder_level' => 10,
            ]);
            if (! $out[$code]->preferred_supplier_id) {
                $out[$code]->update(['preferred_supplier_id' => $suppliers[$sup]->id]);
            }
        }

        return $out;
    }

    /** Every active menu item without a bill of materials (and without a recipe) gets components by keyword. */
    private function components(array $items): void
    {
        $recipes = app(RecipeService::class);
        foreach (MenuItem::where('active', true)->get() as $mi) {
            if ($mi->components()->exists() || $recipes->recipeFor($mi->id)) {
                continue;
            }
            $hay = mb_strtolower($mi->code.' '.$mi->name);
            foreach (self::KEYWORDS as [$re, $comps]) {
                if (preg_match('/'.$re.'/u', $hay)) {
                    foreach ($comps as [$code, $q]) {
                        $mi->components()->create(['stock_item_id' => $items[$code]->id, 'quantity' => $q]);
                        $this->bump('pos_item_components');
                    }
                    break;
                }
            }
        }
    }

    private function recipes(array $items): void
    {
        $svc = app(RecipeService::class);
        $sauce = Recipe::where('code', 'SAUCE-TOM')->first() ?? $svc->save([
            'code' => 'SAUCE-TOM', 'name' => 'Sauce tomate maison (2 l)', 'kind' => 'sub_recipe', 'yield_qty' => 2, 'yield_unit' => 'l',
            'lines' => [['stock_item_id' => $items['TOMATE']->id, 'quantity' => 1.5, 'yield_pct' => 85], ['stock_item_id' => $items['OIGNON']->id, 'quantity' => 0.3, 'yield_pct' => 90],
                ['stock_item_id' => $items['HUILE']->id, 'quantity' => 0.2]],
        ]);
        if ($sauce->wasRecentlyCreated) {
            $this->bump('recipes');
        }
        foreach (self::RECIPES as $menuCode => [$name, $yield, $lines]) {
            $mi = MenuItem::where('code', $menuCode)->first();
            if (! $mi || Recipe::where('code', 'R-'.$menuCode)->exists() || $svc->recipeFor($mi->id)) {
                continue;
            }
            $svc->save([
                'code' => 'R-'.$menuCode, 'name' => $name, 'kind' => 'dish', 'pos_menu_item_id' => $mi->id, 'yield_qty' => $yield, 'yield_unit' => 'portion',
                'sell_vat_rate' => 19.25, 'target_margin_pct' => 65,
                'lines' => array_map(fn ($l) => $l[0] === '@SAUCE'
                    ? ['sub_recipe_id' => $sauce->id, 'quantity' => $l[1], 'yield_pct' => $l[2]]
                    : ['stock_item_id' => $items[$l[0]]->id, 'quantity' => $l[1], 'yield_pct' => $l[2]], $lines),
            ]);
            $this->bump('recipes');
        }
    }

    /**
     * Outlet stores: every stock item the outlet's menu consumes, ~300 portions' worth; main store: every item.
     * @return array<int, array<int, float>> store id => item id => target
     */
    private function stock(Hotel $hotel, array $catalogue): array
    {
        $stock = app(StockService::class);
        $lots = app(LotService::class);
        $need = app(PosStockAvailability::class);
        $date = $hotel->business_date ?? now();
        $targets = [];

        foreach (Store::where('active', true)->whereNotNull('outlet_id')->get() as $store) {
            $perPortion = [];
            foreach (MenuItem::where('active', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $store->outlet_id))->pluck('id') as $id) {
                foreach ($need->requirements([['pos_menu_item_id' => $id, 'quantity' => 1]]) as $itemId => $q) {
                    $perPortion[$itemId] = ($perPortion[$itemId] ?? 0) + $q;
                }
            }
            foreach ($perPortion as $itemId => $q) {
                $item = StockItem::find($itemId);
                $targets[$store->id][$itemId] = max($item->unit === 'btl' ? 48 : 10, ceil($q * 300));
            }
        }
        foreach (Store::where('active', true)->where('kind', 'main')->get() as $main) {
            foreach (StockItem::where('active', true)->get() as $item) {
                $targets[$main->id][$item->id] = match ($item->category) {
                    'beverage' => 240, 'food' => 120, 'amenities' => 600, default => 60,
                };
            }
        }

        foreach ($targets as $storeId => $byItem) {
            $store = Store::find($storeId);
            foreach ($byItem as $itemId => $target) {
                $item = StockItem::find($itemId);
                $on = $stock->onHand($storeId, $itemId);
                if ($on >= $target / 2) {
                    continue;
                }
                $qty = round($target - $on, 3);
                $cat = self::CATALOGUE[$item->code] ?? null;
                $cost = (float) $item->avg_cost > 0 ? (float) $item->avg_cost : (float) ($cat[5] ?? 1000);
                $meta = ['reference' => 'OPENING-POS', 'date' => $date];
                if ($cat && $cat[8]) {
                    $lotNo = sprintf('L%s-%s-%s', $store->code, $item->code, $date->format('ymd'));
                    $lots->receiveLot($item, $store, $qty, $cost, $lotNo, $date->copy()->addDays($cat[8])->toDateString(), $meta);
                    $this->bump('stock_lots');
                } else {
                    $stock->receive($item, $store, $qty, $cost, $meta);
                }
                $this->bump('opening_receipts');
            }
        }

        return $targets;
    }

    private function purchasing(array $items, array $suppliers, array $targets, Hotel $hotel): void
    {
        $from = ($hotel->business_date ?? now())->copy()->subDays(30)->toDateString();
        foreach ($items as $code => $item) {
            [, , , , , $cost, $sup, $lead] = self::CATALOGUE[$code];
            // Preferred supplier list price, its volume break, and a dearer alternative supplier to compare against.
            $alt = $sup === 'EPICE' ? 'FRESH' : 'EPICE';
            foreach ([[$suppliers[$sup], $cost, 1], [$suppliers[$sup], (int) round($cost * 0.94), 50], [$suppliers[$alt], (int) round($cost * 1.08), 1]] as [$s, $price, $min]) {
                $sp = SupplierPrice::firstOrCreate(['supplier_id' => $s->id, 'stock_item_id' => $item->id, 'min_quantity' => $min], ['unit_price' => $price, 'valid_from' => $from]);
                if ($sp->wasRecentlyCreated) {
                    $this->bump('supplier_prices');
                }
            }
        }
        foreach ($targets as $storeId => $byItem) {
            foreach ($byItem as $itemId => $target) {
                $item = StockItem::find($itemId);
                $cat = self::CATALOGUE[$item->code] ?? null;
                $p = ParLevel::firstOrCreate(['store_id' => $storeId, 'stock_item_id' => $itemId], [
                    'par_level' => $target, 'min_level' => round($target * 0.3, 2),
                    'supplier_id' => $item->preferred_supplier_id ?? ($cat ? $suppliers[$cat[6]]->id : $suppliers['QUINC']->id), 'lead_time_days' => $cat[7] ?? 7,
                ]);
                if ($p->wasRecentlyCreated) {
                    $this->bump('par_levels');
                }
            }
        }
    }

    private function floor(): void
    {
        foreach (Outlet::where('active', true)->whereIn('kind', ['restaurant', 'bar'])->get() as $o) {
            $layout = $o->kind === 'restaurant'
                ? [['T1', 2], ['T2', 2], ['T3', 4], ['T4', 4], ['T5', 4], ['T6', 4], ['T7', 6], ['T8', 6], ['T9', 8], ['T10', 2], ['Terrasse 1', 4], ['Terrasse 2', 4]]
                : [['Comptoir', 6], ['B1', 2], ['B2', 2], ['B3', 4], ['Salon', 6]];
            foreach ($layout as $i => [$name, $seats]) {
                $t = PosTable::firstOrCreate(['outlet_id' => $o->id, 'name' => $name], ['seats' => $seats, 'pos_x' => $i % 4, 'pos_y' => intdiv($i, 4), 'active' => true]);
                if ($t->wasRecentlyCreated) {
                    $this->bump('pos_tables');
                }
            }
        }
    }

    private function priceLists(): void
    {
        foreach (Outlet::where('active', true)->where('kind', 'bar')->get() as $o) {
            $l = PriceList::firstOrCreate(['outlet_id' => $o->id, 'name' => 'Happy hour'], [
                'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '17:00:00', 'ends_at' => '19:00:00', 'percent_off' => 20, 'priority' => 10, 'active' => true,
            ]);
            if ($l->wasRecentlyCreated) {
                $this->bump('pos_price_lists');
                if ($beer = MenuItem::where('code', 'B65')->first()) {
                    $l->items()->create(['pos_menu_item_id' => $beer->id, 'price' => 1500]);
                    $this->bump('pos_price_list_items');
                }
            }
        }
        foreach (Outlet::where('active', true)->where('kind', 'restaurant')->get() as $o) {
            $l = PriceList::firstOrCreate(['outlet_id' => $o->id, 'name' => 'Déjeuner affaires'], [
                'days' => [1, 2, 3, 4, 5], 'starts_at' => '12:00:00', 'ends_at' => '14:00:00', 'percent_off' => 10, 'priority' => 5, 'active' => true,
            ]);
            if ($l->wasRecentlyCreated) {
                $this->bump('pos_price_lists');
            }
        }
    }

    /** One completed stocktake on the main store: counted by the actor, approved by someone else (segregation of duties). */
    private function stocktake(User $counter, User $approver): void
    {
        if ($counter->id === $approver->id || Stocktake::where('status', 'posted')->exists()) {
            return;
        }
        $main = Store::where('kind', 'main')->where('active', true)->first();
        if (! $main || ! DB::table('stock_balances')->where('store_id', $main->id)->where('quantity', '>', 0)->exists()) {
            return;
        }
        $svc = app(StocktakeService::class);
        $st = $svc->start($main->id, blind: true, tolerancePct: 50, toleranceQty: 5);
        $counts = [];
        foreach (StocktakeLine::where('stocktake_id', $st->id)->orderBy('id')->get() as $i => $l) {
            $expected = (float) $l->expected;
            // A couple of small, realistic variances (breakage, miscount) within tolerance.
            $counts[] = ['stock_item_id' => $l->stock_item_id, 'quantity' => max(0, $i % 7 === 3 ? $expected - 1 : $expected)];
        }
        $svc->record($st, $counts, $counter);
        $st = $svc->closeCount($st->fresh());
        if ($st->status === 'review') {
            $svc->approve($st, $approver, 'Inventaire de démonstration');
            $this->bump('stocktakes');
        }
    }
}
