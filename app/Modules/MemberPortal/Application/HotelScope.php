<?php

namespace App\Modules\MemberPortal\Application;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;

/** Runs a callback with one hotel of the member's group as the active tenant, then restores the group-only context. */
class HotelScope
{
    public function __construct(private TenantContext $ctx) {}

    /** A hotel of the current group (404 for any other). */
    public function hotel(int $id): Hotel
    {
        return Hotel::where('status', 'active')->findOrFail($id);
    }

    public function run(Hotel $hotel, callable $fn): mixed
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        try {
            return $fn();
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}
