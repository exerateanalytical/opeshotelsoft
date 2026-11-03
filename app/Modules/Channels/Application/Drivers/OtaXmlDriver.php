<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/**
 * OpenTravel Alliance 2003B messages (OTA_HotelAvailNotifRQ, OTA_HotelRateAmountNotifRQ,
 * OTA_HotelResNotifRQ) shared by Booking.com B.XML and SiteMinder pmsXchange.
 */
abstract class OtaXmlDriver extends BaseDriver
{
    protected function availNotif(Connection $c, array $rows): string
    {
        $msgs = '';
        foreach ($this->ranges($rows) as $r) {
            $restr = '';
            if (($r['closed'] ?? false) || ($r['cta'] ?? false) || ($r['ctd'] ?? false)) {
                $status = ($r['closed'] ?? false) ? 'Close' : 'Open';
                $restr .= '<RestrictionStatus Status="'.$status.'"'.(($r['cta'] ?? false) ? ' Restriction="Arrival"' : '').'/>';
            }
            $los = isset($r['min_stay']) ? '<LengthsOfStay><LengthOfStay Time="'.(int) $r['min_stay'].'" MinMaxMessageType="SetMinLOS"/></LengthsOfStay>' : '';
            $msgs .= '<AvailStatusMessage'.(isset($r['availability']) ? ' BookingLimit="'.(int) $r['availability'].'"' : '').'>'
                .'<StatusApplicationControl Start="'.$r['start'].'" End="'.$r['end'].'" InvTypeCode="'.self::e($r['room']).'"'
                .($r['rate'] ? ' RatePlanCode="'.self::e($r['rate']).'"' : '').'/>'.$los.$restr.'</AvailStatusMessage>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<OTA_HotelAvailNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05" Version="1.0" TimeStamp="'.now()->toIso8601String().'" EchoToken="'.uniqid().'">'
            .'<AvailStatusMessages HotelCode="'.self::e($c->property_code).'">'.$msgs.'</AvailStatusMessages></OTA_HotelAvailNotifRQ>';
    }

    protected function rateNotif(Connection $c, array $rows): string
    {
        $msgs = '';
        foreach ($this->ranges(array_filter($rows, fn ($r) => isset($r['amount'], $r['rate']))) as $r) {
            $msgs .= '<RateAmountMessage><StatusApplicationControl Start="'.$r['start'].'" End="'.$r['end'].'" InvTypeCode="'.self::e($r['room']).'" RatePlanCode="'.self::e($r['rate']).'"/>'
                .'<Rates><Rate><BaseByGuestAmts><BaseByGuestAmt AmountAfterTax="'.self::money($r['amount'], $r['currency']).'" CurrencyCode="'.$r['currency'].'"/></BaseByGuestAmts></Rate></Rates></RateAmountMessage>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<OTA_HotelRateAmountNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05" Version="1.0" TimeStamp="'.now()->toIso8601String().'" EchoToken="'.uniqid().'">'
            .'<RateAmountMessages HotelCode="'.self::e($c->property_code).'">'.$msgs.'</RateAmountMessages></OTA_HotelRateAmountNotifRQ>';
    }

    /** Parse OTA_HotelResNotifRQ (new / modify / cancel). */
    public function parseBookings(string $body, string $contentType): array
    {
        $xml = $this->xml($body);
        $xml->registerXPathNamespace('o', 'http://www.opentravel.org/OTA/2003/05');
        $out = [];
        foreach ($xml->xpath('//o:HotelReservation') ?: $xml->xpath('//HotelReservation') ?: [] as $res) {
            $res->registerXPathNamespace('o', 'http://www.opentravel.org/OTA/2003/05');
            $q = fn (string $path) => ($res->xpath($path) ?: [null])[0];
            $status = strtolower((string) ($res['ResStatus'] ?? 'Book'));
            $id = $q('.//o:HotelReservationID[@ResID_Type="14"]') ?? $q('.//o:HotelReservationID') ?? $q('.//HotelReservationID');
            $span = $q('.//o:TimeSpan') ?? $q('.//TimeSpan');
            $roomType = $q('.//o:RoomType') ?? $q('.//RoomType');
            $ratePlan = $q('.//o:RatePlan') ?? $q('.//RatePlan');
            $total = $q('.//o:Total') ?? $q('.//Total');
            $person = $q('.//o:Customer/o:PersonName') ?? $q('.//PersonName');
            $email = $q('.//o:Customer/o:Email') ?? $q('.//Email');
            $phone = $q('.//o:Customer/o:Telephone') ?? $q('.//Telephone');
            $country = $q('.//o:Customer/o:Address/o:CountryName') ?? $q('.//CountryName');
            $adults = 0;
            $children = 0;
            foreach ($res->xpath('.//o:GuestCount') ?: $res->xpath('.//GuestCount') ?: [] as $gc) {
                ((string) $gc['AgeQualifyingCode'] === '8') ? $children += (int) $gc['Count'] : $adults += (int) $gc['Count'];
            }
            $currency = (string) ($total['CurrencyCode'] ?? 'XAF');
            $out[] = [
                'external_ref' => (string) ($id['ResID_Value'] ?? ''),
                'status' => match (true) {
                    str_contains($status, 'cancel') => 'cancelled',
                    str_contains($status, 'modif') => 'modified',
                    default => 'new',
                },
                'version' => (string) ($res['LastModifyDateTime'] ?? $res['CreateDateTime'] ?? ''),
                'arrival' => (string) ($span['Start'] ?? ''),
                'departure' => (string) ($span['End'] ?? ''),
                'room' => (string) ($roomType['RoomTypeCode'] ?? ''),
                'rate' => (string) ($ratePlan['RatePlanCode'] ?? ''),
                'adults' => max(1, $adults),
                'children' => $children,
                'total' => self::minor((string) ($total['AmountAfterTax'] ?? 0), $currency),
                'currency' => $currency,
                'guest' => [
                    'first_name' => (string) ($person?->GivenName ?? 'Guest'),
                    'last_name' => (string) ($person?->Surname ?? 'OTA'),
                    'email' => (string) $email ?: null,
                    'phone' => (string) ($phone['PhoneNumber'] ?? '') ?: null,
                    'country' => strtoupper(substr((string) ($country['Code'] ?? ''), 0, 2)) ?: null,
                ],
                'notes' => (string) ($q('.//o:SpecialRequest/o:Text') ?? $q('.//SpecialRequest') ?? ''),
            ];
        }

        return array_values(array_filter($out, fn ($b) => $b['external_ref'] !== ''));
    }

    public function acknowledge(array $bookings): array
    {
        $refs = implode('', array_map(fn ($b) => '<HotelReservation><ResGlobalInfo><HotelReservationIDs><HotelReservationID ResID_Type="14" ResID_Value="'.self::e($b['external_ref']).'"/></HotelReservationIDs></ResGlobalInfo></HotelReservation>', $bookings));

        return ['xml' => '<?xml version="1.0" encoding="UTF-8"?><OTA_HotelResNotifRS xmlns="http://www.opentravel.org/OTA/2003/05" Version="1.0" TimeStamp="'.now()->toIso8601String().'"><Success/><HotelReservations>'.$refs.'</HotelReservations></OTA_HotelResNotifRS>'];
    }
}
