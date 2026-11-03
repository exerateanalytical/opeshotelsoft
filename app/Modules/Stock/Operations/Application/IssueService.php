<?php

namespace App\Modules\Stock\Operations\Application;

use App\Modules\People\Application\EmployeeDirectory;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Operations\Domain\Issuable;
use App\Modules\Stock\Operations\Domain\IssueRecord;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Uniform and asset issue tracking per employee. Employees are read through the HR directory query
 * (ids only are stored). Items linked to a stock item are deducted on issue and added back on a good return.
 */
class IssueService
{
    public function __construct(private StockService $stock, private EmployeeDirectory $employees, private EventBus $bus, private TenantContext $ctx) {}

    public function createIssuable(array $data): Issuable
    {
        if (! empty($data['stock_item_id'])) {
            StockItem::findOrFail($data['stock_item_id']);
        }

        return Issuable::create($data);
    }

    public function issue(array $data): IssueRecord
    {
        $employee = $this->employees->get((int) $data['employee_id']);
        $issuable = Issuable::where('active', true)->findOrFail($data['issuable_id']);
        $qty = (float) ($data['quantity'] ?? 1);
        if ($qty <= 0) {
            throw new DomainException('Quantity must be positive.', 'issue-invalid');
        }
        if ($issuable->kind === 'asset' && ! empty($data['serial']) && IssueRecord::where('issuable_id', $issuable->id)->where('serial', $data['serial'])->where('status', 'issued')->exists()) {
            throw new DomainException('This serial number is already issued.', 'issue-serial-taken');
        }

        return DB::transaction(function () use ($data, $employee, $issuable, $qty) {
            $store = null;
            if ($issuable->stock_item_id) {
                $store = Store::findOrFail($data['store_id'] ?? throw new DomainException('Choose the store the item comes from.', 'issue-store'));
                $this->stock->consume('issue', StockItem::findOrFail($issuable->stock_item_id), $store, $qty, ['reference' => "Dotation {$employee->full_name}", 'source_type' => 'issue_record', 'department' => $employee->department?->name]);
            }
            $r = IssueRecord::create([
                'issuable_id' => $issuable->id, 'employee_id' => $employee->id, 'store_id' => $store?->id, 'quantity' => $qty, 'serial' => $data['serial'] ?? null, 'size' => $data['size'] ?? null,
                'issued_on' => Hotel::findOrFail($this->ctx->requireHotelId())->business_date, 'due_back_on' => $data['due_back_on'] ?? null, 'issued_by' => $this->ctx->actor()?->id,
            ]);
            $this->bus->record('stock.item_issued', ['id' => $r->id, 'employee_id' => $employee->id, 'issuable' => $issuable->code, 'quantity' => $qty], 'issue_record', $r->id);

            return $r;
        });
    }

    /** Return all or part; condition good/worn goes back to stock, damaged/lost does not. */
    public function return(IssueRecord $r, ?float $quantity = null, string $condition = 'good'): IssueRecord
    {
        if ($r->status !== 'issued') {
            throw new DomainException('This issue is already closed.', 'invalid-state');
        }
        $open = $r->quantity - $r->returned_quantity;
        $qty = $quantity ?? $open;
        if ($qty <= 0 || $qty > $open + 0.0001) {
            throw new DomainException("Cannot return more than is out ({$open}).", 'issue-over-return');
        }

        return DB::transaction(function () use ($r, $qty, $condition, $open) {
            $issuable = Issuable::findOrFail($r->issuable_id);
            if ($condition === 'lost') {
                $r->update(['returned_quantity' => $r->returned_quantity + $qty, 'status' => 'lost', 'return_condition' => 'lost', 'returned_on' => $this->today()]);
            } else {
                if ($issuable->stock_item_id && in_array($condition, ['good', 'worn'], true)) {
                    $this->stock->adjust(StockItem::findOrFail($issuable->stock_item_id), Store::findOrFail($r->store_id), $qty, 'Retour dotation', ['source_type' => 'issue_record', 'source_id' => $r->id]);
                }
                $done = $r->returned_quantity + $qty >= $r->quantity - 0.0001;
                $r->update(['returned_quantity' => $r->returned_quantity + $qty, 'return_condition' => $condition, 'status' => $done ? 'returned' : 'issued', 'returned_on' => $done ? $this->today() : null]);
            }
            $this->bus->record('stock.item_returned', ['id' => $r->id, 'employee_id' => $r->employee_id, 'condition' => $condition, 'quantity' => $qty], 'issue_record', $r->id);

            return $r->fresh();
        });
    }

    /** Issues with names, filtered. Overdue = still out past the due-back date. */
    public function list(?int $employeeId = null, ?string $status = null, bool $overdue = false): array
    {
        $today = $this->today();
        $rows = IssueRecord::query()->when($employeeId, fn ($q, $v) => $q->where('employee_id', $v))->when($status, fn ($q, $v) => $q->where('status', $v))
            ->when($overdue, fn ($q) => $q->where('status', 'issued')->whereNotNull('due_back_on')->where('due_back_on', '<', $today))->orderByDesc('id')->get();
        $issuables = Issuable::whereIn('id', $rows->pluck('issuable_id'))->get()->keyBy('id');
        $people = $this->employees->summaries($rows->pluck('employee_id')->all());

        return $rows->map(fn ($r) => $r->toArray() + [
            'issuable_name' => $issuables[$r->issuable_id]->name ?? null, 'kind' => $issuables[$r->issuable_id]->kind ?? null, 'employee_name' => $people[$r->employee_id]['name'] ?? null,
            'overdue' => $r->status === 'issued' && $r->due_back_on && $r->due_back_on->toDateString() < $today,
        ])->all();
    }

    private function today(): string
    {
        return Hotel::findOrFail($this->ctx->requireHotelId())->business_date->toDateString();
    }
}
