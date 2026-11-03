<?php

namespace App\Modules\Insights\Application\Console;

use App\Modules\Insights\Application\DigestService;
use App\Modules\Insights\Application\Evaluator;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Runs the leakage rules that have no event (occupied-not-billed, rate below floor,
 * bar stock vs sales) for every active hotel, then sends the owner digests.
 * Default date: the day before each hotel's business date (the day the night audit just closed).
 */
class ScanCommand extends Command
{
    protected $signature = 'insights:scan {--date= : Business date to scan} {--hotel= : Hotel id} {--no-digest : Skip the owner digest}';

    protected $description = 'Scan for revenue leakage and send the owner daily digest';

    public function handle(TenantContext $ctx, Evaluator $evaluator, DigestService $digests): int
    {
        $hotels = $ctx->withoutScope(fn () => Hotel::where('status', 'active')->when($this->option('hotel'), fn ($q, $id) => $q->where('id', $id))->get());
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        foreach ($hotels as $hotel) {
            $date = $this->option('date') ?: Carbon::parse($hotel->business_date ?? now())->subDay()->toDateString();
            $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], null);
            $found = $evaluator->scan($hotel->id, $date);
            $sent = $this->option('no-digest') ? null : $digests->send($date);
            $this->line("{$hotel->code} $date: $found finding(s)".($sent ? ", digest sent ({$sent->messages} message(s))" : ''));
        }
        $ctx->set(...$saved);

        return self::SUCCESS;
    }
}
