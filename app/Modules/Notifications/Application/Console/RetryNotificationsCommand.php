<?php

namespace App\Modules\Notifications\Application\Console;

use App\Modules\Notifications\Application\SendNotificationJob;
use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Console\Command;

/** Re-queues outbox rows whose backoff has elapsed. */
class RetryNotificationsCommand extends Command
{
    protected $signature = 'notifications:retry';

    protected $description = 'Retry failed notification deliveries whose backoff has elapsed';

    public function handle(): int
    {
        // Scheduler runs across all groups.
        $ids = app(\App\Platform\Tenancy\TenantContext::class)->withoutScope(fn () => OutboxMessage::where('status', 'retrying')
            ->where('next_attempt_at', '<=', now())->orderBy('id')->limit(500)->pluck('id'));
        $ids->each(fn ($id) => SendNotificationJob::dispatch($id));
        $this->info("Retried {$ids->count()} message(s).");

        return self::SUCCESS;
    }
}
