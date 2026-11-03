<?php

namespace App\Modules\Property;

use App\Modules\Property\FastConfigure\Console\DemoConfigureCommand;
use App\Platform\Events\EventBus;
use Illuminate\Console\Application as Artisan;

class PropertyModule
{
    public static function subscribe(EventBus $bus): void
    {
        Artisan::starting(fn (Artisan $a) => $a->resolveCommands([DemoConfigureCommand::class]));
    }
}
