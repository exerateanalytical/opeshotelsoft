<?php

namespace App\Modules\Platform\Ops;

use App\Modules\Platform\Ops\Http\HealthController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Registers the /health route, JSON log channels and the public API rate limits.
 * Runs during ModuleServiceProvider::boot(), after the default limiters, so these win.
 */
class OpsBootstrap
{
    public static function boot(): void
    {
        self::logging();
        self::rateLimits();

        if (! app()->routesAreCached()) {
            Route::get('health', HealthController::class)
                ->middleware('throttle:health')
                ->name('ops.health');
        }
    }

    /** Adds `json` (daily file) and `stderr_json` (containers) channels, one JSON object per line. */
    private static function logging(): void
    {
        $level = config('logging.channels.single.level', 'debug');
        $channels = config('logging.channels', []);

        $channels['json'] ??= [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.json.log'),
            'level' => $level,
            'days' => 14,
            'formatter' => JsonFormatter::class,
            'replace_placeholders' => true,
        ];
        $channels['stderr_json'] ??= [
            'driver' => 'monolog',
            'level' => $level,
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'formatter' => JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
        ];
        config(['logging.channels' => $channels]);
    }

    private static function rateLimits(): void
    {
        // Public booking API: reads generous, writes strict per IP + route, capped per group slug.
        RateLimiter::for('public-read', fn (Request $r) => [
            Limit::perMinute(300)->by('pr|'.$r->ip()),
            Limit::perDay(20000)->by('prd|'.$r->ip()),
        ]);
        RateLimiter::for('public-write', fn (Request $r) => [
            Limit::perMinute(20)->by('pw|'.$r->ip().'|'.$r->route()?->uri()),
            Limit::perHour(200)->by('pwh|'.$r->ip()),
            Limit::perMinute(300)->by('pwg|'.(string) $r->route('group')),
        ]);
        RateLimiter::for('health', fn (Request $r) => Limit::perMinute(60)->by('h|'.$r->ip()));
    }
}
