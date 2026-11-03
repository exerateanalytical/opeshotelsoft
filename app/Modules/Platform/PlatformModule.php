<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Ops\OpsBootstrap;
use App\Platform\Events\EventBus;

/** Module hook called by ModuleServiceProvider::boot(); wires the Ops sub-module. */
class PlatformModule
{
    public static function subscribe(EventBus $bus): void
    {
        OpsBootstrap::boot();
    }
}
