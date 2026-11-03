<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/**
 * Expedia Group direct connectivity: EQC Availability & Rates (AR) and Booking Retrieval (BR).
 * Requires Expedia connectivity partner certification and EQC credentials per property.
 */
class ExpediaDriver extends BaseDriver
{
    private const NS = 'http://www.expediaconnect.com/EQC/AR/2011/06';

    protected array $endpoints = [
        'production' => [
            'ar' => 'https://services.expediapartnercentral.com/eqc/ar',
            'br' => 'https://services.expediapartnercentral.com/eqc/br',
        ],
    ];

    public function kind(): string
    {
        return 'direct';
    }

    public function ariRequests(Connection $c, array $rows): array
    {
        $updates = '';
        foreach ($this->ranges($rows) as $r) {
            $inv = isset($r['availability']) ? '<Inventory totalInventoryAvailable="'.(int) $r['availability'].'"/>' : '';
            $rate = '';
            if ($r['rate']) {
                $price = isset($r['amount']) ? '<Rate currency="'.$r['currency'].'"><PerDay rate="'.self::money($r['amount'], $r['currency']).'"/></Rate>' : '';
                $restr = '<Restrictions closedToArrival="'.(($r['cta'] ?? false) ? 'true' : 'false').'" closedToDeparture="'.(($r['ctd'] ?? false) ? 'true' : 'false').'"'
                    .(isset($r['min_stay']) ? ' minLOS="'.(int) $r['min_stay'].'"' : '').'/>';
                $rate = '<RatePlan id="'.self::e($r['rate']).'" closed="'.(($r['closed'] ?? false) ? 'true' : 'false').'">'.$price.$restr.'</RatePlan>';
            }
            $updates .= '<AvailRateUpdate><DateRange from="'.$r['start'].'" to="'.$r['end'].'"/><RoomType id="'.self::e($r['room']).'">'.$inv.$rate.'</RoomType></AvailRateUpdate>';
        }
        $body = '<?xml version="1.0" encoding="UTF-8"?><AvailRateUpdateRQ xmlns="'.self::NS.'">'
            .'<Authentication username="'.self::e($this->cred($c, 'username')).'" password="'.self::e($this->cred($c, 'password')).'"/>'
            .'<Hotel id="'.self::e($c->property_code).'"/>'.$updates.'</AvailRateUpdateRQ>';

        return [['method' => 'POST', 'url' => $this->endpoint($c, 'ar'), 'headers' => ['Content-Type' => 'text/xml'], 'body' => $body]];
    }

    public function testRequest(Connection $c): ?array
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><BookingRetrievalRQ xmlns="http://www.expediaconnect.com/EQC/BR/2014/01">'
            .'<Authentication username="'.self::e($this->cred($c, 'username')).'" password="'.self::e($this->cred($c, 'password')).'"/>'
            .'<Hotel id="'.self::e($c->property_code).'"/></BookingRetrievalRQ>';

        return ['method' => 'POST', 'url' => $this->endpoint($c, 'br'), 'headers' => ['Content-Type' => 'text/xml'], 'body' => $body];
    }

    /** Parse BookingRetrievalRS / booking notification. */
    public function parseBookings(string $body, string $contentType): array
    {
        $xml = $this->xml($body);
        $first = fn ($node, string $name) => ($node->xpath('.//*[local-name()="'.$name.'"]') ?: [null])[0];
        $out = [];
        foreach ($xml->xpath('//*[local-name()="Booking"]') ?: [] as $b) {
            $stay = $first($b, 'StayDate');
            $room = $first($b, 'RoomStay');
            $guestCount = $first($b, 'GuestCount');
            $total = $first($b, 'Total');
            $name = $first($b, 'PersonName');
            $email = $first($b, 'Email');
            $phone = $first($b, 'Phone');
            $currency = (string) ($total['currency'] ?? 'XAF');
            $type = strtolower((string) ($b['type'] ?? 'book'));
            $out[] = [
                'external_ref' => (string) $b['id'],
                'status' => $type === 'cancel' ? 'cancelled' : ($type === 'modify' ? 'modified' : 'new'),
                'version' => (string) ($b['createDateTime'] ?? ''),
                'arrival' => (string) ($stay['arrival'] ?? ''),
                'departure' => (string) ($stay['departure'] ?? ''),
                'room' => (string) ($room['roomTypeID'] ?? ''),
                'rate' => (string) ($room['ratePlanID'] ?? ''),
                'adults' => max(1, (int) ($guestCount['adult'] ?? 1)),
                'children' => (int) ($guestCount['child'] ?? 0),
                'total' => self::minor((string) ($total['amountAfterTaxes'] ?? 0), $currency),
                'currency' => $currency,
                'guest' => [
                    'first_name' => (string) ($name?->GivenName ?? 'Guest'), 'last_name' => (string) ($name?->Surname ?? 'Expedia'),
                    'email' => ((string) $email) ?: null, 'phone' => ((string) ($phone['number'] ?? '')) ?: null, 'country' => null,
                ],
                'notes' => (string) ($first($b, 'SpecialRequest') ?? ''),
            ];
        }

        return array_values(array_filter($out, fn ($x) => $x['external_ref'] !== ''));
    }
}
