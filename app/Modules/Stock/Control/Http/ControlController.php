<?php

namespace App\Modules\Stock\Control\Http;

use App\Modules\Stock\Control\Application\LotService;
use App\Modules\Stock\Control\Application\RecipeService;
use App\Modules\Stock\Control\Application\ReorderService;
use App\Modules\Stock\Control\Application\UomService;
use App\Modules\Stock\Control\Application\ValuationService;
use App\Modules\Stock\Control\Application\VarianceService;
use App\Modules\Stock\Control\Domain\ParLevel;
use App\Modules\Stock\Control\Domain\Recipe;
use App\Modules\Stock\Control\Domain\StockLot;
use App\Modules\Stock\Control\Domain\SupplierLeadTime;
use App\Modules\Stock\Control\Domain\UsageVarianceReview;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Inventory control API (I-A). Mounted under /api/v1/stock-control. */
class ControlController
{
    public function __construct(
        private RecipeService $recipes, private UomService $uom, private VarianceService $variance, private ReorderService $reorder,
        private LotService $lots, private ValuationService $valuation, private TenantContext $ctx,
    ) {}

    private function allow(Request $r, string $ability): void
    {
        abort_unless($r->user()->can("stockcontrol.$ability"), 403);
    }

    private function today(): string
    {
        return Hotel::findOrFail($this->ctx->requireHotelId())->business_date->toDateString();
    }

    /** The latest date a valuation may be asked for: the hotel's calendar date, or its business date if that is later. The business date lags until the night audit runs. */
    private function latestValuationDate(): string
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $calendar = now($hotel->timezone ?: 'Africa/Douala')->toDateString();

        return max($hotel->business_date->toDateString(), $calendar);
    }

    // ---- Recipes ----------------------------------------------------------------------------------

    public function recipes(Request $r)
    {
        $this->allow($r, 'view');

        return Recipe::query()->when($r->query('kind'), fn ($q, $v) => $q->where('kind', $v))->orderBy('name')->get()->map(function (Recipe $x) {
            $a = $this->recipes->analysis($x);

            return $x->toArray() + ['price' => $a['price'], 'margin_pct' => $a['margin_pct'], 'food_cost_pct' => $a['food_cost_pct'], 'below_target' => $a['below_target'], 'unit_cost' => $a['plate_cost']];
        });
    }

    public function showRecipe(Request $r, Recipe $recipe)
    {
        $this->allow($r, 'view');

        return $recipe->load('lines')->toArray() + ['analysis' => $this->recipes->analysis($recipe)];
    }

    public function storeRecipe(Request $r)
    {
        $this->allow($r, 'manage');
        $recipe = $this->recipes->save($this->recipeData($r));

        return response()->json($recipe->toArray() + ['analysis' => $this->recipes->analysis($recipe)], 201);
    }

    public function updateRecipe(Request $r, Recipe $recipe)
    {
        $this->allow($r, 'manage');
        $recipe = $this->recipes->save($this->recipeData($r, true), $recipe);

        return $recipe->toArray() + ['analysis' => $this->recipes->analysis($recipe)];
    }

    private function recipeData(Request $r, bool $update = false): array
    {
        return $r->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:255'], 'kind' => ['sometimes', 'in:dish,sub_recipe'],
            'pos_menu_item_id' => ['nullable', 'integer'], 'yield_qty' => ['sometimes', 'numeric', 'gt:0'], 'yield_unit' => ['sometimes', 'string', 'max:10'],
            'sell_vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'target_margin_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'], 'active' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['nullable', 'integer'], 'lines.*.sub_recipe_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit' => ['nullable', 'string', 'max:10'], 'lines.*.yield_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
        ]);
    }

    public function recost(Request $r, Recipe $recipe)
    {
        $this->allow($r, 'manage');
        $this->recipes->recost($recipe, 'recost');

        return $recipe->fresh()->toArray() + ['analysis' => $this->recipes->analysis($recipe)];
    }

    public function recipeHistory(Request $r, Recipe $recipe)
    {
        $this->allow($r, 'view');

        return DB::table('recipe_cost_history')->where('recipe_id', $recipe->id)->orderByDesc('id')->limit(100)->get();
    }

    // ---- Units of measure --------------------------------------------------------------------------

    public function units(Request $r, StockItem $item)
    {
        $this->allow($r, 'view');

        return $this->uom->units($item);
    }

    public function defineUnit(Request $r, StockItem $item)
    {
        $this->allow($r, 'manage');
        $d = $r->validate(['unit' => ['required', 'string', 'max:10'], 'factor' => ['required', 'numeric', 'gt:0'], 'role' => ['sometimes', 'in:recipe,purchase,other']]);
        $this->uom->define($item, $d['unit'], (float) $d['factor'], $d['role'] ?? 'recipe');

        return response()->json($this->uom->units($item), 201);
    }

    public function convert(Request $r, StockItem $item)
    {
        $this->allow($r, 'view');
        $d = $r->validate(['qty' => ['required', 'numeric'], 'from' => ['required', 'string'], 'to' => ['required', 'string']]);

        return ['qty' => $d['qty'], 'from' => $d['from'], 'to' => $d['to'], 'result' => round($this->uom->convert($item, (float) $d['qty'], $d['from'], $d['to']), 6)];
    }

    // ---- Usage variance ----------------------------------------------------------------------------

    public function variance(Request $r)
    {
        $this->allow($r, 'view');
        $d = $r->validate(['outlet_id' => ['required', 'integer'], 'from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return $this->variance->compute((int) $d['outlet_id'], $d['from'], $d['to']) + ['reasons' => VarianceService::REASONS];
    }

    public function reviews(Request $r)
    {
        $this->allow($r, 'view');

        return UsageVarianceReview::query()->when($r->query('outlet_id'), fn ($q, $v) => $q->where('outlet_id', $v))->orderByDesc('id')->limit(100)->get();
    }

    public function saveReview(Request $r)
    {
        $this->allow($r, 'manage');
        $d = $r->validate(['outlet_id' => ['required', 'integer'], 'from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return response()->json($this->variance->saveReview((int) $d['outlet_id'], $d['from'], $d['to']), 201);
    }

    public function showReview(Request $r, UsageVarianceReview $review)
    {
        $this->allow($r, 'view');

        return $review->load('lines');
    }

    public function annotate(Request $r, UsageVarianceReview $review, int $line)
    {
        $this->allow($r, 'manage');
        $d = $r->validate(['reason_code' => ['nullable', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->variance->annotate($review, $line, $d['reason_code'] ?? null, $d['note'] ?? null);

        return $review->fresh('lines');
    }

    public function finalizeReview(Request $r, UsageVarianceReview $review)
    {
        $this->allow($r, 'manage');
        $review->update(['status' => 'final']);

        return $review;
    }

    // ---- Par levels and reorder --------------------------------------------------------------------

    public function parLevels(Request $r)
    {
        $this->allow($r, 'view');

        return ParLevel::query()->when($r->query('store_id'), fn ($q, $v) => $q->where('store_id', $v))->orderBy('store_id')->orderBy('stock_item_id')->get();
    }

    public function savePar(Request $r)
    {
        $this->allow($r, 'manage');
        $d = $r->validate([
            'store_id' => ['required', 'integer'], 'stock_item_id' => ['required', 'integer'], 'par_level' => ['required', 'numeric', 'gt:0'],
            'min_level' => ['sometimes', 'numeric', 'min:0', 'lte:par_level'], 'supplier_id' => ['nullable', 'integer'], 'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
        Store::findOrFail($d['store_id']);
        StockItem::findOrFail($d['stock_item_id']);
        if (! empty($d['supplier_id'])) {
            Supplier::findOrFail($d['supplier_id']);
        }
        $par = ParLevel::updateOrCreate(['store_id' => $d['store_id'], 'stock_item_id' => $d['stock_item_id']], collect($d)->except(['store_id', 'stock_item_id'])->all());

        return response()->json($par, 201);
    }

    public function deletePar(Request $r, ParLevel $par)
    {
        $this->allow($r, 'manage');
        $par->delete();

        return response()->noContent();
    }

    public function leadTimes(Request $r)
    {
        $this->allow($r, 'view');

        return Supplier::orderBy('name')->get(['id', 'code', 'name'])->map(fn ($s) => $s->toArray() + ['lead_time_days' => $this->reorder->leadTime($s->id)]);
    }

    public function setLeadTime(Request $r, Supplier $supplier)
    {
        $this->allow($r, 'manage');
        $d = $r->validate(['lead_time_days' => ['required', 'integer', 'min:0', 'max:365']]);

        return $this->reorder->setLeadTime($supplier->id, (int) $d['lead_time_days']);
    }

    public function suggestions(Request $r)
    {
        $this->allow($r, 'view');

        return ['groups' => $this->reorder->grouped($r->query('store_id') ? (int) $r->query('store_id') : null)];
    }

    public function convertSuggestions(Request $r)
    {
        $this->allow($r, 'manage');
        abort_unless($r->user()->can('purchasing.manage'), 403);
        $d = $r->validate(['supplier_ids' => ['sometimes', 'array'], 'supplier_ids.*' => ['integer'], 'store_id' => ['sometimes', 'integer']]);

        return response()->json($this->reorder->convert($d['supplier_ids'] ?? null, $d['store_id'] ?? null), 201);
    }

    // ---- Lots and expiry ---------------------------------------------------------------------------

    public function lots(Request $r)
    {
        $this->allow($r, 'view');
        $rows = StockLot::query()
            ->when($r->query('stock_item_id'), fn ($q, $v) => $q->where('stock_item_id', $v))->when($r->query('store_id'), fn ($q, $v) => $q->where('store_id', $v))
            ->when($r->query('status', 'active') === 'active', fn ($q) => $q->where('quantity_remaining', '>', 0))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('id')->limit(500)->get();
        $items = StockItem::whereIn('id', $rows->pluck('stock_item_id'))->get(['id', 'name', 'unit'])->keyBy('id');

        return $rows->map(fn ($l) => $l->toArray() + ['item' => $items->get($l->stock_item_id)?->name, 'unit' => $items->get($l->stock_item_id)?->unit]);
    }

    public function receiveLot(Request $r)
    {
        $this->allow($r, 'manage');
        $d = $r->validate([
            'stock_item_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'], 'lot_number' => ['required', 'string', 'max:40'], 'expiry_date' => ['nullable', 'date'],
        ]);

        return response()->json($this->lots->receiveLot(StockItem::findOrFail($d['stock_item_id']), Store::findOrFail($d['store_id']), (float) $d['quantity'], (float) $d['unit_cost'], $d['lot_number'], $d['expiry_date'] ?? null), 201);
    }

    public function updateLot(Request $r, StockLot $lot)
    {
        $this->allow($r, 'manage');
        $d = $r->validate(['lot_number' => ['sometimes', 'string', 'max:40'], 'expiry_date' => ['nullable', 'date']]);
        $lot->update($d + ['alerted_at' => null]);

        return $lot;
    }

    public function expiry(Request $r)
    {
        $this->allow($r, 'view');

        return $this->lots->expiry($r->query('days') !== null ? (int) $r->query('days') : null, $r->query('store_id') ? (int) $r->query('store_id') : null);
    }

    public function fefo(Request $r)
    {
        $this->allow($r, 'view');
        $d = $r->validate(['stock_item_id' => ['required', 'integer'], 'store_id' => ['required', 'integer']]);

        return $this->lots->fefoQuery((int) $d['stock_item_id'], (int) $d['store_id'])->get();
    }

    public function writeOff(Request $r, StockLot $lot)
    {
        $this->allow($r, 'writeoff');
        $d = $r->validate(['quantity' => ['nullable', 'numeric', 'gt:0'], 'reason' => ['sometimes', 'string'], 'note' => ['nullable', 'string', 'max:200']]);
        $m = $this->lots->writeOff($lot, isset($d['quantity']) ? (float) $d['quantity'] : null, $d['reason'] ?? 'expired', $d['note'] ?? null);

        return response()->json(['movement' => $m, 'lot' => $lot->fresh()], 201);
    }

    public function itemControl(Request $r, StockItem $item)
    {
        $this->allow($r, 'view');

        return $this->lots->control($item->id)->fresh();
    }

    public function setItemControl(Request $r, StockItem $item)
    {
        $this->allow($r, 'manage');
        $d = $r->validate(['tracks_lots' => ['sometimes', 'boolean'], 'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'expiry_alert_days' => ['sometimes', 'integer', 'min:0', 'max:365']]);

        return $this->lots->setControl($item, $d);
    }

    // ---- Valuation ---------------------------------------------------------------------------------

    public function valuation(Request $r)
    {
        $this->allow($r, 'valuation');
        $d = $r->validate(['date' => ['sometimes', 'date'], 'store_id' => ['sometimes', 'integer'], 'category' => ['sometimes', 'string'], 'layers' => ['sometimes', 'boolean']]);
        if (isset($d['store_id'])) {
            Store::findOrFail($d['store_id']);
        }
        if (($d['date'] ?? $this->today()) > $this->latestValuationDate()) {
            throw new DomainException('The valuation date cannot be in the future.', 'date-invalid');
        }

        return $this->valuation->asOf($d['date'] ?? $this->today(), $d['store_id'] ?? null, $d['category'] ?? null, (bool) ($d['layers'] ?? false));
    }
}
