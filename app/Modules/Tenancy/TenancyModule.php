<?php

namespace App\Modules\Tenancy;

use App\Platform\Events\EventBus;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Boot hook (called by ModuleServiceProvider). */
class TenancyModule
{
    public static function subscribe(EventBus $bus): void
    {
        // Administrator credential resets and hotel status changes (GAPH): few per actor, plus a per-IP ceiling.
        RateLimiter::for('credential-admin', fn (Request $r) => [
            Limit::perMinute(5)->by('ca|u|'.($r->user()?->id ?: $r->ip())),
            Limit::perHour(30)->by('ca|ip|'.$r->ip()),
        ]);
    }
}
