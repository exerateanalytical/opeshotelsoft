<?php

namespace App\Modules\ServiceRequests\Application\Console;

use App\Modules\ServiceRequests\Application\EscalationService;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every minute: announce unacknowledged requests as late at half the SLA, alert managers past the SLA. */
class EscalateCommand extends Command
{
    protected $signature = 'servicerequests:escalate {--hotel= : Hotel id}';

    protected $description = 'Escalate service requests that are late or past their SLA';

    public function handle(TenantContext $ctx, EscalationService $escalation): int
    {
        $hotels = $ctx->withoutScope(fn () => Hotel::where('status', 'active')->when($this->option('hotel'), fn ($q, $id) => $q->where('id', $id))->get());
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        foreach ($hotels as $hotel) {
            $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], null);
            $r = $escalation->run();
            if ($r['late'] || $r['overdue']) {
                $this->line("{$hotel->code}: {$r['late']} late, {$r['overdue']} overdue");
            }
        }
        $ctx->set(...$saved);

        return self::SUCCESS;
    }
}
