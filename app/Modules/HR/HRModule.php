<?php

namespace App\Modules\HR;

use App\Modules\HR\Talent\Console\TalentCommands;
use App\Platform\Events\EventBus;
use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\View;

class HRModule
{
    public static function subscribe(EventBus $bus): void
    {
        View::addNamespace('talent', __DIR__.'/Talent/Resources/views');
        Artisan::starting(fn (Artisan $a) => $a->resolveCommands(TalentCommands::all()));
    }
}
