<?php

namespace App\Platform;

use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Discovers bounded contexts under app/Modules. Each context may provide:
 *   Database/Migrations/   migrations
 *   routes.php             API routes (mounted under /api/v1)
 *   <Name>Module.php       static subscribe(EventBus) for event handlers
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(EventBus::class);
    }

    public function boot(): void
    {
        // Named limiters are keyed per route so strict limits on sensitive endpoints
        // do not share a counter with the general API allowance.
        RateLimiter::for('public-write', fn (Request $r) => Limit::perMinute(20)->by($r->ip().'|'.$r->route()?->uri()));
        RateLimiter::for('public-read', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(1200)->by($r->user()?->id ?: $r->ip()));

        foreach ($this->modules() as $name => $path) {
            if (is_dir("$path/Database/Migrations")) {
                $this->loadMigrationsFrom("$path/Database/Migrations");
            }

            if (is_file("$path/routes.php")) {
                Route::prefix('api/v1')
                    ->middleware(['api', 'auth:api', 'tenant', 'idempotent'])
                    ->group("$path/routes.php");
            }

            if (is_file("$path/public-routes.php")) {
                Route::prefix('api/v1')->middleware(['api', 'idempotent'])->group("$path/public-routes.php");
            }

            $class = "App\\Modules\\$name\\{$name}Module";
            if (class_exists($class) && method_exists($class, 'subscribe')) {
                $class::subscribe($this->app->make(EventBus::class));
            }
        }
    }

    /** @return array<string, string> */
    public static function modules(): array
    {
        $out = [];
        foreach (glob(app_path('Modules').'/*', GLOB_ONLYDIR) as $dir) {
            $out[basename($dir)] = $dir;
        }

        return $out;
    }
}
