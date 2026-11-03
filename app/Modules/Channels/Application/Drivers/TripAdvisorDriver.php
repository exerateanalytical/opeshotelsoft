<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/**
 * Tripadvisor metasearch (TripConnect / Hotel Availability API).
 * Tripadvisor PULLS live prices from our public endpoint
 * GET /api/v1/public/meta/{connection uuid}/availability, so there is
 * nothing to push. Bookings come back through the booking engine.
 */
class TripAdvisorDriver extends BaseDriver
{
    public function kind(): string
    {
        return 'metasearch';
    }

    public function ariRequests(Connection $c, array $rows): array
    {
        return [];
    }

    public function parseBookings(string $body, string $contentType): array
    {
        return [];
    }
}
