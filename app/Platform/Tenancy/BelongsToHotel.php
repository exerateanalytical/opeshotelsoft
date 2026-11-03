<?php

namespace App\Platform\Tenancy;

use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hotel-level tenancy: rows owned by one property. Reads are limited to
 * the active hotel (or the actor's allowed hotels when none is active);
 * writes are stamped with the active group and hotel.
 */
trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        static::addGlobalScope('hotel', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if ($ctx->isBypassed()) {
                return;
            }
            $model = $builder->getModel();
            $builder->where($model->qualifyColumn('group_id'), $ctx->groupId() ?? 0);
            if ($ctx->hotelId()) {
                $builder->where($model->qualifyColumn('hotel_id'), $ctx->hotelId());
            } elseif (($allowed = $ctx->allowedHotelIds()) !== null) {
                $builder->whereIn($model->qualifyColumn('hotel_id'), $allowed);
            }
        });

        static::creating(function ($model) {
            $ctx = app(TenantContext::class);
            $model->group_id ??= $ctx->groupId();
            $model->hotel_id ??= $ctx->hotelId();
            if (! $model->group_id || ! $model->hotel_id) {
                throw new TenantException('No hotel in context for '.static::class);
            }
        });
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}
