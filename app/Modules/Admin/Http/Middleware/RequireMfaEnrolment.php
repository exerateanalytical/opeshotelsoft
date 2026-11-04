<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Application\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the API door for staff who must enrol in two-factor authentication but have not yet
 * (the web shell already redirects them to the profile page; the laravel_token cookie and
 * bearer tokens bypassed that). Only the endpoints the shell needs to render the profile stay open.
 * SEC1 audit, blocker #20.
 */
class RequireMfaEnrolment
{
    private const ALLOWED = ['api/v1/me', 'api/v1/auth/logout'];

    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Group middleware runs before the route's auth:api, so resolve the API guard explicitly and only on protected routes.
        $protected = in_array('auth:api', $request->route()?->gatherMiddleware() ?? [], true);
        $user = $protected ? $request->user('api') : null;
        if ($user && ! in_array($request->path(), self::ALLOWED, true) && $this->twoFactor->mustEnrol($user)) {
            abort(403, 'Two-factor authentication must be set up for this account before using the API.');
        }

        return $next($request);
    }
}
