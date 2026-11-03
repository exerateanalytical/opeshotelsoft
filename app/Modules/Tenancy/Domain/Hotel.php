<?php

namespace App\Modules\Tenancy\Domain;

use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A property. Each hotel is its own legal and accounting entity. */
class Hotel extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'group_id', 'business_date'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'business_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('group', function (Builder $q) {
            $ctx = app(TenantContext::class);
            if ($ctx->isBypassed()) {
                return;
            }
            $q->where('hotels.group_id', $ctx->groupId() ?? 0);
            if (($allowed = $ctx->allowedHotelIds()) !== null) {
                $q->whereIn('hotels.id', $allowed);
            }
        });

        static::creating(function (Hotel $hotel) {
            $hotel->group_id ??= app(TenantContext::class)->groupId();
            $hotel->business_date ??= now($hotel->timezone ?? 'Africa/Douala')->toDateString();
        });
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }
}
