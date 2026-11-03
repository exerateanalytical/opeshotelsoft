<?php

namespace App\Modules\Stock\Operations\Http;

use App\Modules\Stock\Control\Domain\StockLot;
use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Operations\Application\BarcodeService;
use App\Modules\Stock\Operations\Application\IssueService;
use App\Modules\Stock\Operations\Application\MenuEngineeringService;
use App\Modules\Stock\Operations\Application\MinibarService;
use App\Modules\Stock\Operations\Application\RfqService;
use App\Modules\Stock\Operations\Application\StocktakeService;
use App\Modules\Stock\Operations\Domain\Issuable;
use App\Modules\Stock\Operations\Domain\IssueRecord;
use App\Modules\Stock\Operations\Domain\MinibarTask;
use App\Modules\Stock\Operations\Domain\Rfq;
use App\Modules\Stock\Operations\Domain\Stocktake;
use Illuminate\Http\Request;

/** Inventory operations API (I-B). Mounted under /api/v1/stock-ops. */
class OperationsController
{
    public function __construct(
        private RfqService $rfqs, private BarcodeService $barcodes, private StocktakeService $stocktakes,
        private MinibarService $minibar, private IssueService $issues, private MenuEngineeringService $menu,
    ) {}

    private function allow(Request $r, string $ability): void
    {
        abort_unless($r->user()->can($ability), 403);
    }

    // ---- RFQ --------------------------------------------------------------------------------------

    public function rfqs(Request $r)
    {
        $this->allow($r, 'stockops.view');

        return Rfq::query()->when($r->query('status'), fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->get();
    }

    public function storeRfq(Request $r)
    {
        $this->allow($r, 'stockops.rfq');
        $data = $r->validate([
            'title' => ['required', 'string', 'max:200'], 'store_id' => ['required', 'integer'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string'],
            'supplier_ids' => ['required', 'array', 'min:1'], 'supplier_ids.*' => ['integer'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.notes' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->rfqs->detail($this->rfqs->create($data)), 201);
    }

    public function showRfq(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.view');

        return $this->rfqs->detail($rfq);
    }

    public function sendRfq(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.rfq');

        return $this->rfqs->send($rfq);
    }

    public function quote(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.rfq');
        $data = $r->validate([
            'supplier_id' => ['required', 'integer'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.rfq_line_id' => ['required', 'integer'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'], 'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'lines.*.valid_until' => ['nullable', 'date'], 'lines.*.notes' => ['nullable', 'string', 'max:200'],
        ]);
        $this->rfqs->recordQuote($rfq, $data['supplier_id'], $data['lines']);

        return $this->rfqs->grid($rfq);
    }

    public function grid(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.view');

        return $this->rfqs->grid($rfq);
    }

    public function award(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.rfq');
        $data = $r->validate(['supplier_id' => ['nullable', 'integer'], 'awards' => ['nullable', 'array'], 'awards.*.rfq_line_id' => ['required_with:awards', 'integer'], 'awards.*.supplier_id' => ['required_with:awards', 'integer']]);
        $this->rfqs->award($rfq, $data['awards'] ?? [], $data['supplier_id'] ?? null);

        return $this->rfqs->grid($rfq->fresh());
    }

    public function convertRfq(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.rfq');

        return response()->json(['purchase_orders' => $this->rfqs->convert($rfq)], 201);
    }

    public function cancelRfq(Request $r, Rfq $rfq)
    {
        $this->allow($r, 'stockops.rfq');

        return $this->rfqs->cancel($rfq);
    }

    public function supplierSheet(Request $r, Rfq $rfq, int $supplier)
    {
        $this->allow($r, 'stockops.view');

        return $this->rfqs->supplierSheet($rfq, $supplier);
    }

    // ---- Codes and scanning -----------------------------------------------------------------------

    public function resolve(Request $r)
    {
        $this->allow($r, 'stockops.scan');

        return $this->barcodes->resolve($r->validate(['code' => ['required', 'string', 'max:100']])['code']);
    }

    public function itemCodes(Request $r, StockItem $item)
    {
        $this->allow($r, 'stockops.view');

        return $this->barcodes->itemCode($item);
    }

    public function lotCode(Request $r, StockLot $lot)
    {
        $this->allow($r, 'stockops.view');

        return $this->barcodes->lotCode($lot);
    }

    public function registerBarcode(Request $r)
    {
        $this->allow($r, 'stockops.scan');
        $data = $r->validate(['stock_item_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:64']]);

        return response()->json($this->barcodes->register(StockItem::findOrFail($data['stock_item_id']), $data['code']), 201);
    }

    public function scanReceive(Request $r)
    {
        $this->allow($r, 'stockops.scan');
        abort_unless($r->user()->can('purchasing.manage'), 403);
        $data = $r->validate(['po' => ['required', 'string'], 'code' => ['required', 'string'], 'quantity' => ['required', 'numeric', 'gt:0'], 'delivery_note' => ['nullable', 'string', 'max:60']]);
        $po = str_starts_with($data['po'], 'PO:') ? PurchaseOrder::where('number', substr($data['po'], 3))->firstOrFail() : PurchaseOrder::where('number', $data['po'])->orWhere('id', ctype_digit($data['po']) ? (int) $data['po'] : 0)->firstOrFail();

        return response()->json($this->barcodes->receiveByScan($po, $data['code'], (float) $data['quantity'], $data['delivery_note'] ?? null), 201);
    }

    // ---- Stocktake --------------------------------------------------------------------------------

    public function stocktakes(Request $r)
    {
        $this->allow($r, 'stockops.view');

        return Stocktake::query()->when($r->query('status'), fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->get()->map(fn ($s) => $s->only(['id', 'number', 'store_id', 'status', 'date', 'blind']));
    }

    public function startStocktake(Request $r)
    {
        $this->allow($r, 'stocktake.count');
        $d = $r->validate(['store_id' => ['required', 'integer'], 'blind' => ['nullable', 'boolean'], 'tolerance_pct' => ['nullable', 'numeric', 'min:0'], 'tolerance_qty' => ['nullable', 'numeric', 'min:0'], 'include_all' => ['nullable', 'boolean']]);

        return response()->json($this->stocktakes->start($d['store_id'], $d['blind'] ?? true, $d['tolerance_pct'] ?? null, $d['tolerance_qty'] ?? null, $d['include_all'] ?? false)->only(['id', 'number', 'store_id', 'status']), 201);
    }

    /** The counter's sheet never carries expected quantities. */
    public function sheet(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.count');

        return $this->stocktakes->sheet($stocktake);
    }

    public function recordCounts(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.count');
        $d = $r->validate(['counts' => ['required', 'array', 'min:1'], 'counts.*.stock_item_id' => ['required', 'integer'], 'counts.*.quantity' => ['required', 'numeric', 'min:0']]);

        $st = $this->stocktakes->record($stocktake, $d['counts'], $r->user());

        return in_array($st->status, ['counting', 'recount'], true) ? $this->stocktakes->sheet($st) : $st->only(['id', 'number', 'status']);
    }

    public function scanCount(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.count');
        $d = $r->validate(['code' => ['required', 'string'], 'quantity' => ['nullable', 'numeric', 'gt:0']]);
        $st = $this->stocktakes->scan($stocktake, $d['code'], (float) ($d['quantity'] ?? 1), $r->user(), $this->barcodes);
        $item = $this->barcodes->itemFor($d['code']);

        return ['status' => $st->status, 'item' => $item->only(['id', 'code', 'name', 'unit'])];
    }

    public function closeCount(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.count');
        $st = $this->stocktakes->closeCount($stocktake, (bool) $r->boolean('skip_uncounted'));

        return $st->only(['id', 'number', 'status']);
    }

    public function reviewStocktake(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.review');

        return $this->stocktakes->review($stocktake);
    }

    public function approveStocktake(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.approve');
        $st = $this->stocktakes->approve($stocktake, $r->user(), $r->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);

        return $this->stocktakes->review($st);
    }

    public function cancelStocktake(Request $r, Stocktake $stocktake)
    {
        $this->allow($r, 'stocktake.review');

        return $this->stocktakes->cancel($stocktake)->only(['id', 'number', 'status']);
    }

    // ---- Minibar ----------------------------------------------------------------------------------

    public function minibarPars(Request $r)
    {
        $this->allow($r, 'stockops.view');

        return $this->minibar->pars($r->integer('room_type_id') ?: null);
    }

    public function saveMinibarPar(Request $r)
    {
        $this->allow($r, 'stockops.minibar');
        $d = $r->validate(['room_type_id' => ['required', 'integer'], 'stock_item_id' => ['required', 'integer'], 'par_qty' => ['required', 'numeric', 'gt:0'], 'price' => ['required', 'integer', 'min:0']]);

        return $this->minibar->setPar($d['room_type_id'], $d['stock_item_id'], (float) $d['par_qty'], $d['price']);
    }

    public function minibarTasks(Request $r)
    {
        $this->allow($r, 'stockops.minibar');

        return $this->minibar->tasks($r->query('status'));
    }

    public function completeMinibarTask(Request $r, MinibarTask $task)
    {
        $this->allow($r, 'stockops.minibar');
        $d = $r->validate(['lines' => ['nullable', 'array'], 'lines.*.stock_item_id' => ['required_with:lines', 'integer'], 'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0'], 'from_store_id' => ['nullable', 'integer'], 'minibar_store_id' => ['nullable', 'integer']]);

        return $this->minibar->complete($task, $d['lines'] ?? null, $d['from_store_id'] ?? null, $d['minibar_store_id'] ?? null);
    }

    public function consumeMinibar(Request $r)
    {
        $this->allow($r, 'stockops.minibar');
        $d = $r->validate(['room_id' => ['required', 'integer'], 'items' => ['required', 'array', 'min:1'], 'items.*.stock_item_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'minibar_store_id' => ['nullable', 'integer']]);

        return response()->json($this->minibar->consume($d['room_id'], $d['items'], $d['minibar_store_id'] ?? null), 201);
    }

    // ---- Menu engineering -------------------------------------------------------------------------

    public function menuEngineering(Request $r)
    {
        $this->allow($r, 'stockops.view');
        $d = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'outlet_id' => ['nullable', 'integer']]);

        return $this->menu->report($d['from'] ?? null, $d['to'] ?? null, $d['outlet_id'] ?? null);
    }

    // ---- Uniforms and assets ----------------------------------------------------------------------

    public function issuables(Request $r)
    {
        $this->allow($r, 'stockops.view');

        return Issuable::orderBy('name')->get();
    }

    public function storeIssuable(Request $r)
    {
        $this->allow($r, 'stockops.issues');
        $d = $r->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:200'], 'kind' => ['required', 'in:uniform,asset'], 'stock_item_id' => ['nullable', 'integer'], 'unit_value' => ['nullable', 'integer', 'min:0']]);

        return response()->json($this->issues->createIssuable($d), 201);
    }

    public function issues(Request $r)
    {
        $this->allow($r, 'stockops.view');

        return $this->issues->list($r->integer('employee_id') ?: null, $r->query('status'), $r->boolean('overdue'));
    }

    public function employeeIssues(Request $r, int $employee)
    {
        $this->allow($r, 'stockops.view');

        return $this->issues->list($employee);
    }

    public function issue(Request $r)
    {
        $this->allow($r, 'stockops.issues');
        $d = $r->validate(['employee_id' => ['required', 'integer'], 'issuable_id' => ['required', 'integer'], 'quantity' => ['nullable', 'numeric', 'gt:0'], 'store_id' => ['nullable', 'integer'],
            'serial' => ['nullable', 'string', 'max:60'], 'size' => ['nullable', 'string', 'max:20'], 'due_back_on' => ['nullable', 'date']]);

        return response()->json($this->issues->issue($d), 201);
    }

    public function returnIssue(Request $r, IssueRecord $record)
    {
        $this->allow($r, 'stockops.issues');
        $d = $r->validate(['quantity' => ['nullable', 'numeric', 'gt:0'], 'condition' => ['nullable', 'in:good,worn,damaged,lost']]);

        return $this->issues->return($record, isset($d['quantity']) ? (float) $d['quantity'] : null, $d['condition'] ?? 'good');
    }
}
