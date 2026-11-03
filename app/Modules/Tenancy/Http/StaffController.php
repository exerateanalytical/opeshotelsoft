<?php

namespace App\Modules\Tenancy\Http;

use App\Models\User;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Minimal staff directory for the current hotel (pickers). No emails, no permissions. */
class StaffController
{
    public function index(Request $request, TenantContext $ctx)
    {
        $q = \App\Modules\Tenancy\Application\StaffDirectory::query($request->user()->group_id, $ctx->requireHotelId())->with('roles:id,name');
        if ($role = $request->query('role')) {
            $q->whereHas('roles', fn ($r) => $r->where('name', $role));
        }
        if ($term = $request->query('q')) {
            $q->where('name', 'ilike', '%'.$term.'%');
        }

        return $q->orderBy('name')->limit(500)->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'roles' => $u->roles->pluck('name')->values()]);
    }

    /** Users of the group who can work in the given hotel. */
    public static function query(int $groupId, int $hotelId): Builder
    {
        return User::where('group_id', $groupId)
            ->where(fn ($q) => $q->where('status', '!=', 'suspended')->orWhereNull('status'))
            ->where(fn ($q) => $q->where('all_hotels', true)
                ->orWhereHas('hotels', fn ($h) => $h->withoutGlobalScopes()->where('hotels.id', $hotelId)));
    }
}
