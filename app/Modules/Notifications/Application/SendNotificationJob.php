<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * One delivery attempt for an outbox row. Failures never throw: the row is
 * marked `retrying` with next_attempt_at (exponential backoff) and picked up
 * by `notifications:retry`, or `failed` after max_attempts.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Seconds to wait after attempt n (1-based). */
    public const BACKOFF = [60, 300, 900, 3600, 10800];

    public function __construct(public int $outboxId)
    {
        $this->onQueue('notifications');
    }

    public function handle(ChannelManager $channels): void
    {
        // Runs on a queue worker with no tenant in context.
        $m = app(\App\Platform\Tenancy\TenantContext::class)->withoutScope(fn () => OutboxMessage::find($this->outboxId));
        if (! $m || in_array($m->status, ['sent', 'skipped', 'failed'], true)) {
            return;
        }
        $driver = $channels->driver($m->channel);
        $m->attempts++;
        $m->driver = $driver->name();
        try {
            $m->provider_id = $driver->send($m);
            $m->status = 'sent';
            $m->sent_at = now();
            $m->error = null;
            $m->next_attempt_at = null;
        } catch (Throwable $e) {
            $m->error = mb_substr($e->getMessage(), 0, 2000);
            if ($m->attempts >= $m->max_attempts) {
                $m->status = 'failed';
                $m->next_attempt_at = null;
            } else {
                $m->status = 'retrying';
                $m->next_attempt_at = now()->addSeconds(self::BACKOFF[min($m->attempts, count(self::BACKOFF)) - 1]);
            }
        }
        $m->save();
    }
}
