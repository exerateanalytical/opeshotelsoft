<?php

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\WebhookSubscription;
use App\Platform\Events\StoredEvent;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class QueueWebhookDeliveries
{
    public static function forEvent(StoredEvent $event): void
    {
        if (! $event->group_id) {
            return;
        }

        $subs = app(TenantContext::class)->withoutScope(fn () => WebhookSubscription::query()
            ->where('group_id', $event->group_id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $event->hotel_id))
            ->get());

        foreach ($subs as $sub) {
            if (! $sub->wants($event->type)) {
                continue;
            }
            $id = DB::table('webhook_deliveries')->insertGetId([
                'webhook_subscription_id' => $sub->id,
                'event_id' => $event->id,
                'status' => 'pending',
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DeliverWebhook::dispatch($id)->afterCommit();
        }
    }
}
