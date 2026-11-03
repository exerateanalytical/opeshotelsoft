<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily accounting automation for every active hotel:
 *  1. due accrual / prepayment items, 2. due recurring journals,
 *  3. month-end foreign-currency revaluation of the previous month (run in the first 5 days of a month).
 */
class RunScheduledCommand extends Command
{
    protected $signature = 'accounting:run-scheduled {--date= : Run as of this date (default today)} {--hotel= : Hotel id} {--no-fx : Skip revaluation}';

    protected $description = 'Post due accruals/prepayments and recurring journals; revalue foreign currency at month end';

    public function handle(TenantContext $ctx, Deferrals $deferrals, RecurringJournals $recurring, MultiCurrencyRevaluation $fx): int
    {
        $asOf = Carbon::parse($this->option('date') ?: now());
        $hotels = $ctx->withoutScope(fn () => Hotel::where('status', 'active')->when($this->option('hotel'), fn ($q, $id) => $q->where('id', $id))->get());
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        foreach ($hotels as $hotel) {
            $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], null);
            $d = $deferrals->run($asOf);
            $r = $recurring->run($asOf);
            $f = ['status' => 'not-due'];
            if (! $this->option('no-fx') && $asOf->day <= 5) {
                $f = $fx->monthEnd($asOf->copy()->subMonthNoOverflow());
            }
            $this->line("{$hotel->code}: deferrals {$d['posted']} posted/{$d['failed']} failed, recurring {$r['posted']}/{$r['failed']}, fx {$f['status']}");
        }
        $ctx->set(...$saved);

        return self::SUCCESS;
    }
}
