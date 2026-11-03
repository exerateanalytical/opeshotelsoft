<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;

/**
 * SiteMinder pmsXchange (OTA 2003B over SOAP with WS-Security username token).
 * Requires SiteMinder partner onboarding and per-property credentials.
 */
class SiteMinderDriver extends OtaXmlDriver
{
    protected array $endpoints = [
        'sandbox' => ['soap' => 'https://cmtpi.siteminder.com/pmsxchangev2/services/OPESHOTELSOFT'],
        'production' => ['soap' => 'https://smtpi.siteminder.com/pmsxchangev2/services/OPESHOTELSOFT'],
    ];

    public function ariRequests(Connection $c, array $rows): array
    {
        $headers = ['Content-Type' => 'text/xml; charset=utf-8'];
        $out = [['method' => 'POST', 'url' => $this->endpoint($c, 'soap'), 'headers' => $headers, 'body' => $this->soap($c, $this->availNotif($c, $rows))]];
        if (collect($rows)->whereNotNull('amount')->isNotEmpty()) {
            $out[] = ['method' => 'POST', 'url' => $this->endpoint($c, 'soap'), 'headers' => $headers, 'body' => $this->soap($c, $this->rateNotif($c, $rows))];
        }

        return $out;
    }

    private function soap(Connection $c, string $otaXml): string
    {
        $inner = preg_replace('/^<\?xml[^>]+\?>/', '', $otaXml);

        return '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<SOAP-ENV:Header><wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
            .'<wsse:UsernameToken><wsse:Username>'.self::e($this->cred($c, 'username')).'</wsse:Username>'
            .'<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">'.self::e($this->cred($c, 'password')).'</wsse:Password>'
            .'</wsse:UsernameToken></wsse:Security></SOAP-ENV:Header><SOAP-ENV:Body>'.$inner.'</SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }
}
