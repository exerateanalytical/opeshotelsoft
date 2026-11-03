<?php

namespace App\Modules\Tenancy\Application;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Users of a group who can work in a given hotel; shared by pickers and assignment checks. */
class StaffDirectory
{
    public static function query(int $groupId, int $hotelId): Builder
    {
        return User::where('group_id', $groupId)
            ->where(fn ($q) => $q->where('status', '!=', 'suspended')->orWhereNull('status'))
            ->where(fn ($q) => $q->where('all_hotels', true)
                ->orWhereHas('hotels', fn ($h) => $h->withoutGlobalScopes()->where('hotels.id', $hotelId)));
    }
}
