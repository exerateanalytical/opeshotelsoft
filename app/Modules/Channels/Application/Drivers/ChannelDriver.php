<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;
use Illuminate\Http\Request;

/**
 * Anti-corruption layer for one external channel. Drivers translate between
 * our ARI/reservation model and the channel's wire format; they never touch
 * our database directly.
 *
 * ARI row:  ['room' => ext room id, 'rate' => ext rate id|null, 'date' => Y-m-d,
 *            'availability' => int|null, 'amount' => int|null, 'currency' => 'XAF',
 *            'min_stay' => int|null, 'closed' => bool, 'cta' => bool, 'ctd' => bool]
 *
 * Normalized booking:
 *   ['external_ref', 'status' => new|modified|cancelled, 'version', 'arrival', 'departure',
 *    'room' => ext room id, 'rate' => ext rate id, 'adults', 'children', 'total', 'currency',
 *    'guest' => ['first_name','last_name','email','phone','country'], 'notes', 'commission']
 */
interface ChannelDriver
{
    /** Kind of integration: direct OTA, metasearch or channel manager. */
    public function kind(): string;

    /**
     * Build the HTTP request(s) that push ARI rows.
     *
     * @return array<int, array{method:string, url:string, headers:array, body:string}>
     */
    public function ariRequests(Connection $c, array $rows): array;

    /** Request used to test credentials. */
    public function testRequest(Connection $c): ?array;

    /** @return array<int, array> normalized bookings found in an inbound payload */
    public function parseBookings(string $body, string $contentType): array;

    /** Verify an inbound webhook/notification is genuine. */
    public function verify(Request $request, Connection $c): bool;

    /** Body to answer an inbound notification with (acknowledgement). */
    public function acknowledge(array $bookings): array;
}
