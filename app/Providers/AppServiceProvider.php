<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use App\Platform\I18n\JsonFragmentLoader;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // __() also reads the per-module fragments lang/<locale>/*.json (lang/<locale>.json wins on clashes),
        // the same merge HandleInertiaRequests ships to the UI.
        $this->app->extend('translation.loader', fn (Loader $loader, $app) => new JsonFragmentLoader($loader, $app['path.lang']));
    }

    public function boot(): void
    {
        // Short-lived access tokens with rotating refresh tokens (SPEC §7.4).
        Passport::tokensExpireIn(now()->addHours(12));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addHours(12));

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
        });
    }
}
