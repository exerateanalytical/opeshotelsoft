<?php

namespace App\Modules\Documents;

use App\Modules\Documents\Application\Console\SendScheduledReportsCommand;
use App\Platform\Events\EventBus;
use Illuminate\Console\Application as Artisan;

class DocumentsModule
{
    public static function subscribe(EventBus $bus): void
    {
        Artisan::starting(fn (Artisan $a) => $a->resolveCommands([SendScheduledReportsCommand::class]));
    }
}
