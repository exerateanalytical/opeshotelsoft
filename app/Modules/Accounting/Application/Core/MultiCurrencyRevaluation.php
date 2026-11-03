<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Accounting\Application\FxRevaluation;
use App\Modules\Accounting\Domain\FxRevaluation as Run;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Revaluation of every foreign-currency monetary position: receivables/payables (class 4) AND foreign-currency
 * treasury (accounts 52 and 57). Used by the monthly job `accounting:run-scheduled`, which revalues at each
 * month end using the latest stored exchange rate; a missing rate skips the currency run and is audited.
 * Gain -> 776, loss -> 676 (SYSCOHADA); to be validated by the chartered accountant (unrealised vs realised split).
 */
class MultiCurrencyRevaluation extends FxRevaluation
{
    public function positions(string $date): array
    {
        return DB::table('journal_lines')
            ->where('hotel_id', app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId())
            ->whereNotNull('currency')->where('currency', '<>', self::BASE)
            ->where(fn ($q) => $q->where('account_code', 'like', '4%')->orWhere('account_code', 'like', '52%')->orWhere('account_code', 'like', '57%'))
            ->where('date', '<=', $date)
            ->selectRaw('account_code, partner_type, partner_id, currency,
                sum(case when debit > 0 then coalesce(fx_amount, 0) else -coalesce(fx_amount, 0) end) as fx_balance,
                sum(debit) - sum(credit) as book_balance')
            ->groupBy('account_code', 'partner_type', 'partner_id', 'currency')
            ->orderBy('account_code')->get()
            ->map(fn ($r) => ['account' => $r->account_code, 'partner_type' => $r->partner_type, 'partner_id' => $r->partner_id, 'currency' => $r->currency,
                'fx_balance' => round((float) $r->fx_balance, 2), 'book_balance' => (int) $r->book_balance])
            ->filter(fn ($p) => $p['fx_balance'] != 0.0 || $p['book_balance'] !== 0)
            ->values()->all();
    }

    /**
     * Month-end run for the current hotel; idempotent (a date already revalued is skipped).
     *
     * @return array{status: string, run_id?: int, reason?: string}
     */
    public function monthEnd(string|Carbon $date): array
    {
        $date = Carbon::parse($date)->endOfMonth()->toDateString();
        if (Run::where('date', $date)->exists()) {
            return ['status' => 'already-run'];
        }
        if (! $this->positions($date)) {
            return ['status' => 'nothing-to-revalue'];
        }
        try {
            $run = $this->run($date);
        } catch (DomainException $e) {
            app(AuditTrail::class)->record('fx_revaluation_skipped', 'fx_revaluation', $date, ['reason' => $e->getMessage()]);

            return ['status' => 'skipped', 'reason' => $e->getMessage()];
        }
        app(AuditTrail::class)->record('fx_revaluation_run', 'fx_revaluation', $date, ['run' => $run->id, 'gain' => $run->gain, 'loss' => $run->loss]);

        return ['status' => 'run', 'run_id' => $run->id];
    }
}
