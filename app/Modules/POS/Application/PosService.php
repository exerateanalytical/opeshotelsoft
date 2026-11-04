<?php

namespace App\Modules\POS\Application;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Application\TaxCalculator;
use App\Modules\FrontOffice\Domain\CashierShift;
use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\CheckLine;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\ChartOfAccounts;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\POS\Domain\PosTable;
use App\Modules\POS\Domain\PriceList;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Outlet checks: order, fire to kitchen, split, discount, void, settle or charge to room. */
class PosService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx, private FolioService $folios) {}

    public function open(int $outletId, array $data = []): Check
    {
        if (! empty($data['client_uuid']) && ($existing = Check::where('client_uuid', $data['client_uuid'])->first())) {
            return $existing; // offline replay
        }
        $outlet = Outlet::where('active', true)->findOrFail($outletId);
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());

        // One transaction: the sequence row lock is held until the check exists, so no number is lost or reused.
        return DB::transaction(function () use ($outlet, $hotel, $data) {
            $check = Check::create([
                'outlet_id' => $outlet->id,
                'number' => NumberSequence::next($hotel->id, 'pos-check', $outlet->code.'-'),
                'client_uuid' => $data['client_uuid'] ?? null,
                'table_number' => $data['table_number'] ?? null,
                'pos_table_id' => $data['pos_table_id'] ?? null,
                'covers' => $data['covers'] ?? 1,
                'business_date' => $hotel->business_date,
                'opened_by' => $this->ctx->actor()?->id,
            ])->refresh();
            $this->bus->record('pos.check_opened', $check->only(['id', 'number', 'outlet_id', 'table_number']), 'pos_check', $check->id);

            return $check;
        });
    }

    public function addItem(Check $check, int $menuItemId, float $qty = 1, array $modifiers = [], ?string $notes = null): CheckLine
    {
        $check->assertOpen();
        $item = MenuItem::where('active', true)->findOrFail($menuItemId);
        if ($item->outlet_id && $item->outlet_id !== $check->outlet_id) {
            throw new DomainException("{$item->name} is not sold in this outlet.");
        }
        $extra = (int) collect($modifiers)->sum('price');
        // Price level (happy hour...) active now for this outlet, applied automatically.
        $list = PriceList::activeFor($check->outlet_id, now());
        $levelPrice = $list?->priceFor($item);
        $base = $levelPrice ?? $item->price;
        $line = $check->lines()->create([
            'pos_menu_item_id' => $item->id, 'name' => $item->name, 'quantity' => $qty,
            'unit_price' => $base + $extra, 'amount' => (int) round(($base + $extra) * $qty),
            'pos_price_list_id' => $levelPrice !== null ? $list->id : null, 'list_price' => $levelPrice !== null ? $item->price : null,
            'modifiers' => $modifiers, 'notes' => $notes, 'charge_code' => $item->charge_code,
        ]);
        $check->recalculate();

        return $line;
    }

    /** Send un-fired lines to kitchen/bar printers or KDS. */
    public function fire(Check $check): array
    {
        $check->assertOpen();
        $lines = $check->lines()->whereNull('fired_at')->where('voided', false)->get();
        if ($lines->isEmpty()) {
            return [];
        }
        $check->lines()->whereIn('id', $lines->pluck('id'))->update(['fired_at' => now()]);
        $byStation = $lines->groupBy(fn ($l) => MenuItem::find($l->pos_menu_item_id)?->kitchen_station ?? 'kitchen');
        foreach ($byStation as $station => $items) {
            $this->bus->record('pos.order_fired', [
                'check_id' => $check->id, 'check_number' => $check->number, 'outlet_id' => $check->outlet_id,
                'table' => $check->table_number, 'station' => $station,
                'items' => $items->map(fn ($l) => ['name' => $l->name, 'qty' => $l->quantity, 'modifiers' => $l->modifiers, 'notes' => $l->notes])->values(),
            ], 'pos_check', $check->id);
        }

        return $byStation->keys()->all();
    }

    /** Change quantity/notes of a line not yet sent to the kitchen. */
    public function updateLine(Check $check, int $lineId, array $data): CheckLine
    {
        $check->assertOpen();
        $line = $this->unfiredLine($check, $lineId);
        $changes = array_intersect_key($data, array_flip(['quantity', 'notes']));
        if (array_key_exists('quantity', $changes)) {
            $changes['amount'] = (int) round($line->unit_price * $changes['quantity']);
        }
        $line->update($changes);
        $check->recalculate();

        return $line->fresh();
    }

    /** Remove a line not yet sent to the kitchen (no supervisor needed). */
    public function removeLine(Check $check, int $lineId): void
    {
        $check->assertOpen();
        // Kept as a voided line so the check's history stays complete; no supervisor needed before firing.
        $line = $this->unfiredLine($check, $lineId);
        $line->update(['voided' => true, 'void_reason' => 'Removed before sending to kitchen', 'voided_by' => $this->ctx->actor()?->id]);
        $check->recalculate();
        $this->bus->record('pos.line_removed', ['check_id' => $check->id, 'line_id' => $lineId, 'amount' => $line->amount], 'pos_check', $check->id);
    }

    private function unfiredLine(Check $check, int $lineId): CheckLine
    {
        $line = $check->lines()->findOrFail($lineId);
        if ($line->fired_at || $line->voided) {
            throw new DomainException('This line was already sent to the kitchen; a supervisor must void it.', 'line-fired');
        }

        return $line;
    }

    public function voidLine(Check $check, int $lineId, string $reason): CheckLine
    {
        $check->assertOpen();
        $line = $check->lines()->findOrFail($lineId);
        if ($line->voided) {
            throw new DomainException('This line is already voided.', 'line-voided');
        }
        $line->update(['voided' => true, 'void_reason' => $reason, 'voided_by' => $this->ctx->actor()?->id]);
        $check->recalculate();
        $this->bus->record('pos.line_voided', ['check_id' => $check->id, 'line_id' => $line->id, 'amount' => $line->amount, 'reason' => $reason, 'fired' => (bool) $line->fired_at], 'pos_check', $check->id);

        return $line;
    }

    public function discount(Check $check, int $amount, string $reason): Check
    {
        $check->assertOpen();
        if ($amount > $check->subtotal - $check->comp_total) {
            throw new DomainException('The discount cannot exceed the check subtotal.');
        }
        $check->update(['discount' => $amount, 'discount_reason' => $reason]);
        $check->recalculate();

        return $check->fresh();
    }

    /** Move some lines to a new check (split bill). */
    public function split(Check $check, array $lineIds): Check
    {
        $check->assertOpen();

        return DB::transaction(function () use ($check, $lineIds) {
            $new = $this->open($check->outlet_id, ['table_number' => $check->table_number, 'pos_table_id' => $check->pos_table_id, 'covers' => 1]);
            $moved = $check->lines()->whereIn('id', $lineIds)->update(['pos_check_id' => $new->id]);
            if ($moved === 0) {
                throw new DomainException('No lines selected to split.');
            }
            $check->recalculate();
            $fresh = $check->fresh();
            if ($fresh->discount > $fresh->subtotal - $fresh->comp_total) {
                $check->update(['discount' => 0, 'discount_reason' => null]); // the discount no longer fits what is left: re-apply it explicitly
                $check->recalculate();
            }
            $new->recalculate();

            return $new->load('lines');
        });
    }

    /**
     * Settle with payments: [['method' => 'CASH', 'amount' => 10000, 'tendered' => 20000, 'reference' => null], ...]
     */
    public function settle(Check $check, array $payments): Check
    {
        return DB::transaction(function () use ($check, $payments) {
            $check = Check::lockForUpdate()->findOrFail($check->id);
            $check->assertOpen();
            $this->assertHasLines($check);
            $this->assertNoPendingComps($check);
            $paid = (int) collect($payments)->sum('amount');
            if ($paid !== $check->total) {
                throw new DomainException("Payments ($paid) must equal the check total ({$check->total}).", 'payment-mismatch');
            }
            $shift = CashierShift::where('user_id', $this->ctx->actor()?->id)->where('status', 'open')->first();
            $settlementPayments = [];
            foreach ($payments as $p) {
                $method = PaymentMethod::where('code', $p['method'])->where('active', true)->first()
                    ?? throw new DomainException("Unknown payment method {$p['method']}.");
                if (in_array($method->kind, ['cash', 'card', 'mobile_money'], true) && ! $shift) {
                    throw new DomainException('Open a cashier shift before taking payments.', 'shift-required');
                }
                if ($method->kind === 'loyalty_points') { // points as tender: the Loyalty module validates and deducts
                    app(\App\Modules\Loyalty\Application\PosLoyalty::class)->redeemTender($check, (int) $p['amount'], $p['reference'] ?? null);
                }
                $tendered = $p['tendered'] ?? $p['amount'];
                if ($tendered < $p['amount']) {
                    throw new DomainException("Amount tendered ({$tendered}) for {$method->code} is less than the amount paid ({$p['amount']}). Correct the tendered amount or leave it empty.", 'tendered-short');
                }
                $check->payments()->create([
                    'hotel_id' => $check->hotel_id, 'method_code' => $method->code, 'amount' => $p['amount'],
                    'tendered' => $tendered, 'change_given' => max(0, $tendered - $p['amount']),
                    'reference' => $p['reference'] ?? null, 'cashier_shift_id' => $shift?->id,
                ]);
                $settlementPayments[] = ['method' => $method->code, 'account' => $method->account, 'amount' => (int) $p['amount']];
            }

            $this->finish($check, 'payment', $settlementPayments);

            return $check->fresh(['lines', 'payments']);
        });
    }

    /** Post the check to an in-house guest's folio. */
    public function chargeToRoom(Check $check, int $reservationId): Check
    {
        return DB::transaction(function () use ($check, $reservationId) {
            $check = Check::lockForUpdate()->findOrFail($check->id);
            $check->assertOpen();
            $this->assertHasLines($check);
            $this->assertNoPendingComps($check);
            $r = Reservation::findOrFail($reservationId);
            if ($r->status !== 'checked_in') {
                throw new DomainException('Only in-house guests can charge to their room.', 'not-in-house');
            }
            $folio = $this->folios->guestFolioFor($r);
            foreach ($this->revenueByCode($check) as $code => $gross) {
                $this->folios->charge($folio, $code, $gross, [
                    'description' => "{$check->outlet->name} #{$check->number}", 'source_type' => 'pos_check', 'source_id' => $check->id,
                ]);
            }
            $check->update(['reservation_id' => $r->id, 'guest_id' => $r->guest_id]);
            $this->finish($check, 'room_charge', []);

            return $check->fresh(['lines']);
        });
    }

    public function voidCheck(Check $check, string $reason): Check
    {
        $check->assertOpen();
        $check->update(['status' => 'void', 'void_reason' => $reason]);
        $this->bus->record('pos.check_voided', ['id' => $check->id, 'number' => $check->number, 'total' => $check->total, 'reason' => $reason], 'pos_check', $check->id);

        return $check;
    }

    private function finish(Check $check, string $settlement, array $payments): void
    {
        if ($settlement === 'payment' && $check->total === 0 && $check->comp_total > 0) {
            $settlement = 'comp'; // fully complimentary check
        }
        SettlementGuards::run($check); // e.g. stock on hand in the outlet's store; throws before anything is closed

        $check->update(['status' => 'settled', 'settlement' => $settlement, 'settled_at' => now(), 'settled_by' => $this->ctx->actor()?->id]);
        [$revenue, $taxes] = $this->revenueAndTax($this->revenueByCode($check));

        $this->bus->record('pos.check_settled', [
            'id' => $check->id, 'number' => $check->number, 'outlet_id' => $check->outlet_id, 'outlet' => $check->outlet->code,
            'business_date' => $check->business_date->toDateString(), 'settlement' => $settlement, 'total' => $check->total,
            'discount' => $check->discount, 'reservation_id' => $check->reservation_id, 'guest_id' => $check->guest_id,
            'payments' => $payments, 'revenue' => $revenue, 'taxes' => $taxes,
            'items' => $check->lines()->where('voided', false)->get(['pos_menu_item_id', 'quantity'])->toArray(),
        ], 'pos_check', $check->id);

        // Comped items: revenue (and its tax) offset by the comp expense account, through the same posting rule.
        if ($check->comp_total > 0) {
            [$compRevenue, $compTaxes] = $this->revenueAndTax($this->compByCode($check));
            $this->bus->record('pos.check_settled', [
                'id' => $check->id, 'number' => $check->number.'-COMP', 'outlet_id' => $check->outlet_id, 'outlet' => $check->outlet->code,
                'business_date' => $check->business_date->toDateString(), 'settlement' => 'comp', 'total' => $check->comp_total,
                'discount' => 0, 'reservation_id' => $check->reservation_id, 'guest_id' => $check->guest_id,
                'payments' => [['method' => 'COMP', 'account' => $this->compAccount(), 'amount' => $check->comp_total]],
                'revenue' => $compRevenue, 'taxes' => $compTaxes,
                'items' => [], // stock is consumed by the main settlement event
            ], 'pos_check', $check->id);
        }
    }

    /** @return array{0: array, 1: array} revenue and tax lines for gross amounts per charge code */
    private function revenueAndTax(array $byCode): array
    {
        $revenue = [];
        $taxes = [];
        foreach ($byCode as $code => $gross) {
            $cc = ChargeCode::where('code', $code)->firstOrFail();
            [$taxTotal, $breakdown] = TaxCalculator::compute($cc, $gross);
            $revenue[] = ['code' => $code, 'department' => $cc->department, 'account' => $cc->revenue_account, 'gross' => $gross, 'net' => $gross - $taxTotal];
            foreach ($breakdown as $b) {
                $taxes[$b['account']] = ['account' => $b['account'], 'amount' => ($taxes[$b['account']]['amount'] ?? 0) + $b['amount']];
            }
        }

        return [$revenue, array_values($taxes)];
    }

    /** @return array<string,int> gross of approved comped lines per charge code */
    private function compByCode(Check $check): array
    {
        return $check->lines()->where('voided', false)->where('comp_status', 'approved')->get()
            ->groupBy('charge_code')->map(fn ($g) => (int) $g->sum('amount'))->filter()->all();
    }

    /**
     * Comp expense account. ChartOfAccounts has no comp role yet, so the POS uses
     * 658100 (charges diverses - offerts) and creates it for the hotel if missing.
     */
    private function compAccount(): string
    {
        $code = ChartOfAccounts::ROLES['comp_expense'] ?? '658100';
        Account::firstOrCreate(['code' => $code], ['name' => 'Charges diverses - offerts (comps)', 'class' => 6, 'type' => 'expense']);

        return $code;
    }

    /** @return array<string,int> gross per charge code, discount applied pro-rata */
    private function revenueByCode(Check $check): array
    {
        $by = $check->lines()->where('voided', false)->where(fn ($q) => $q->whereNull('comp_status')->orWhere('comp_status', '!=', 'approved'))->get()->groupBy('charge_code')->map(fn ($g) => (int) $g->sum('amount'))->all();
        $subtotal = array_sum($by);
        if ($check->discount > 0 && $subtotal > 0) {
            $remaining = $check->discount;
            $codes = array_keys($by);
            foreach ($codes as $i => $code) {
                $share = $i === count($codes) - 1 ? $remaining : (int) round($check->discount * $by[$code] / $subtotal);
                $by[$code] -= $share;
                $remaining -= $share;
            }
        }

        return array_filter($by, fn ($v) => $v > 0);
    }

    private function assertNoPendingComps(Check $check): void
    {
        if ($check->lines()->where('voided', false)->where('comp_status', 'pending')->exists()) {
            throw new DomainException('A comp on this check is waiting for supervisor approval.', 'comp-pending');
        }
    }

    // ---- Table plan ---------------------------------------------------------

    /** Open the table's current check, or start one. */
    public function openAtTable(PosTable $table, int $covers = 1): Check
    {
        $existing = Check::where('pos_table_id', $table->id)->where('status', 'open')->orderBy('id')->first();

        return $existing ?? $this->open($table->outlet_id, ['pos_table_id' => $table->id, 'table_number' => $table->name, 'covers' => $covers]);
    }

    public function transfer(Check $check, PosTable $to): Check
    {
        $check->assertOpen();
        if (! $to->active || $to->outlet_id !== $check->outlet_id) {
            throw new DomainException('Checks can only move to an active table of the same outlet.', 'table-invalid');
        }
        if (Check::where('pos_table_id', $to->id)->where('status', 'open')->where('id', '!=', $check->id)->exists()) {
            throw new DomainException("Table {$to->name} already has an open check.", 'table-occupied');
        }
        $from = $check->table_number;
        $check->update(['pos_table_id' => $to->id, 'table_number' => $to->name]);
        $this->bus->record('pos.check_transferred', ['id' => $check->id, 'from' => $from, 'to' => $to->name], 'pos_check', $check->id);

        return $check->fresh('lines');
    }

    // ---- Comps --------------------------------------------------------------

    /** Ask to comp one line (or every line when $lineId is null). A supervisor (pos.comp) approves at once. */
    public function requestComp(Check $check, ?int $lineId, string $reason, bool $supervisor): Check
    {
        $check->assertOpen();
        $lines = $check->lines()->where('voided', false)->whereNull('comp_status')
            ->when($lineId, fn ($q) => $q->where('id', $lineId))->get();
        if ($lines->isEmpty()) {
            throw new DomainException('Nothing to comp on this check.', 'comp-nothing');
        }
        $actor = $this->ctx->actor()?->id;
        foreach ($lines as $l) {
            $l->update(['comp_status' => 'pending', 'comp_reason' => $reason, 'comp_requested_by' => $actor]);
        }
        $this->bus->record('pos.comp_requested', ['check_id' => $check->id, 'line_ids' => $lines->pluck('id')->all(), 'reason' => $reason], 'pos_check', $check->id);

        return $supervisor ? $this->decideComps($check, true) : $check->fresh('lines');
    }

    /** Supervisor decision on every pending comp of the check. */
    public function decideComps(Check $check, bool $approve): Check
    {
        $check->assertOpen();
        $pending = $check->lines()->where('comp_status', 'pending')->get();
        if ($pending->isEmpty()) {
            throw new DomainException('No comp is waiting for approval.', 'comp-none-pending');
        }
        foreach ($pending as $l) {
            $l->update($approve
                ? ['comp_status' => 'approved', 'comp_approved_by' => $this->ctx->actor()?->id, 'comped_at' => now()]
                : ['comp_status' => null, 'comp_reason' => null, 'comp_requested_by' => null]);
        }
        $check->recalculate();
        $fresh = $check->fresh();
        if ($fresh->discount > $fresh->subtotal - $fresh->comp_total) {
            $check->update(['discount' => 0, 'discount_reason' => null]); // a discount cannot exceed what is still charged
            $check->recalculate();
        }
        $this->bus->record($approve ? 'pos.comp_approved' : 'pos.comp_rejected', [
            'check_id' => $check->id, 'line_ids' => $pending->pluck('id')->all(), 'amount' => (int) $pending->sum('amount'),
        ], 'pos_check', $check->id);

        return $check->fresh('lines');
    }

    private function assertHasLines(Check $check): void
    {
        if (! $check->lines()->where('voided', false)->exists()) {
            throw new DomainException('The check has no items.');
        }
    }
}
