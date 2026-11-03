<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\FiscalYear;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Year-end close: requires all twelve months closed; posts the closing entry
 * that zeroes income and expense accounts (classes 6, 7, 8) into 131 (profit)
 * or 139 (loss), locks the year, and stores the balance-sheet balances
 * (classes 1-5) as next year's opening balances (carry-forward snapshot; the
 * ledger itself is cumulative, so no "à nouveaux" entry is needed to keep
 * balances correct).
 */
class YearEndClose
{
    public function __construct(private Ledger $ledger, private EventBus $bus, private TenantContext $ctx) {}

    public function status(int $year): array
    {
        $hotelId = $this->ctx->requireHotelId();
        $closed = DB::table('fiscal_periods')->where(['hotel_id' => $hotelId, 'year' => $year, 'status' => 'closed'])->pluck('month')->map(fn ($m) => (int) $m)->all();
        $open = array_values(array_diff(range(1, 12), $closed));
        $fy = FiscalYear::where('year', $year)->first();
        $result = $this->result($year);

        return ['year' => $year, 'open_months' => $open, 'locked' => (bool) $fy, 'result' => $fy?->result ?? $result, 'fiscal_year' => $fy, 'can_close' => ! $fy && ! $open];
    }

    public function close(int $year): FiscalYear
    {
        $hotelId = $this->ctx->requireHotelId();
        $status = $this->status($year);
        if ($status['locked']) {
            throw new DomainException("The fiscal year $year is already closed.", 'year-locked');
        }
        if ($status['open_months']) {
            throw new DomainException('All months must be closed first. Open: '.implode(', ', $status['open_months']).'.', 'months-open');
        }
        $from = "$year-01-01";
        $to = "$year-12-31";

        return DB::transaction(function () use ($hotelId, $year, $from, $to) {
            $lines = [];
            $result = 0;
            foreach ($this->ledger->balances($from, $to) as $code => $b) {
                $code = (string) $code;
                if (! in_array($code[0], ['6', '7', '8'], true) || $b['balance'] === 0) {
                    continue;
                }
                $lines[] = [$code, -$b['balance']];
                $result -= $b['balance'];
            }
            $entry = null;
            if ($lines) {
                $lines[] = [$result >= 0 ? C::role('result_profit') : C::role('result_loss'), -$result];
                $entry = $this->ledger->post('OD', Carbon::parse($to), "Clôture de l'exercice $year - détermination du résultat", $lines, [
                    'closing' => true, 'source_type' => 'year_end_close', 'source_id' => $year, 'source_event' => "year-end:$hotelId:$year",
                ]);
            }
            $fy = FiscalYear::create(['year' => $year, 'status' => 'locked', 'result' => $result, 'closing_entry_id' => $entry?->id, 'closed_at' => now(), 'closed_by' => $this->ctx->actor()?->id]);

            DB::table('opening_balances')->where(['hotel_id' => $hotelId, 'year' => $year + 1])->delete();
            $rows = [];
            foreach ($this->ledger->balances(null, $to) as $code => $b) {
                $code = (string) $code;
                if ((int) $code[0] >= 1 && (int) $code[0] <= 5 && $b['balance'] !== 0) {
                    $rows[] = ['hotel_id' => $hotelId, 'year' => $year + 1, 'account_code' => $code, 'debit' => max(0, $b['balance']), 'credit' => max(0, -$b['balance'])];
                }
            }
            if ($rows) {
                DB::table('opening_balances')->insert($rows);
            }
            $this->bus->record('accounting.year_closed', ['year' => $year, 'result' => $result], 'hotel', $hotelId);

            return $fy;
        });
    }

    public function openingBalances(int $year): array
    {
        return DB::table('opening_balances')->where(['hotel_id' => $this->ctx->requireHotelId(), 'year' => $year])->orderBy('account_code')->get(['account_code', 'debit', 'credit'])->all();
    }

    private function result(int $year): int
    {
        $r = 0;
        foreach ($this->ledger->balances("$year-01-01", "$year-12-31") as $code => $b) {
            if (in_array(((string) $code)[0], ['6', '7', '8'], true)) {
                $r -= $b['balance'];
            }
        }

        return $r;
    }
}
