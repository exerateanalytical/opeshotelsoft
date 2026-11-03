<?php

namespace App\Platform\Tenancy;

use App\Modules\Tenancy\Domain\Hotel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for an authenticated API request.
 * Group comes from the user; the active hotel from the X-Hotel-Id header,
 * which must be inside the user's hotel scope.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $allowed = $user->allowedHotelIds();
        $hotelId = $request->header('X-Hotel-Id');

        if ($hotelId !== null) {
            $hotelId = (int) $hotelId;
            $hotel = $this->ctx->withoutScope(fn () => Hotel::find($hotelId));
            $inGroup = $hotel && $hotel->group_id === $user->group_id;
            $inScope = $allowed === null || in_array($hotelId, $allowed, true);
            if (! $inGroup || ! $inScope) {
                abort(403, 'You do not have access to this hotel.');
            }
        }

        $this->ctx->set($user->group_id, $hotelId ?: null, $allowed, $user);

        return $next($request);
    }
}
