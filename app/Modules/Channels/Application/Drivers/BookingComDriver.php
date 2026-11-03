<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/**
 * Booking.com direct connectivity (B.XML, OTA 2003B flavour).
 * Requires Booking.com Connectivity Partner certification and machine-account credentials.
 */
class BookingComDriver extends OtaXmlDriver
{
    protected array $endpoints = [
        'production' => [
            'availability' => 'https://supply-xml.booking.com/hotels/ota/OTA_HotelAvailNotif',
            'rates' => 'https://supply-xml.booking.com/hotels/ota/OTA_HotelRateAmountNotif',
            'reservations' => 'https://secure-supply-xml.booking.com/hotels/ota/OTA_HotelResNotif',
        ],
    ];

    public function kind(): string
    {
        return 'direct';
    }

    public function ariRequests(Connection $c, array $rows): array
    {
        $headers = $this->basicAuth($c) + ['Content-Type' => 'application/xml'];
        $out = [['method' => 'POST', 'url' => $this->endpoint($c, 'availability'), 'headers' => $headers, 'body' => $this->availNotif($c, $rows)]];
        if (collect($rows)->whereNotNull('amount')->isNotEmpty()) {
            $out[] = ['method' => 'POST', 'url' => $this->endpoint($c, 'rates'), 'headers' => $headers, 'body' => $this->rateNotif($c, $rows)];
        }

        return $out;
    }

    public function testRequest(Connection $c): ?array
    {
        return ['method' => 'GET', 'url' => $this->endpoint($c, 'reservations').'?hotel_ids='.urlencode((string) $c->property_code), 'headers' => $this->basicAuth($c), 'body' => ''];
    }
}
