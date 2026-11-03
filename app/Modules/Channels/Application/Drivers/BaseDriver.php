<?php

namespace App\Modules\Channels\Application\Drivers;

use App\Modules\Channels\Domain\Connection;
use Illuminate\Http\Request;
use SimpleXMLElement;

abstract class BaseDriver implements ChannelDriver
{
    /** Default endpoints per environment; override with settings.endpoints.* on the connection. */
    protected array $endpoints = [];

    public function kind(): string
    {
        return 'channel_manager';
    }

    protected function endpoint(Connection $c, string $name): string
    {
        return $c->settings['endpoints'][$name] ?? $this->endpoints[$c->environment][$name] ?? $this->endpoints['production'][$name] ?? '';
    }

    protected function cred(Connection $c, string $key): string
    {
        return (string) ($c->credentials[$key] ?? '');
    }

    protected function basicAuth(Connection $c): array
    {
        return ['Authorization' => 'Basic '.base64_encode($this->cred($c, 'username').':'.$this->cred($c, 'password'))];
    }

    /** Group consecutive dates with identical values into ranges (fewer, smaller messages). */
    protected function ranges(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$a['room'], $a['rate'] ?? '', $a['date']] <=> [$b['room'], $b['rate'] ?? '', $b['date']]);
        $out = [];
        foreach ($rows as $r) {
            $last = end($out);
            $sig = fn ($x) => [$x['room'], $x['rate'] ?? null, $x['availability'] ?? null, $x['amount'] ?? null, $x['min_stay'] ?? null, $x['closed'] ?? false, $x['cta'] ?? false, $x['ctd'] ?? false];
            if ($last && $sig($last) === $sig($r) && date('Y-m-d', strtotime($last['end'].' +1 day')) === $r['date']) {
                $out[array_key_last($out)]['end'] = $r['date'];

                continue;
            }
            $out[] = $r + ['start' => $r['date'], 'end' => $r['date']];
        }

        return $out;
    }

    /** Verify `X-Signature: sha256=<hmac(body)>` using credentials.webhook_secret. */
    public function verify(Request $request, Connection $c): bool
    {
        $secret = $this->cred($c, 'webhook_secret');
        if ($secret === '') {
            return $c->environment === 'sandbox'; // sandbox connections may receive unsigned test traffic
        }
        $sig = (string) ($request->header('X-Signature') ?? $request->header('X-Hub-Signature-256') ?? '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $sig) || hash_equals(substr($expected, 7), $sig);
    }

    public function acknowledge(array $bookings): array
    {
        return ['status' => 'ok', 'received' => array_column($bookings, 'external_ref')];
    }

    public function testRequest(Connection $c): ?array
    {
        return null;
    }

    protected function xml(string $body): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new \InvalidArgumentException('Malformed XML');
        }

        return $xml;
    }

    protected static function e(string|int|float|null $v): string
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Amounts are stored in minor units; XAF has no decimals, other currencies have two. */
    protected static function money(int $amount, string $currency): string
    {
        return in_array($currency, ['XAF', 'XOF'], true) ? (string) $amount : number_format($amount / 100, 2, '.', '');
    }

    protected static function minor(string|float $amount, string $currency): int
    {
        return in_array($currency, ['XAF', 'XOF'], true) ? (int) round((float) $amount) : (int) round((float) $amount * 100);
    }
}
