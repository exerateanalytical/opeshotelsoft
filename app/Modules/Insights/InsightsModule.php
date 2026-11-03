<?php

namespace App\Modules\Insights;

use App\Modules\Insights\Application\Console\ScanCommand;
use App\Modules\Insights\Application\Evaluator;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Console\Application as Artisan;

/** Owner insights: leakage alerts from other modules' events + scheduled scan, owner digest. */
class InsightsModule
{
    public const EVENT_RULES = [
        'pos.line_voided' => 'onVoid',
        'pos.check_voided' => 'onVoid',
        'pos.comp_approved' => 'onComp',
        'pos.check_settled' => 'onSettled',
        'pos.check_reopened' => 'onCheckReopened',
        'frontoffice.room_moved' => 'onRoomMoved',
        'frontoffice.guest_checked_out' => 'onCheckedOut',
        'cashier.shift_closed' => 'onShiftClosed',
        'folio.line_posted' => 'onFolioLine',
    ];

    public static function subscribe(EventBus $bus): void
    {
        Artisan::starting(fn ($artisan) => $artisan->resolveCommands([ScanCommand::class]));

        foreach (self::EVENT_RULES as $type => $method) {
            $bus->subscribe($type, "insights.$method", function (StoredEvent $e) use ($method) {
                if ($e->hotel_id) {
                    app(Evaluator::class)->$method($e);
                }
            });
        }
    }
}
