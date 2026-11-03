<?php

namespace App\Platform\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Domain\Hotel;

/**
 * Request-scoped tenant context: the current group, the active hotel
 * and the hotels the actor may access. Every tenant-aware query and
 * every domain event reads from here.
 */
class TenantContext
{
    private ?int $groupId = null;

    private ?int $hotelId = null;

    /** @var int[]|null null = all hotels in the group */
    private ?array $allowedHotelIds = null;

    private ?User $actor = null;

    private bool $bypass = false;

    public function set(?int $groupId, ?int $hotelId = null, ?array $allowedHotelIds = null, ?User $actor = null): void
    {
        $this->groupId = $groupId;
        $this->hotelId = $hotelId;
        $this->allowedHotelIds = $allowedHotelIds;
        $this->actor = $actor;
    }

    public function forHotel(Hotel $hotel): void
    {
        $this->set($hotel->group_id, $hotel->id, [$hotel->id], $this->actor);
    }

    public function groupId(): ?int
    {
        return $this->groupId;
    }

    public function hotelId(): ?int
    {
        return $this->hotelId;
    }

    public function requireHotelId(): int
    {
        if (! $this->hotelId) {
            throw new TenantException('An active hotel is required. Send the X-Hotel-Id header.');
        }

        return $this->hotelId;
    }

    public function allowedHotelIds(): ?array
    {
        return $this->allowedHotelIds;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    /** Run a callback without tenant scoping (platform jobs only). */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;
        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }
}
