<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/** Channex.io channel manager (REST JSON, user-api-key). */
class ChannexDriver extends BaseDriver
{
    protected array $endpoints = [
        'sandbox' => ['base' => 'https://staging.channex.io/api/v1'],
        'production' => ['base' => 'https://app.channex.io/api/v1'],
    ];

    public function ariRequests(Connection $c, array $rows): array
    {
        $headers = ['user-api-key' => $this->cred($c, 'api_key'), 'Content-Type' => 'application/json'];
        $avail = [];
        $restr = [];
        foreach ($this->ranges($rows) as $r) {
            if (isset($r['availability']) && ! $r['rate']) {
                $avail[] = ['property_id' => $c->property_code, 'room_type_id' => $r['room'], 'date_from' => $r['start'], 'date_to' => $r['end'], 'availability' => (int) $r['availability']];
            }
            if ($r['rate']) {
                $restr[] = array_filter([
                    'property_id' => $c->property_code, 'rate_plan_id' => $r['rate'], 'date_from' => $r['start'], 'date_to' => $r['end'],
                    'rate' => isset($r['amount']) ? self::money($r['amount'], $r['currency']) : null,
                    'min_stay_arrival' => $r['min_stay'] ?? null, 'stop_sell' => $r['closed'] ?? false,
                    'closed_to_arrival' => $r['cta'] ?? false, 'closed_to_departure' => $r['ctd'] ?? false,
                ], fn ($v) => $v !== null);
            }
        }
        $base = $this->endpoint($c, 'base');
        $out = [];
        if ($avail) {
            $out[] = ['method' => 'POST', 'url' => "$base/availability", 'headers' => $headers, 'body' => json_encode(['values' => $avail])];
        }
        if ($restr) {
            $out[] = ['method' => 'POST', 'url' => "$base/restrictions", 'headers' => $headers, 'body' => json_encode(['values' => $restr])];
        }

        return $out;
    }

    public function testRequest(Connection $c): ?array
    {
        return ['method' => 'GET', 'url' => $this->endpoint($c, 'base').'/properties/'.urlencode((string) $c->property_code), 'headers' => ['user-api-key' => $this->cred($c, 'api_key')], 'body' => ''];
    }

    /** Booking revision webhook: {"event":"booking", "payload": {...}} or a revisions feed {"data": [...]}. */
    public function parseBookings(string $body, string $contentType): array
    {
        $data = json_decode($body, true) ?: [];
        $items = isset($data['payload']) ? [$data['payload']] : ($data['data'] ?? []);
        $out = [];
        foreach ($items as $item) {
            $b = $item['attributes'] ?? $item;
            $room = $b['rooms'][0] ?? [];
            $customer = $b['customer'] ?? [];
            $currency = $b['currency'] ?? 'XAF';
            $out[] = [
                'external_ref' => (string) ($b['ota_reservation_code'] ?? $b['booking_id'] ?? $b['id'] ?? ''),
                'status' => match ($b['status'] ?? 'new') {
                    'cancelled' => 'cancelled', 'modified' => 'modified', default => 'new'
                },
                'version' => (string) ($b['revision_id'] ?? $item['id'] ?? ''),
                'arrival' => $b['arrival_date'] ?? $room['checkin_date'] ?? '',
                'departure' => $b['departure_date'] ?? $room['checkout_date'] ?? '',
                'room' => (string) ($room['room_type_id'] ?? ''),
                'rate' => (string) ($room['rate_plan_id'] ?? ''),
                'adults' => max(1, (int) ($room['occupancy']['adults'] ?? 1)),
                'children' => (int) ($room['occupancy']['children'] ?? 0),
                'total' => self::minor($b['amount'] ?? 0, $currency),
                'currency' => $currency,
                'guest' => [
                    'first_name' => $customer['name'] ?? 'Guest', 'last_name' => $customer['surname'] ?? ($b['ota_name'] ?? 'OTA'),
                    'email' => $customer['mail'] ?? null, 'phone' => $customer['phone'] ?? null, 'country' => $customer['country'] ?? null,
                ],
                'notes' => $b['notes'] ?? null,
                'source' => $b['ota_name'] ?? null,
            ];
        }

        return array_values(array_filter($out, fn ($x) => $x['external_ref'] !== ''));
    }
}
