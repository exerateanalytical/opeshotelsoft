<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Generator;

/** Iterate the hotels of the current group the actor may see, with the tenant context switched to each one. */
class HotelScope
{
    /** @return Generator<int, Hotel> */
    public static function each(?array $onlyIds = null): Generator
    {
        $ctx = app(TenantContext::class);
        $hotels = Hotel::all()->when($onlyIds, fn ($c) => $c->whereIn('id', $onlyIds));
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        try {
            foreach ($hotels as $hotel) {
                $ctx->set($hotel->group_id, $hotel->id, $saved[2], $saved[3]);
                yield $hotel;
            }
        } finally {
            $ctx->set(...$saved);
        }
    }

    /** Run $fn with the context switched to $hotel; the previous context is always restored. */
    public static function in(Hotel $hotel, callable $fn): mixed
    {
        $ctx = app(TenantContext::class);
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        $ctx->set($hotel->group_id, $hotel->id, $saved[2], $saved[3]);
        try {
            return $fn($hotel);
        } finally {
            $ctx->set(...$saved);
        }
    }
}
