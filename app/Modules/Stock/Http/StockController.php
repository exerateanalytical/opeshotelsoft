<?php

namespace App\Modules\Stock\Http;

use App\Modules\Stock\Application\PurchasingExtrasService;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockCount;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\StockMovement;
use App\Modules\Stock\Domain\Store;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController
{
    public function __construct(private StockService $stock, private TenantContext $ctx) {}

    /** On-hand by store and item with value at average cost. */
    public function balances(Request $request)
    {
        $this->can($request, 'view');

        return DB::table('stock_balances as b')
            ->join('stock_items as i', 'i.id', '=', 'b.stock_item_id')
            ->join('stores as s', 's.id', '=', 'b.store_id')
            ->where('b.hotel_id', $this->ctx->requireHotelId())
            ->when($request->query('store_id'), fn ($q, $v) => $q->where('b.store_id', $v))
            ->when($request->query('category'), fn ($q, $v) => $q->where('i.category', $v))
            ->selectRaw('b.store_id, s.code as store, b.stock_item_id, i.code, i.name, i.unit, b.quantity, i.avg_cost, round(b.quantity * i.avg_cost) as value, i.reorder_level')
            ->orderBy('s.code')->orderBy('i.name')->get();
    }

    public function movements(Request $request)
    {
        $this->can($request, 'view');

        return StockMovement::query()
            ->when($request->query('stock_item_id'), fn ($q, $v) => $q->where('stock_item_id', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('id')->paginate(100);
    }

    public function transfer(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'from_store_id' => ['required', 'integer'], 'to_store_id' => ['required', 'integer', 'different:from_store_id'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'reference' => ['nullable', 'string'],
        ]);
        $from = Store::findOrFail($data['from_store_id']);
        $to = Store::findOrFail($data['to_store_id']);

        return response()->json(DB::transaction(fn () => collect($data['lines'])->map(fn ($l) => $this->stock->transfer(
            StockItem::findOrFail($l['stock_item_id']), $from, $to, $l['quantity'], ['reference' => $data['reference'] ?? null],
        ))), 201);
    }

    /** Issue to a department (housekeeping amenities, kitchen consumption) or record wastage. */
    public function issue(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'kind' => ['sometimes', 'in:issue,wastage'], 'store_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'reference' => ['nullable', 'string'],
            'reason' => [($request->input('kind') === 'wastage' ? 'required' : 'nullable'), 'string', ...($request->input('kind') === 'wastage' ? ['in:'.implode(',', PurchasingExtrasService::WASTAGE_REASONS)] : [])],
        ]);
        $store = Store::findOrFail($data['store_id']);

        return response()->json(DB::transaction(fn () => collect($data['lines'])->map(fn ($l) => $this->stock->consume(
            $data['kind'] ?? 'issue', StockItem::findOrFail($l['stock_item_id']), $store, $l['quantity'],
            ['reference' => $data['reference'] ?? null, 'reason' => $data['reason'] ?? null],
        ))), 201);
    }

    public function counts(Request $request)
    {
        $this->can($request, 'view');

        return StockCount::query()
            ->when($request->query('store_id'), fn ($q, $v) => $q->where('store_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->withCount('lines')
            ->orderByDesc('id')->paginate(50);
    }

    public function showCount(Request $request, StockCount $count)
    {
        $this->can($request, 'view');
        $count->load(['lines' => fn ($q) => $q->orderBy('id')]);
        $items = StockItem::whereIn('id', $count->lines->pluck('stock_item_id'))->get(['id', 'code', 'name', 'unit'])->keyBy('id');
        foreach ($count->lines as $l) {
            $i = $items->get($l->stock_item_id);
            $l->setAttribute('item_code', $i?->code);
            $l->setAttribute('item_name', $i?->name);
            $l->setAttribute('unit', $i?->unit);
        }

        return $count;
    }

    /** Start a count: snapshot expected quantities for a store. */
    public function startCount(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['store_id' => ['required', 'integer']]);
        $store = Store::findOrFail($data['store_id']);

        return response()->json(DB::transaction(function () use ($store) {
            $count = StockCount::create(['store_id' => $store->id, 'date' => now()->toDateString(), 'counted_by' => $this->ctx->actor()?->id]);
            $rows = DB::table('stock_balances')->where('store_id', $store->id)->get();
            foreach ($rows as $r) {
                $count->lines()->create(['stock_item_id' => $r->stock_item_id, 'expected' => $r->quantity]);
            }

            return $count->load('lines');
        }), 201);
    }

    public function recordCount(Request $request, StockCount $count)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['lines' => ['required', 'array'], 'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.counted' => ['required', 'numeric', 'min:0']]);
        if ($count->status !== 'open') {
            throw new DomainException('This count is already posted.');
        }
        foreach ($data['lines'] as $l) {
            $count->lines()->updateOrCreate(['stock_item_id' => $l['stock_item_id']], ['counted' => $l['counted'], 'expected' => $count->lines()->where('stock_item_id', $l['stock_item_id'])->value('expected') ?? 0]);
        }

        return $count->load('lines');
    }

    /** Post count variances as adjustments (needs stock.adjust). */
    public function postCount(Request $request, StockCount $count, EventBus $bus)
    {
        abort_unless($request->user()->can('stock.adjust'), 403);
        if ($count->status !== 'open') {
            throw new DomainException('This count is already posted.');
        }
        $store = Store::findOrFail($count->store_id);

        return DB::transaction(function () use ($count, $store, $bus) {
            $variances = [];
            foreach ($count->lines()->whereNotNull('counted')->get() as $l) {
                $current = $this->stock->onHand($store->id, $l->stock_item_id);
                $delta = $l->counted - $current; // movements since the snapshot are respected
                if (abs($delta) > 0.0001) {
                    $m = $this->stock->adjust(StockItem::findOrFail($l->stock_item_id), $store, $delta, "Inventaire #{$count->id}", ['source_type' => 'stock_count', 'source_id' => $count->id]);
                    $variances[] = ['stock_item_id' => $l->stock_item_id, 'delta' => $delta, 'value' => $m?->value];
                }
            }
            $count->update(['status' => 'posted']);
            $bus->record('stock.count_posted', ['id' => $count->id, 'store_id' => $store->id, 'variances' => $variances], 'stock_count', $count->id);

            return ['count' => $count, 'variances' => $variances];
        });
    }

    public function reorder(Request $request)
    {
        $this->can($request, 'view');

        return DB::table('stock_items as i')->where('i.hotel_id', $this->ctx->requireHotelId())->where('i.active', true)->where('i.reorder_level', '>', 0)
            ->leftJoin('stock_balances as b', 'b.stock_item_id', '=', 'i.id')
            ->groupBy('i.id', 'i.code', 'i.name', 'i.unit', 'i.reorder_level', 'i.reorder_quantity', 'i.preferred_supplier_id')
            ->havingRaw('coalesce(sum(b.quantity),0) <= i.reorder_level')
            ->selectRaw('i.id, i.code, i.name, i.unit, i.reorder_level, i.reorder_quantity, i.preferred_supplier_id, coalesce(sum(b.quantity),0) as on_hand')
            ->get();
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('stock.manage') || ($ability === 'view' && ($u->can('stock.view') || $u->can('purchasing.view'))), 403);
    }
}
