<?php

namespace App\Modules\Stock\Control\Console;

use App\Modules\Stock\Control\Application\LotService;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ExpiryAlertsCommand extends Command
{
    protected $signature = 'stock:expiry-alerts {--hotel= : Hotel id}';

    protected $description = 'Raise stock.lot_expiring events for lots that expired or are inside their alert window';

    public function handle(TenantContext $ctx, LotService $lots): int
    {
        $hotels = $ctx->withoutScope(fn () => Hotel::where('status', 'active')->when($this->option('hotel'), fn ($q, $id) => $q->where('id', $id))->get());
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        foreach ($hotels as $hotel) {
            $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], null);
            $this->line("{$hotel->code}: ".$lots->raiseAlerts().' lot alert(s)');
        }
        $ctx->set(...$saved);

        return self::SUCCESS;
    }
}
