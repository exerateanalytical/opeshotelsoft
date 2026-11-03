<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\AssetClass;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\DepreciationRun;
use App\Modules\Accounting\Domain\FixedAsset;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-asset register (SYSCOHADA): acquisition (class 2), monthly
 * depreciation (Dr 68 / Cr 28), disposal through 812 (book value) and 822
 * (proceeds). Methods: straight-line and declining-balance (dégressif with
 * switch to straight-line when that gives more).
 */
class FixedAssets
{
    public function __construct(private Ledger $ledger, private EventBus $bus) {}

    public function saveClass(array $data): AssetClass
    {
        if (($data['asset_account'][0] ?? '') !== '2' || str_starts_with($data['asset_account'], '28')) {
            throw new DomainException('The asset account must be a class-2 account (not 28).', 'invalid-account');
        }
        if (! str_starts_with($data['depreciation_account'], '28')) {
            throw new DomainException('The depreciation account must be a class-28 account.', 'invalid-account');
        }
        if (! str_starts_with($data['expense_account'] ?? C::role('depreciation_expense'), '68')) {
            throw new DomainException('The depreciation expense account must be a class-68 account.', 'invalid-account');
        }

        return AssetClass::updateOrCreate(['code' => $data['code']], $data);
    }

    /**
     * Manual acquisition posts Dr asset / Cr counter account (481 by default).
     * From a supplier invoice nothing is posted: the approved invoice already
     * debited the class-2 account through payables.
     */
    public function acquire(array $data): FixedAsset
    {
        $class = AssetClass::findOrFail($data['asset_class_id']);
        $invoice = null;
        if (! empty($data['supplier_invoice_id'])) {
            $invoice = DB::table('supplier_invoices')->where('hotel_id', $class->hotel_id)->where('id', $data['supplier_invoice_id'])->first();
            if (! $invoice || ! in_array($invoice->status, ['approved', 'partially_paid', 'paid'], true)) {
                throw new DomainException('The supplier invoice must exist and be approved.', 'invoice-not-approved');
            }
            $assetLines = collect(json_decode($invoice->expense_lines, true) ?: [])->filter(fn ($l) => ($l['account'] ?? '') === $class->asset_account);
            if ($assetLines->isEmpty()) {
                throw new DomainException("The invoice has no line on account {$class->asset_account}.", 'invoice-not-asset');
            }
            if (DB::table('fixed_assets')->where('supplier_invoice_id', $invoice->id)->where('asset_account', $class->asset_account)->exists()) {
                throw new DomainException('An asset is already registered from this invoice.', 'duplicate-asset');
            }
            $data['cost'] ??= (int) $assetLines->sum('amount');
            $data['acquired_on'] ??= $invoice->date;
            $data['name'] ??= $assetLines->first()['description'] ?? 'Invoice '.$invoice->reference;
        }
        if (empty($data['cost']) || $data['cost'] <= 0) {
            throw new DomainException('Cost is required.', 'invalid-cost');
        }

        return DB::transaction(function () use ($data, $class, $invoice) {
            $asset = FixedAsset::create([
                'asset_class_id' => $class->id,
                'code' => $data['code'] ?? 'IMMO-'.str_pad((string) (FixedAsset::count() + 1), 5, '0', STR_PAD_LEFT),
                'name' => $data['name'],
                'asset_account' => $class->asset_account, 'depreciation_account' => $class->depreciation_account, 'expense_account' => $class->expense_account,
                'acquired_on' => $data['acquired_on'], 'cost' => (int) $data['cost'], 'salvage_value' => (int) ($data['salvage_value'] ?? 0),
                'useful_life_months' => (int) ($data['useful_life_months'] ?? $class->useful_life_months),
                'method' => $data['method'] ?? $class->method, 'declining_coefficient' => $data['declining_coefficient'] ?? $class->declining_coefficient,
                'supplier_invoice_id' => $invoice?->id,
            ]);
            if (! $invoice) {
                $entry = $this->ledger->post('OD', $asset->acquired_on, "Acquisition immobilisation {$asset->code} {$asset->name}", [
                    [$asset->asset_account, $asset->cost],
                    [$data['counter_account'] ?? C::role('asset_supplier'), -$asset->cost],
                ], ['source_type' => 'fixed_asset', 'source_id' => $asset->id]);
                $asset->update(['acquisition_entry_id' => $entry->id]);
            }
            $this->bus->record('accounting.asset_acquired', $asset->only(['id', 'code', 'cost']), 'fixed_asset', $asset->id);

            return $asset->fresh();
        });
    }

    /** Depreciation for one asset for the month ending $monthEnd (0 if not due). */
    public function monthlyCharge(FixedAsset $a, Carbon $monthEnd): int
    {
        if ($a->status !== 'active' || $a->acquired_on->gt($monthEnd)) {
            return 0;
        }
        $base = (int) $a->cost - (int) $a->salvage_value;
        $remaining = $base - (int) $a->accumulated;
        if ($remaining <= 0) {
            return 0;
        }
        $elapsed = (int) $a->acquired_on->copy()->startOfMonth()->diffInMonths($monthEnd->copy()->startOfMonth()); // 0 in the acquisition month
        $monthsLeft = max(1, $a->useful_life_months - $elapsed);
        $straight = (int) round($remaining / $monthsLeft);
        if ($a->method === 'declining_balance') {
            $annualRate = (float) $a->declining_coefficient / max(1, $a->useful_life_months / 12);
            $declining = (int) round(((int) $a->cost - (int) $a->accumulated) * $annualRate / 12);
            $charge = max($declining, $straight);
        } else {
            $charge = (int) round($base / max(1, $a->useful_life_months));
        }
        if ($monthsLeft === 1) {
            $charge = $remaining;
        }

        return min($charge, $remaining);
    }

    public function run(int $year, int $month): DepreciationRun
    {
        if (DepreciationRun::where(['year' => $year, 'month' => $month])->exists()) {
            throw new DomainException("Depreciation for $month/$year has already been run.", 'already-run');
        }
        $monthEnd = Carbon::create($year, $month)->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($year, $month, $monthEnd) {
            $details = [];
            $lines = [];
            foreach (FixedAsset::where('status', 'active')->whereDate('acquired_on', '<=', $monthEnd)->orderBy('id')->lockForUpdate()->get() as $a) {
                if ($a->last_depreciated_on && $a->last_depreciated_on->gte($monthEnd)) {
                    continue;
                }
                $amount = $this->monthlyCharge($a, $monthEnd);
                if ($amount <= 0) {
                    continue;
                }
                $details[] = ['asset_id' => $a->id, 'code' => $a->code, 'amount' => $amount];
                $lines[] = [$a->expense_account, $amount, ['description' => $a->code]];
                $lines[] = [$a->depreciation_account, -$amount, ['description' => $a->code]];
                $a->update(['accumulated' => $a->accumulated + $amount, 'last_depreciated_on' => $monthEnd->toDateString()]);
            }
            $total = array_sum(array_column($details, 'amount'));
            $entry = $total > 0 ? $this->ledger->post('OD', $monthEnd, sprintf('Dotations aux amortissements %02d/%d', $month, $year), $lines, [
                'source_type' => 'depreciation_run', 'source_id' => "$year-$month", 'source_event' => 'depreciation:'.app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId().":$year-$month",
            ]) : null;
            $run = DepreciationRun::create(['year' => $year, 'month' => $month, 'total' => $total, 'journal_entry_id' => $entry?->id, 'details' => $details]);
            $this->bus->record('accounting.depreciation_run', ['id' => $run->id, 'year' => $year, 'month' => $month, 'total' => $total], 'depreciation_run', $run->id);

            return $run;
        });
    }

    /** Dispose: Dr 28 (accumulated), Dr 812 (book value), Cr 2x (cost); proceeds Dr 485/5x, Cr 822. */
    public function dispose(FixedAsset $a, string $date, int $proceeds, ?string $proceedsAccount = null): FixedAsset
    {
        if ($a->status !== 'active') {
            throw new DomainException('The asset is already disposed.', 'already-disposed');
        }
        $d = Carbon::parse($date);
        if ($d->lt($a->acquired_on)) {
            throw new DomainException('Disposal date is before acquisition.', 'invalid-date');
        }

        return DB::transaction(function () use ($a, $d, $proceeds, $proceedsAccount) {
            $nbv = $a->netBookValue();
            $lines = [
                [$a->depreciation_account, (int) $a->accumulated],
                [C::role('disposal_book_value'), $nbv],
                [$a->asset_account, -(int) $a->cost],
            ];
            if ($proceeds > 0) {
                $lines[] = [$proceedsAccount ?? C::role('disposal_receivable'), $proceeds];
                $lines[] = [C::role('disposal_proceeds'), -$proceeds];
            }
            $entry = $this->ledger->post('OD', $d, "Cession immobilisation {$a->code} {$a->name}", $lines, ['source_type' => 'fixed_asset_disposal', 'source_id' => $a->id]);
            $a->update(['status' => 'disposed', 'disposed_on' => $d->toDateString(), 'disposal_proceeds' => $proceeds, 'disposal_result' => $proceeds - $nbv, 'disposal_entry_id' => $entry->id]);
            $this->bus->record('accounting.asset_disposed', ['id' => $a->id, 'proceeds' => $proceeds, 'result' => $proceeds - $nbv], 'fixed_asset', $a->id);

            return $a->fresh();
        });
    }
}
