<?php

namespace App\Modules\Documents;

use App\Modules\Documents\Application\Console\SendScheduledReportsCommand;
use App\Platform\Events\EventBus;
use Illuminate\Console\Application as Artisan;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;

class DocumentsModule
{
    public static function subscribe(EventBus $bus): void
    {
        View::addNamespace('documents', __DIR__.'/Resources/views');
        View::addNamespace('hr', app_path('Modules/HR/Resources/views')); // e-contract PDF view
        RateLimiter::for('verify', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        Artisan::starting(fn (Artisan $a) => $a->resolveCommands([SendScheduledReportsCommand::class]));
    }
}
