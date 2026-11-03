<?php

namespace App\Platform\Events;

use App\Modules\Platform\Application\QueueWebhookDeliveries;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional outbox. record() writes the event in the caller's DB
 * transaction; after commit the event is dispatched to in-process
 * subscribers (other bounded contexts) and queued for webhooks.
 *
 * Subscribers must be idempotent: they receive the event id and are
 * tracked in event_consumptions so a replay never applies twice.
 */
class EventBus
{
    /** @var array<string, array<int, callable>> */
    private array $subscribers = [];

    public function __construct(private TenantContext $ctx) {}

    public function subscribe(string $type, string $consumer, callable $handler): void
    {
        $this->subscribers[$type][$consumer] = $handler;
    }

    public function record(string $type, array $payload, ?string $aggregateType = null, int|string|null $aggregateId = null, int $version = 1): StoredEvent
    {
        $event = StoredEvent::create([
            'uuid' => (string) Str::uuid7(),
            'type' => $type,
            'version' => $version,
            'group_id' => $this->ctx->groupId(),
            'hotel_id' => $this->ctx->hotelId(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'actor_id' => $this->ctx->actor()?->id,
            'correlation_id' => request()?->header('X-Correlation-Id') ?? (string) Str::uuid(),
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        DB::afterCommit(fn () => $this->dispatch($event));

        return $event;
    }

    public function dispatch(StoredEvent $event): void
    {
        if ($event->dispatched_at) {
            return;
        }

        foreach ($this->subscribers[$event->type] ?? [] as $consumer => $handler) {
            $this->consume($event, $consumer, $handler);
        }
        foreach ($this->subscribers['*'] ?? [] as $consumer => $handler) {
            $this->consume($event, $consumer, $handler);
        }

        $event->forceFill(['dispatched_at' => now()])->save();

        QueueWebhookDeliveries::forEvent($event);
    }

    private function consume(StoredEvent $event, string $consumer, callable $handler): void
    {
        $already = DB::table('event_consumptions')
            ->where('event_id', $event->id)->where('consumer', $consumer)->exists();
        if ($already) {
            return;
        }

        $previous = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($event->group_id, $event->hotel_id, $event->hotel_id ? [$event->hotel_id] : null, $previous[3]);

        try {
            DB::transaction(function () use ($event, $consumer, $handler) {
                $handler($event);
                DB::table('event_consumptions')->insert([
                    'event_id' => $event->id, 'consumer' => $consumer, 'consumed_at' => now(),
                ]);
            });
        } finally {
            $this->ctx->set(...$previous);
        }
    }

    /** Re-dispatch events that were stored but not dispatched (crash recovery). */
    public function relayPending(int $limit = 500): int
    {
        $pending = StoredEvent::whereNull('dispatched_at')->orderBy('id')->limit($limit)->get();
        $pending->each(fn ($e) => $this->dispatch($e));

        return $pending->count();
    }
}
