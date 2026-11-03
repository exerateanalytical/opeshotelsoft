<?php

namespace App\Platform\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Group-level tenancy: rows shared by all hotels of a group
 * (guests, loyalty, chart-of-accounts templates...).
 */
trait BelongsToGroup
{
    public static function bootBelongsToGroup(): void
    {
        static::addGlobalScope('group', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if ($ctx->isBypassed()) {
                return;
            }
            $builder->where($builder->getModel()->qualifyColumn('group_id'), $ctx->groupId() ?? 0);
        });

        static::creating(function ($model) {
            $model->group_id ??= app(TenantContext::class)->groupId();
            if (! $model->group_id) {
                throw new TenantException('No group in context for '.static::class);
            }
        });
    }
}
