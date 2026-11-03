<?php

namespace App\Modules\Platform\Application;

use App\Platform\Events\StoredEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one event to one subscriber. Signed with HMAC-SHA256:
 *   X-Opes-Signature: t=<unix>,v1=<hex(hmac(secret, "<t>.<body>"))>
 * Retries with exponential backoff up to 10 attempts (~1.5 days).
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const MAX_ATTEMPTS = 10;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = DB::table('webhook_deliveries')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }
        $sub = DB::table('webhook_subscriptions')->find($delivery->webhook_subscription_id);
        $event = StoredEvent::find($delivery->event_id);

        $body = json_encode($event->envelope());
        $ts = time();
        $signature = hash_hmac('sha256', $ts.'.'.$body, $sub->secret);
        $attempts = $delivery->attempts + 1;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Opes-Event' => $event->type,
                    'X-Opes-Delivery' => (string) $delivery->id,
                    'X-Opes-Signature' => "t=$ts,v1=$signature",
                ])->withBody($body, 'application/json')->post($sub->url);

            $ok = $response->successful();
            $code = $response->status();
            $error = $ok ? null : substr($response->body(), 0, 500);
        } catch (Throwable $e) {
            $ok = false;
            $code = null;
            $error = $e->getMessage();
        }

        $status = $ok ? 'delivered' : ($attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retrying');
        $next = $ok || $status === 'failed' ? null : now()->addSeconds(30 * (2 ** ($attempts - 1)));

        DB::table('webhook_deliveries')->where('id', $delivery->id)->update([
            'status' => $status, 'attempts' => $attempts, 'response_code' => $code,
            'last_error' => $error, 'next_attempt_at' => $next, 'updated_at' => now(),
        ]);

        if ($next) {
            self::dispatch($delivery->id)->delay($next);
        }
    }
}
