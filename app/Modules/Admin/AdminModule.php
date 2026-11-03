<?php

namespace App\Modules\Admin;

use App\Platform\Events\EventBus;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

/** Boot hook (called by ModuleServiceProvider): auth hardening defaults. */
class AdminModule
{
    public static function subscribe(EventBus $bus): void
    {
        // Password policy used wherever Password::defaults() is applied.
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(10)->letters()->numbers());

        // Token endpoints: per IP per route, on top of the per-account LoginGuard throttle.
        RateLimiter::for('auth-token', fn (Request $r) => Limit::perMinute(10)->by($r->ip().'|'.$r->route()?->uri()));

        // Session hardening: HttpOnly, SameSite, Secure in production unless explicitly configured.
        config([
            'session.http_only' => true,
            'session.same_site' => config('session.same_site') ?: 'lax',
            'session.secure' => config('session.secure') ?? app()->isProduction(),
            'session.expire_on_close' => (bool) config('session.expire_on_close'),
        ]);
    }
}
