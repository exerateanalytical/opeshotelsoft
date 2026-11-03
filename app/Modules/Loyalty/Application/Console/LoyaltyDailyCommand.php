<?php

namespace App\Modules\Loyalty\Application\Console;

use App\Modules\Loyalty\Application\CardService;
use App\Modules\Loyalty\Application\PromotionService;
use App\Modules\Tenancy\Domain\Group;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Daily loyalty housekeeping: expire cards and credit birthday bonuses (per group). */
class LoyaltyDailyCommand extends Command
{
    protected $signature = 'loyalty:daily {--date= : Business date (defaults to today)}';

    protected $description = 'Expire loyalty cards and credit birthday bonuses';

    public function handle(TenantContext $ctx, CardService $cards, PromotionService $promos): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $expired = $cards->expireCards($date);
        $birthdays = 0;
        foreach ($ctx->withoutScope(fn () => Group::all()) as $group) {
            $ctx->set($group->id, null, null, null);
            $birthdays += $promos->awardBirthdays($date);
        }
        $this->info("Cards expired: $expired; birthday bonuses: $birthdays");

        return self::SUCCESS;
    }
}
