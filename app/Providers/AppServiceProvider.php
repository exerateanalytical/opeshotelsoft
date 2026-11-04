<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use App\Platform\I18n\JsonFragmentLoader;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // __() also reads the per-module fragments lang/<locale>/*.json (lang/<locale>.json wins on clashes),
        // the same merge HandleInertiaRequests ships to the UI.
        $this->app->extend('translation.loader', fn (Loader $loader, $app) => new JsonFragmentLoader($loader, $app['path.lang']));

        // SEC2: only the token endpoints of Passport are exposed (registered in boot()); the authorization-code and
        // device screens are not used by any client of this platform.
        Passport::ignoreRoutes();
    }

    /** POST /oauth/token (throttled as in Passport) and POST /oauth/token/refresh (web session, transient token). */
    private function passportRoutes(): void
    {
        Route::group([
            'as' => 'passport.', 'prefix' => config('passport.path', 'oauth'),
            'namespace' => 'Laravel\Passport\Http\Controllers', 'middleware' => config('passport.middleware', []),
        ], function () {
            Route::post('/token', ['uses' => 'AccessTokenController@issueToken', 'as' => 'token', 'middleware' => 'throttle']);
            $guard = config('passport.guard', null);
            Route::post('/token/refresh', ['uses' => 'TransientTokenController@refresh', 'as' => 'token.refresh',
                'middleware' => ['web', $guard ? 'auth:'.$guard : 'auth']]);
        });
    }

    public function boot(): void
    {
        // Short-lived access tokens with rotating refresh tokens (SPEC §7.4).
        Passport::tokensExpireIn(now()->addHours(12));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addHours(12));
        if (! $this->app->routesAreCached()) {
            $this->passportRoutes();
        }
        // SEC2: say loudly when demo one-click logins meet a production environment.
        if (config('app.demo_logins_requested') && $this->app->isProduction()) {
            Log::warning(config('app.demo_logins')
                ? 'DEMO_LOGINS is ON in production because ALLOW_DEMO_LOGINS_IN_PRODUCTION=true: anyone can sign in as the demo administrator.'
                : 'DEMO_LOGINS=true is ignored in production (set ALLOW_DEMO_LOGINS_IN_PRODUCTION=true to override; not recommended).');
        }

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
        });
    }
}
