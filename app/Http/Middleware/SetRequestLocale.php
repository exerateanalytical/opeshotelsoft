<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleController;
use Closure;
use Illuminate\Http\Request;

/**
 * API locale: the signed-in user's saved locale, else Accept-Language, else French.
 * Only labels change; value codes in payloads stay the same in every language.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale($this->locale($request));

        return $next($request);
    }

    private function locale(Request $request): string
    {
        $user = rescue(fn () => $request->user('api') ?? $request->user(), null, false);
        if ($user && in_array($user->locale, LocaleController::SUPPORTED, true)) {
            return $user->locale;
        }
        // Accept-Language in preference order; the first supported primary language wins.
        foreach ($request->getLanguages() as $lang) {
            $primary = strtolower(explode('_', str_replace('-', '_', $lang))[0]);
            if (in_array($primary, LocaleController::SUPPORTED, true)) {
                return $primary;
            }
        }

        return LocaleController::DEFAULT;
    }
}
