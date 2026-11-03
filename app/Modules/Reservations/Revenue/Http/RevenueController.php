<?php

namespace App\Modules\Reservations\Revenue\Http;

use Illuminate\Http\Request;

/**
 * Permissions: revenue.view (read), revenue.manage (rules, packages, promos, contracts, settings),
 * revenue.approve (accept / reject rate suggestions), revenue.upsell (offer and accept room upgrades).
 */
abstract class RevenueController
{
    protected function need(Request $request, string ...$permissions): void
    {
        $user = $request->user();
        abort_unless(collect($permissions)->contains(fn ($p) => $user->can($p)), 403);
    }
}
