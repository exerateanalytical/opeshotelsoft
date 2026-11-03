<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/** MyAllocator channel manager (JSON API, vendor + user tokens). */
class MyAllocatorDriver extends BaseDriver
{
    protected array $endpoints = [
        'production' => ['api' => 'https://api.myallocator.com/pms/v202001/json'],
    ];

    public function ariRequests(Connection $c, array $rows): array
    {
        $alloc = [];
        foreach ($this->ranges($rows) as $r) {
            $alloc[] = array_filter([
                'RoomId' => $r['room'], 'StartDate' => $r['start'], 'EndDate' => $r['end'],
                'Units' => $r['availability'] ?? null,
                'Prices' => isset($r['amount']) ? [['Price' => self::money($r['amount'], $r['currency'])]] : null,
                'MinStay' => $r['min_stay'] ?? null,
                'Closed' => ($r['closed'] ?? false) ? 'true' : null,
                'ClosedForArrival' => ($r['cta'] ?? false) ? 'true' : null,
                'ClosedForDeparture' => ($r['ctd'] ?? false) ? 'true' : null,
            ], fn ($v) => $v !== null);
        }
        $body = json_encode([
            'Auth/VendorId' => $this->cred($c, 'vendor_id'), 'Auth/VendorPassword' => $this->cred($c, 'vendor_password'),
            'Auth/UserToken' => $this->cred($c, 'user_token'), 'Auth/PropertyId' => $c->property_code,
            'Channels' => ['all'], 'Allocations' => $alloc,
        ]);

        return [['method' => 'POST', 'url' => $this->endpoint($c, 'api').'/ARIUpdate', 'headers' => ['Content-Type' => 'application/json'], 'body' => $body]];
    }

    public function parseBookings(string $body, string $contentType): array
    {
        $data = json_decode($body, true) ?: [];
        $out = [];
        foreach ($data['Bookings'] ?? (isset($data['MyallocatorId']) ? [$data] : []) as $b) {
            $room = $b['Rooms'][0] ?? [];
            $cust = $b['Customers'][0] ?? [];
            $currency = $b['TotalCurrency'] ?? 'XAF';
            $out[] = [
                'external_ref' => (string) ($b['OrderId'] ?? $b['MyallocatorId'] ?? ''),
                'status' => ! empty($b['IsCancellation']) ? 'cancelled' : (! empty($b['IsModification']) ? 'modified' : 'new'),
                'version' => (string) ($b['MyallocatorModificationDate'] ?? ''),
                'arrival' => $b['StartDate'] ?? $room['StartDate'] ?? '',
                // MyAllocator EndDate is the last night; departure is the day after.
                'departure' => isset($room['EndDate']) ? date('Y-m-d', strtotime($room['EndDate'].' +1 day')) : ($b['EndDate'] ?? ''),
                'room' => (string) ($room['RoomTypeIds'][0] ?? ''),
                'rate' => (string) ($room['RateId'] ?? ''),
                'adults' => max(1, (int) ($room['Occupancy'] ?? 1)),
                'children' => 0,
                'total' => self::minor($b['TotalPrice'] ?? 0, $currency),
                'currency' => $currency,
                'guest' => [
                    'first_name' => $cust['CustomerFName'] ?? 'Guest', 'last_name' => $cust['CustomerLName'] ?? 'OTA',
                    'email' => $cust['CustomerEmail'] ?? null, 'phone' => $cust['CustomerPhone'] ?? null, 'country' => $cust['CustomerCountry'] ?? null,
                ],
                'notes' => $b['OrderComments'] ?? null,
                'source' => $b['Channel'] ?? null,
            ];
        }

        return array_values(array_filter($out, fn ($x) => $x['external_ref'] !== ''));
    }
}
