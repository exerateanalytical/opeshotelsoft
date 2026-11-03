<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/**
 * Cloudbeds (as channel manager) via Cloudbeds API v1.2 with OAuth bearer token.
 * Exact endpoint set to confirm during Cloudbeds partner onboarding (docs/BLOCKERS.md).
 */
class CloudbedsDriver extends BaseDriver
{
    protected array $endpoints = [
        'production' => ['api' => 'https://api.cloudbeds.com/api/v1.2'],
    ];

    public function ariRequests(Connection $c, array $rows): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->cred($c, 'access_token'), 'Content-Type' => 'application/json'];
        $rates = [];
        foreach ($this->ranges($rows) as $r) {
            $rates[] = array_filter([
                'roomTypeID' => $r['room'], 'rateID' => $r['rate'] ?: null, 'startDate' => $r['start'], 'endDate' => $r['end'],
                'rate' => isset($r['amount']) ? self::money($r['amount'], $r['currency']) : null,
                'roomsAvailable' => $r['availability'] ?? null, 'minLos' => $r['min_stay'] ?? null,
                'closedToArrival' => $r['cta'] ?? null, 'closedToDeparture' => $r['ctd'] ?? null, 'blocked' => $r['closed'] ?? null,
            ], fn ($v) => $v !== null);
        }

        return [['method' => 'POST', 'url' => $this->endpoint($c, 'api').'/putRate', 'headers' => $headers, 'body' => json_encode(['propertyID' => $c->property_code, 'rates' => $rates])]];
    }

    public function testRequest(Connection $c): ?array
    {
        return ['method' => 'GET', 'url' => $this->endpoint($c, 'api').'/getHotelDetails?propertyID='.urlencode((string) $c->property_code), 'headers' => ['Authorization' => 'Bearer '.$this->cred($c, 'access_token')], 'body' => ''];
    }

    public function parseBookings(string $body, string $contentType): array
    {
        $data = json_decode($body, true) ?: [];
        $items = $data['data'] ?? (isset($data['reservationID']) ? [$data] : []);
        $out = [];
        foreach ($items as $b) {
            $room = $b['rooms'][0] ?? $b['assigned'][0] ?? [];
            $currency = $b['currency'] ?? 'XAF';
            $out[] = [
                'external_ref' => (string) ($b['thirdPartyIdentifier'] ?? $b['reservationID'] ?? ''),
                'status' => ($b['status'] ?? '') === 'canceled' ? 'cancelled' : (($b['event'] ?? '') === 'reservation/dates_changed' ? 'modified' : 'new'),
                'version' => (string) ($b['dateModified'] ?? ''),
                'arrival' => $b['startDate'] ?? '',
                'departure' => $b['endDate'] ?? '',
                'room' => (string) ($room['roomTypeID'] ?? ''),
                'rate' => (string) ($room['rateID'] ?? ''),
                'adults' => max(1, (int) ($room['adults'] ?? $b['adults'] ?? 1)),
                'children' => (int) ($room['children'] ?? 0),
                'total' => self::minor($b['total'] ?? 0, $currency),
                'currency' => $currency,
                'guest' => [
                    'first_name' => $b['guestFirstName'] ?? 'Guest', 'last_name' => $b['guestLastName'] ?? ($b['guestName'] ?? 'Cloudbeds'),
                    'email' => $b['guestEmail'] ?? null, 'phone' => $b['guestPhone'] ?? null, 'country' => $b['guestCountry'] ?? null,
                ],
                'notes' => $b['guestNotes'] ?? null,
                'source' => $b['sourceName'] ?? null,
            ];
        }

        return array_values(array_filter($out, fn ($x) => $x['external_ref'] !== ''));
    }
}
