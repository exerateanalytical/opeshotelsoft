<?php

namespace App\Modules\Edge\Application;

/**
 * Signed edge token: every sync request carries the node id, a timestamp
 * and an HMAC-SHA256 over method, path, timestamp and body hash, keyed with
 * the node secret issued once at registration.
 */
class Signature
{
    public static function sign(string $secret, string $method, string $path, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', strtoupper($method)."\n/".ltrim($path, '/')."\n".$timestamp."\n".hash('sha256', $body), $secret);
    }

    /** @return array<string, string> */
    public static function headers(int $nodeId, string $secret, string $method, string $path, string $body, ?int $time = null): array
    {
        $ts = (string) ($time ?? time());

        return [
            'X-Edge-Node' => (string) $nodeId,
            'X-Edge-Timestamp' => $ts,
            'X-Edge-Signature' => self::sign($secret, $method, $path, $ts, $body),
        ];
    }
}
