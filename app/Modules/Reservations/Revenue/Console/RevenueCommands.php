<?php

namespace App\Modules\Reservations\Revenue\Console;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Application\CorporateService;
use App\Modules\Reservations\Revenue\Application\UpsellService;
use App\Platform\Console\RunsPerHotel;
use Illuminate\Console\Command;

class RevenueCommands
{
    /** @return array<class-string> */
    public static function all(): array
    {
        return [UpsellPreArrivalCommand::class, ReleaseAllotmentsCommand::class];
    }
}

/** Offer room upgrades to confirmed arrivals N days ahead, for every active hotel. */
class UpsellPreArrivalCommand extends Command
{
    use RunsPerHotel;

    protected $signature = 'revenue:upsell-pre-arrival {--hotel= : Hotel id} {--days=3 : Days before arrival} {--dry-run : Count candidate arrivals only}';

    protected $description = 'Send pre-arrival upsell offers for upcoming confirmed arrivals';

    public function handle(UpsellService $upsell): int
    {
        $days = (int) $this->option('days');

        return $this->eachActiveHotel(function ($h) use ($upsell, $days) {
            if ($this->option('dry-run')) {
                $n = Reservation::where('status', 'confirmed')->whereDate('arrival', now()->addDays($days)->toDateString())->count();

                return "$n arrival(s) would be considered";
            }

            return $upsell->offerPreArrival($days).' reservation(s) offered';
        }, false);
    }
}

/** Give back unsold corporate allotment rooms whose release date has passed. */
class ReleaseAllotmentsCommand extends Command
{
    use RunsPerHotel;

    protected $signature = 'revenue:release-allotments {--hotel= : Hotel id} {--dry-run : Compute then roll back}';

    protected $description = 'Release unsold corporate allotment rooms past their release date';

    public function handle(CorporateService $corporate): int
    {
        return $this->eachActiveHotel(fn ($h) => $corporate->releaseDue().' room(s) released');
    }
}
