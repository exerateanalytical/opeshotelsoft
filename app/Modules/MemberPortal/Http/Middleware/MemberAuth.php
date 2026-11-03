<?php

namespace App\Modules\MemberPortal\Http\Middleware;

use App\Http\Controllers\LocaleController;
use App\Platform\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /account route: only the `member` guard counts (a staff session is not a member session), the tenant
 * context is the member's own group (no hotel), and the language follows the member's choice.
 */
class MemberAuth
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next): Response
    {
        $member = Auth::guard('member')->user();
        if (! $member || ! $member->verified_at || ! $member->guest_id) {
            Auth::guard('member')->logout();
            if ($request->expectsJson()) {
                abort(401, 'Unauthenticated.');
            }

            return redirect('/login?tab=member');
        }
        $this->ctx->set($member->group_id, null, null);
        // Member routes never see an acting staff user, even when the same browser also holds a staff session.
        $request->setUserResolver(fn () => null);
        $locale = $request->hasSession() ? $request->session()->get('locale') : null;
        app()->setLocale(in_array($locale, LocaleController::SUPPORTED, true) ? $locale : ($member->locale ?: LocaleController::DEFAULT));

        return $next($request);
    }
}
