<?php

namespace App\Modules\Housekeeping;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Domain\HkTask;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;

class HousekeepingModule
{
    public static function subscribe(EventBus $bus): void
    {
        // A departure creates a cleaning task straight away.
        $bus->subscribe('frontoffice.guest_checked_out', 'housekeeping.departure_task', function (StoredEvent $e) {
            if (! $e->payload['room_id']) {
                return;
            }
            HkTask::firstOrCreate(
                ['room_id' => $e->payload['room_id'], 'date' => now()->toDateString(), 'kind' => 'departure'],
                ['priority' => 1, 'checklist' => array_map(fn ($c) => ['item' => $c, 'done' => false], HousekeepingService::DEFAULT_CHECKLIST)],
            );
        });
    }
}
