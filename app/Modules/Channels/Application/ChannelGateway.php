<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Application\Drivers\ChannelDriver;
use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends driver-built requests and logs every exchange. Connections without
 * credentials, or in sandbox without an explicit endpoint override, run in
 * dry-run mode: the exact payload is logged but not sent.
 */
class ChannelGateway
{
    public const DRIVERS = [
        'booking_com' => Drivers\BookingComDriver::class,
        'expedia' => Drivers\ExpediaDriver::class,
        'tripadvisor' => Drivers\TripAdvisorDriver::class,
        'channex' => Drivers\ChannexDriver::class,
        'myallocator' => Drivers\MyAllocatorDriver::class,
        'cloudbeds' => Drivers\CloudbedsDriver::class,
        'siteminder' => Drivers\SiteMinderDriver::class,
    ];

    public function driver(Connection $c): ChannelDriver
    {
        $class = self::DRIVERS[$c->provider] ?? throw new \InvalidArgumentException("Unknown channel {$c->provider}");

        return app($class);
    }

    public function isDryRun(Connection $c): bool
    {
        return ! $c->hasCredentials() || (bool) ($c->settings['dry_run'] ?? false);
    }

    /** @return SyncLog[] */
    public function send(Connection $c, string $kind, array $requests): array
    {
        $logs = [];
        foreach ($requests as $req) {
            $log = SyncLog::create([
                'channel_connection_id' => $c->id, 'direction' => 'outbound', 'kind' => $kind, 'status' => 'queued',
                'request' => mb_substr($req['method'].' '.$req['url']."\n\n".$this->redact($req['body']), 0, 200_000),
            ]);
            if ($this->isDryRun($c)) {
                $log->update(['status' => 'dry_run']);
                $logs[] = $log;

                continue;
            }
            try {
                $response = Http::timeout(30)->retry(2, 500, throw: false)->withHeaders($req['headers'])
                    ->withBody($req['body'], $req['headers']['Content-Type'] ?? 'application/json')
                    ->send($req['method'], $req['url']);
                $ok = $response->successful() && ! preg_match('/<Errors?\b|"errors"\s*:\s*\[\s*\{/i', $response->body());
                $log->update(['status' => $ok ? 'sent' : 'failed', 'http_status' => $response->status(), 'attempts' => 1, 'response' => mb_substr($response->body(), 0, 100_000), 'error' => $ok ? null : 'Channel rejected the message']);
            } catch (Throwable $e) {
                $log->update(['status' => 'failed', 'attempts' => 1, 'error' => $e->getMessage()]);
            }
            $logs[] = $log;
        }
        $c->forceFill(['last_push_at' => now()])->save();

        return $logs;
    }

    private function redact(string $body): string
    {
        return preg_replace(['/(password|Password|VendorPassword|api_key|user-api-key)("?\s*[:=]\s*"?)([^"<&\s]+)/', '/(<wsse:Password[^>]*>)[^<]*/'], ['$1$2***', '$1***'], $body);
    }
}
